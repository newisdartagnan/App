<?php

namespace App\Services\Teleconsultation;

use App\Enums\MotifRemboursement;
use App\Enums\StatutConsultation;
use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiement\RemboursementService;
use App\Sms\EnvoyeurSms;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fin de consultation et absences (RG-06, RG-07). Chaque transition se fait
 * sous verrou, consultation ET rendez-vous dans la même transaction :
 *
 * - terminer : le médecin, consultation en cours → Terminee, rendez-vous Termine ;
 * - patient absent : le médecin, venu, 10 minutes après l'heure, patient
 *   jamais venu → PatientAbsent, rendez-vous AbsencePatient, rien n'est
 *   remboursé (RG-04 : le créneau était réservé pour lui) ;
 * - médecin absent : le patient, venu, 10 minutes après l'heure, médecin
 *   jamais venu → MedecinAbsent, rendez-vous AbsenceMedecin, remboursement
 *   intégral automatique, SMS au patient (sans donnée de santé).
 *
 * Personne n'a rien fait quand la salle ferme (fin + 1 h) : la tâche
 * planifiée constate l'absence (ou la fin) à leur place. Si NI l'un NI
 * l'autre n'est venu, c'est le médecin qui est réputé absent : le patient
 * a payé, et rien ne prouve son absence sans la présence du médecin. Il est
 * remboursé, le médecin n'est pas payé (le reversement exclut ce rendez-vous).
 */
final class FinConsultation
{
    public function __construct(private readonly AccesConsultation $acces) {}

    /** @throws AccesRefuse */
    public function terminer(User $medecin, RendezVous $rdv): Consultation
    {
        $this->exigerRole($medecin, $rdv, AccesConsultation::MEDECIN);

        return DB::transaction(function () use ($rdv) {
            [$rdv, $consultation] = $this->verrouiller($rdv);

            if ($consultation === null || $consultation->statut === StatutConsultation::EnAttente) {
                throw new AccesRefuse('La consultation n\'a pas commencé : elle ne peut pas être terminée.');
            }
            if ($consultation->statut !== StatutConsultation::EnCours) {
                throw new AccesRefuse('Cette consultation est déjà close ('.mb_strtolower($consultation->statut->libelle()).').');
            }

            $this->clore($consultation, $rdv, StatutConsultation::Terminee, StatutRendezVous::Termine);

            return $consultation;
        });
    }

    /** @throws AccesRefuse */
    public function constaterPatientAbsent(User $medecin, RendezVous $rdv): Consultation
    {
        $this->exigerRole($medecin, $rdv, AccesConsultation::MEDECIN);

        return DB::transaction(function () use ($rdv) {
            [$rdv, $consultation] = $this->verrouiller($rdv);
            $this->exigerAbsence($rdv, $consultation, 'medecin_rejoint_le', 'patient_rejoint_le', 'du patient');

            $this->clore($consultation, $rdv, StatutConsultation::PatientAbsent, StatutRendezVous::AbsencePatient);

            return $consultation;
        });
    }

    /** @throws AccesRefuse */
    public function constaterMedecinAbsent(User $patient, RendezVous $rdv): Consultation
    {
        $this->exigerRole($patient, $rdv, AccesConsultation::PATIENT);

        $consultation = DB::transaction(function () use ($rdv) {
            [$rdv, $consultation] = $this->verrouiller($rdv);
            $this->exigerAbsence($rdv, $consultation, 'patient_rejoint_le', 'medecin_rejoint_le', 'du médecin');

            $this->clore($consultation, $rdv, StatutConsultation::MedecinAbsent, StatutRendezVous::AbsenceMedecin);
            $this->rembourserIntegralement($rdv);

            return $consultation;
        });

        $this->prevenirPatient($rdv->fresh());

        return $consultation;
    }

    /**
     * Ce que l'écran de fin propose à CE participant, maintenant.
     *
     * @return array{role: string, statut: ?string, libelle: string, actions: list<string>, absenceDisponibleA: ?string}
     *
     * @throws AccesRefuse
     */
    public function etat(User $user, RendezVous $rdv): array
    {
        $role = $this->acces->role($user, $rdv) ?? throw new AccesRefuse('Cette consultation ne vous concerne pas.');
        $consultation = Consultation::where('rendez_vous_id', $rdv->id)->first();
        $seuil = self::seuilAbsence($rdv);
        $maintenant = CarbonImmutable::now();

        $actions = [];
        if ($consultation?->statut === StatutConsultation::EnCours && $role === AccesConsultation::MEDECIN) {
            $actions[] = 'terminer';
        }
        if ($consultation?->statut === StatutConsultation::EnAttente && $maintenant >= $seuil) {
            $moi = $role === AccesConsultation::MEDECIN ? 'medecin_rejoint_le' : 'patient_rejoint_le';
            $autre = $role === AccesConsultation::MEDECIN ? 'patient_rejoint_le' : 'medecin_rejoint_le';
            if ($consultation->{$moi} !== null && $consultation->{$autre} === null) {
                $actions[] = $role === AccesConsultation::MEDECIN ? 'patient_absent' : 'medecin_absent';
            }
        }

        return [
            'role' => $role,
            'statut' => $consultation?->statut->value,
            'libelle' => $consultation?->statut->libelle() ?? 'Salle pas encore ouverte',
            'actions' => $actions,
            'absenceDisponibleA' => $consultation?->statut === StatutConsultation::EnAttente && $maintenant < $seuil
                ? $seuil->setTimezone('Africa/Kinshasa')->format('H:i') : null,
        ];
    }

    /**
     * Tâche planifiée : salles fermées (fin + délai de fermeture) où personne
     * n'a conclu. Rendez-vous confirmé sans salle (personne n'est venu) ou salle
     * en attente → absence constatée ; salle en cours → terminée.
     *
     * @return array{terminees: int, patient_absent: int, medecin_absent: int}
     */
    public function constaterLesSallesFermees(): array
    {
        $compte = ['terminees' => 0, 'patient_absent' => 0, 'medecin_absent' => 0];
        $fermeture = CarbonImmutable::now()->subMinutes((int) config('doc-and-i.teleconsultation.fermeture_minutes_apres'));

        RendezVous::where('statut', StatutRendezVous::Confirme->value)->where('fin', '<', $fermeture)
            ->orderBy('fin')->limit(200)->pluck('id')
            ->each(function (string $id) use (&$compte) {
                $resultat = $this->constaterSalleFermee($id);
                if ($resultat !== null) {
                    $compte[$resultat]++;
                }
            });

        return $compte;
    }

    private function constaterSalleFermee(string $rdvId): ?string
    {
        $resultat = DB::transaction(function () use ($rdvId) {
            $rdv = RendezVous::whereKey($rdvId)->lockForUpdate()->firstOrFail();
            if ($rdv->statut !== StatutRendezVous::Confirme) {
                return null; // Un participant a conclu entre-temps.
            }

            $consultation = Consultation::where('rendez_vous_id', $rdv->id)->lockForUpdate()->first();
            if ($consultation === null) {
                // Personne n'est venu : la salle est créée pour garder la trace.
                $consultation = new Consultation;
                $consultation->forceFill(['id' => (string) Str::uuid(), 'rendez_vous_id' => $rdv->id, 'statut' => StatutConsultation::EnAttente])->save();
            }

            if ($consultation->statut === StatutConsultation::EnCours) {
                $this->clore($consultation, $rdv, StatutConsultation::Terminee, StatutRendezVous::Termine);

                return 'terminees';
            }

            if ($consultation->medecin_rejoint_le !== null && $consultation->patient_rejoint_le === null) {
                $this->clore($consultation, $rdv, StatutConsultation::PatientAbsent, StatutRendezVous::AbsencePatient);

                return 'patient_absent';
            }

            // Médecin jamais venu (patient venu, ou personne) : RG-06.
            $this->clore($consultation, $rdv, StatutConsultation::MedecinAbsent, StatutRendezVous::AbsenceMedecin);
            $this->rembourserIntegralement($rdv);

            return 'medecin_absent';
        });

        if ($resultat === 'medecin_absent') {
            $this->prevenirPatient(RendezVous::findOrFail($rdvId));
        }

        return $resultat;
    }

    /** Verrous dans l'ordre : rendez-vous, puis sa salle. @return array{0: RendezVous, 1: ?Consultation} */
    private function verrouiller(RendezVous $rdv): array
    {
        $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();

        return [$rdv, Consultation::where('rendez_vous_id', $rdv->id)->lockForUpdate()->first()];
    }

    /** @throws AccesRefuse */
    private function exigerAbsence(RendezVous $rdv, ?Consultation $consultation, string $present, string $absent, string $qui): void
    {
        if ($consultation === null || $consultation->{$present} === null) {
            throw new AccesRefuse('Entrez d\'abord dans la salle : l\'absence se constate depuis la consultation.');
        }

        if ($consultation->statut !== StatutConsultation::EnAttente || $consultation->{$absent} !== null) {
            throw new AccesRefuse('Absence '.$qui.' impossible : '.($consultation->statut->estOuverte()
                ? 'il ou elle est venu(e) à la consultation.'
                : 'la consultation est déjà close ('.mb_strtolower($consultation->statut->libelle()).').'));
        }

        if (CarbonImmutable::now() < self::seuilAbsence($rdv)) {
            throw new AccesRefuse('L\'absence se constate '.config('doc-and-i.rendez_vous.retard_absence_minutes').' minutes après l\'heure du rendez-vous, à partir de '
                .self::seuilAbsence($rdv)->setTimezone('Africa/Kinshasa')->format('H:i').'.');
        }
    }

    private function clore(Consultation $consultation, RendezVous $rdv, StatutConsultation $statut, StatutRendezVous $statutRdv): void
    {
        $consultation->transitionVers($statut);
        $consultation->fin_le = CarbonImmutable::now();
        $consultation->save();

        $rdv->transitionVers($statutRdv);
        $rdv->save();
    }

    /** RG-06 : tout ce qui reste remboursable, demandé dans la transaction (automatique : né validé). */
    private function rembourserIntegralement(RendezVous $rdv): void
    {
        $paiement = Paiement::where('rendez_vous_id', $rdv->id)->where('statut', StatutPaiement::Reussi->value)
            ->orderBy('confirme_le')->orderBy('id')->lockForUpdate()->first();

        if ($paiement === null || $paiement->resteRemboursableEnCentimes() <= 0) {
            return;
        }

        app(RemboursementService::class)->demander($paiement, Montant::depuisCentimes($paiement->resteRemboursableEnCentimes()), MotifRemboursement::AbsenceMedecin);
    }

    /** Après validation : un SMS sans donnée de santé ; une panne SMS ne défait rien (journal sans numéro). */
    private function prevenirPatient(RendezVous $rdv): void
    {
        $debut = $rdv->debut->setTimezone('Africa/Kinshasa');

        try {
            app(EnvoyeurSms::class)->envoyer($rdv->patient->telephone, sprintf(
                'MEDIHOME : le medecin n\'a pas pu vous recevoir le %s a %s. Vous serez rembourse integralement ; ouvrez l\'application pour reprendre rendez-vous.',
                $debut->format('d/m'), $debut->format('H:i')));
        } catch (Throwable $erreur) {
            Log::warning('SMS « médecin absent » non envoyé', ['rendez_vous' => $rdv->id, 'erreur' => $erreur::class]);
        }
    }

    /** @throws AccesRefuse */
    private function exigerRole(User $user, RendezVous $rdv, string $role): void
    {
        if ($this->acces->role($user, $rdv) !== $role) {
            throw new AccesRefuse('Cette action ne vous est pas ouverte pour cette consultation.');
        }
    }

    private static function seuilAbsence(RendezVous $rdv): CarbonImmutable
    {
        return $rdv->debut->addMinutes((int) config('doc-and-i.rendez_vous.retard_absence_minutes'));
    }
}
