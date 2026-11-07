<?php

namespace App\Services\Teleconsultation;

use App\Enums\MotifRemboursement;
use App\Enums\StatutConsultation;
use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\DemandeConsultation;
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
 * Fin de consultation et absences (RG-06, RG-07), sur la PRÉSENCE RÉELLE
 * dans l'appel (revue de sécurité) : ouvrir la page dit seulement « venu » ;
 * « en cours », ensemble_le et secondes_ensemble viennent des battements des
 * deux navigateurs connectés (Presence::battement). Chaque transition se
 * fait sous verrou, rendez-vous puis consultation, dans une transaction.
 *
 * - terminer (médecin) : consultation en cours ET au moins
 *   duree_minimale_secondes passées ensemble → Terminee, rendez-vous Termine ;
 * - interrompre (médecin, RG-07) : consultation en cours → Interrompue ; le
 *   rendez-vous passe « annulé par le médecin » (motif fixe), remboursement
 *   intégral automatique, demande rouverte pour reprendre un créneau, SMS ;
 * - absences : jamais connectés ensemble (ensemble_le nul), 10 minutes après
 *   l'heure, et celui qui constate est venu À L'HEURE (au plus tard à ce
 *   seuil). Le médecin ne constate l'absence du patient que si le patient
 *   n'est pas venu à l'heure ; le patient constate l'absence du médecin
 *   (remboursé intégralement) même si le médecin a ouvert la page sans
 *   jamais être connecté avec lui.
 *
 * Tâche planifiée, à la fermeture de la salle (fin + délai) :
 * - en cours, assez de temps ensemble → terminée ; sinon → interrompue ;
 * - jamais ensemble : médecin venu à l'heure et patient non → patient
 *   absent ; dans tous les autres cas (médecin en retard ou jamais venu,
 *   personne, ou deux présents jamais connectés) → médecin absent : le
 *   patient est remboursé, le médecin n'est pas payé.
 */
final class FinConsultation
{
    public const MOTIF_INTERRUPTION = 'Consultation interrompue (RG-07)';

    public function __construct(private readonly AccesConsultation $acces) {}

    /** @throws AccesRefuse */
    public function terminer(User $medecin, RendezVous $rdv): Consultation
    {
        $this->exigerRole($medecin, $rdv, AccesConsultation::MEDECIN);

        return DB::transaction(function () use ($rdv) {
            [$rdv, $consultation] = $this->verrouiller($rdv);
            $this->exigerEnCours($consultation);

            $manque = self::secondesManquantes($consultation);
            if ($manque > 0) {
                throw new AccesRefuse(sprintf(
                    'Encore %s d\'échange avant de pouvoir terminer. Si l\'appel est coupé pour de bon, déclarez la consultation interrompue : le patient sera remboursé.',
                    self::duree($manque)));
            }

            $this->clore($consultation, $rdv, StatutConsultation::Terminee, StatutRendezVous::Termine);

            return $consultation;
        });
    }

    /** RG-07 : coupure définitive ou échange trop court ; le patient est remboursé et reprend un créneau. @throws AccesRefuse */
    public function interrompre(User $medecin, RendezVous $rdv): Consultation
    {
        $this->exigerRole($medecin, $rdv, AccesConsultation::MEDECIN);

        $consultation = DB::transaction(function () use ($rdv) {
            [$rdv, $consultation] = $this->verrouiller($rdv);
            $this->exigerEnCours($consultation);
            $this->interrompreSousVerrou($consultation, $rdv);

            return $consultation;
        });

        $this->prevenirPatient($rdv->fresh(), 'interrompue');

        return $consultation;
    }

    /** @throws AccesRefuse */
    public function constaterPatientAbsent(User $medecin, RendezVous $rdv): Consultation
    {
        $this->exigerRole($medecin, $rdv, AccesConsultation::MEDECIN);

        return DB::transaction(function () use ($rdv) {
            [$rdv, $consultation] = $this->verrouiller($rdv);
            $this->exigerAbsence($rdv, $consultation, 'medecin_rejoint_le');

            if (self::aLHeure($rdv, $consultation->patient_rejoint_le)) {
                throw new AccesRefuse('Le patient est venu à l\'heure : son absence ne peut pas être constatée.');
            }

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
            $this->exigerAbsence($rdv, $consultation, 'patient_rejoint_le');

            $this->clore($consultation, $rdv, StatutConsultation::MedecinAbsent, StatutRendezVous::AbsenceMedecin);
            $this->rembourserIntegralement($rdv, MotifRemboursement::AbsenceMedecin);

            return $consultation;
        });

        $this->prevenirPatient($rdv->fresh(), 'medecin_absent');

        return $consultation;
    }

    /**
     * Ce que l'écran de fin propose à CE participant, maintenant.
     *
     * @return array{role: string, statut: ?string, libelle: string, actions: list<string>, absenceDisponibleA: ?string, terminerDansSecondes: ?int}
     *
     * @throws AccesRefuse
     */
    public function etat(User $user, RendezVous $rdv): array
    {
        $role = $this->acces->role($user, $rdv) ?? throw new AccesRefuse('Cette consultation ne vous concerne pas.');
        $consultation = Consultation::where('rendez_vous_id', $rdv->id)->first();
        $seuil = self::seuilAbsence($rdv);
        $maintenant = CarbonImmutable::now();
        $estMedecin = $role === AccesConsultation::MEDECIN;

        $actions = [];
        $terminerDans = null;
        if ($consultation?->statut === StatutConsultation::EnCours && $estMedecin) {
            $terminerDans = self::secondesManquantes($consultation);
            if ($terminerDans === 0) {
                $actions[] = 'terminer';
            }
            $actions[] = 'interrompre';
        }

        $enAttenteJamaisEnsemble = $consultation?->statut === StatutConsultation::EnAttente && $consultation->ensemble_le === null;
        if ($enAttenteJamaisEnsemble && $maintenant >= $seuil) {
            if ($estMedecin && self::aLHeure($rdv, $consultation->medecin_rejoint_le) && ! self::aLHeure($rdv, $consultation->patient_rejoint_le)) {
                $actions[] = 'patient_absent';
            }
            if (! $estMedecin && self::aLHeure($rdv, $consultation->patient_rejoint_le)) {
                $actions[] = 'medecin_absent';
            }
        }

        return [
            'role' => $role,
            'statut' => $consultation?->statut->value,
            'libelle' => $consultation?->statut->libelle() ?? 'Salle pas encore ouverte',
            'actions' => $actions,
            'absenceDisponibleA' => $enAttenteJamaisEnsemble && $maintenant < $seuil ? $seuil->setTimezone('Africa/Kinshasa')->format('H:i') : null,
            'terminerDansSecondes' => $terminerDans ?: null,
        ];
    }

    /**
     * Tâche planifiée : salles fermées (fin + délai de fermeture) où personne
     * n'a conclu.
     *
     * @return array{terminees: int, interrompues: int, patient_absent: int, medecin_absent: int}
     */
    public function constaterLesSallesFermees(): array
    {
        $compte = ['terminees' => 0, 'interrompues' => 0, 'patient_absent' => 0, 'medecin_absent' => 0];
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
                if (self::secondesManquantes($consultation) === 0) {
                    $this->clore($consultation, $rdv, StatutConsultation::Terminee, StatutRendezVous::Termine);

                    return 'terminees';
                }

                $this->interrompreSousVerrou($consultation, $rdv);

                return 'interrompues';
            }

            if (self::aLHeure($rdv, $consultation->medecin_rejoint_le) && ! self::aLHeure($rdv, $consultation->patient_rejoint_le)) {
                $this->clore($consultation, $rdv, StatutConsultation::PatientAbsent, StatutRendezVous::AbsencePatient);

                return 'patient_absent';
            }

            // Médecin en retard ou jamais venu, personne, ou deux présents jamais connectés (RG-06).
            $this->clore($consultation, $rdv, StatutConsultation::MedecinAbsent, StatutRendezVous::AbsenceMedecin);
            $this->rembourserIntegralement($rdv, MotifRemboursement::AbsenceMedecin);

            return 'medecin_absent';
        });

        if (in_array($resultat, ['medecin_absent', 'interrompues'], true)) {
            $this->prevenirPatient(RendezVous::findOrFail($rdvId), $resultat === 'interrompues' ? 'interrompue' : 'medecin_absent');
        }

        return $resultat;
    }

    /** Sous verrou : Interrompue, rendez-vous annulé par le médecin (motif fixe), remboursement, demande rouverte. */
    private function interrompreSousVerrou(Consultation $consultation, RendezVous $rdv): void
    {
        $consultation->transitionVers(StatutConsultation::Interrompue);
        $consultation->fin_le = CarbonImmutable::now();
        $consultation->save();

        $rdv->transitionVers(StatutRendezVous::AnnuleParMedecin);
        $rdv->forceFill([
            'annule_le' => CarbonImmutable::now(),
            'motif_annulation' => self::MOTIF_INTERRUPTION,
            'taux_remboursement' => 100,
        ])->save();

        $this->rembourserIntegralement($rdv, MotifRemboursement::ConsultationInterrompue);

        // Le patient choisit un autre créneau pour la même demande.
        DemandeConsultation::whereKey($rdv->demande_id)->where('statut', 'reservee')->update(['statut' => 'ouverte']);
    }

    /** Verrous dans l'ordre : rendez-vous, puis sa salle. @return array{0: RendezVous, 1: ?Consultation} */
    private function verrouiller(RendezVous $rdv): array
    {
        $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();

        return [$rdv, Consultation::where('rendez_vous_id', $rdv->id)->lockForUpdate()->first()];
    }

    /** @throws AccesRefuse */
    private function exigerEnCours(?Consultation $consultation): void
    {
        if ($consultation === null || $consultation->statut === StatutConsultation::EnAttente) {
            throw new AccesRefuse('La consultation n\'a pas commencé : vous n\'avez pas encore été connectés ensemble.');
        }

        if ($consultation->statut !== StatutConsultation::EnCours) {
            throw new AccesRefuse('Cette consultation est déjà close ('.mb_strtolower($consultation->statut->libelle()).').');
        }
    }

    /** @throws AccesRefuse */
    private function exigerAbsence(RendezVous $rdv, ?Consultation $consultation, string $present): void
    {
        if ($consultation === null || $consultation->{$present} === null) {
            throw new AccesRefuse('Entrez d\'abord dans la salle : l\'absence se constate depuis la consultation.');
        }

        if ($consultation->statut !== StatutConsultation::EnAttente || $consultation->ensemble_le !== null) {
            throw new AccesRefuse($consultation->statut->estOuverte()
                ? 'Vous avez été connectés ensemble : ce n\'est pas une absence.'
                : 'La consultation est déjà close ('.mb_strtolower($consultation->statut->libelle()).').');
        }

        if (CarbonImmutable::now() < self::seuilAbsence($rdv)) {
            throw new AccesRefuse('L\'absence se constate '.config('doc-and-i.rendez_vous.retard_absence_minutes').' minutes après l\'heure du rendez-vous, à partir de '
                .self::seuilAbsence($rdv)->setTimezone('Africa/Kinshasa')->format('H:i').'.');
        }

        if (! self::aLHeure($rdv, $consultation->{$present})) {
            throw new AccesRefuse('Vous êtes arrivé après le délai de '.config('doc-and-i.rendez_vous.retard_absence_minutes').' minutes : l\'absence de l\'autre participant ne peut pas être constatée.');
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

    /**
     * Remboursement intégral, automatique (né validé) : TOUS les paiements
     * réussis du rendez-vous (un paiement tardif ou en double compris), chacun
     * dans la limite de son reste remboursable. Dans la transaction.
     */
    private function rembourserIntegralement(RendezVous $rdv, MotifRemboursement $motif): void
    {
        $paiements = Paiement::where('rendez_vous_id', $rdv->id)->where('statut', StatutPaiement::Reussi->value)
            ->orderBy('confirme_le')->orderBy('id')->lockForUpdate()->get();

        foreach ($paiements as $paiement) {
            if ($paiement->resteRemboursableEnCentimes() > 0) {
                app(RemboursementService::class)->demander($paiement, Montant::depuisCentimes($paiement->resteRemboursableEnCentimes()), $motif);
            }
        }
    }

    /** Après validation : un SMS sans donnée de santé ; une panne SMS ne défait rien (journal sans numéro). */
    private function prevenirPatient(RendezVous $rdv, string $cas): void
    {
        $debut = $rdv->debut->setTimezone('Africa/Kinshasa');
        $texte = $cas === 'interrompue'
            ? 'MEDIHOME : votre consultation du %s a %s a ete interrompue. Vous serez rembourse integralement ; ouvrez l\'application pour reprendre rendez-vous.'
            : 'MEDIHOME : le medecin n\'a pas pu vous recevoir le %s a %s. Vous serez rembourse integralement ; ouvrez l\'application pour reprendre rendez-vous.';

        try {
            app(EnvoyeurSms::class)->envoyer($rdv->patient->telephone, sprintf($texte, $debut->format('d/m'), $debut->format('H:i')));
        } catch (Throwable $erreur) {
            Log::warning('SMS de fin de consultation non envoyé', ['rendez_vous' => $rdv->id, 'erreur' => $erreur::class]);
        }
    }

    /** @throws AccesRefuse */
    private function exigerRole(User $user, RendezVous $rdv, string $role): void
    {
        if ($this->acces->role($user, $rdv) !== $role) {
            throw new AccesRefuse('Cette action ne vous est pas ouverte pour cette consultation.');
        }
    }

    private static function secondesManquantes(Consultation $consultation): int
    {
        return max(0, (int) config('doc-and-i.teleconsultation.duree_minimale_secondes') - (int) $consultation->secondes_ensemble);
    }

    /** Venu au plus tard au seuil d'absence (heure + 10 minutes). */
    private static function aLHeure(RendezVous $rdv, ?CarbonImmutable $arrivee): bool
    {
        return $arrivee !== null && $arrivee <= self::seuilAbsence($rdv);
    }

    private static function seuilAbsence(RendezVous $rdv): CarbonImmutable
    {
        return $rdv->debut->addMinutes((int) config('doc-and-i.rendez_vous.retard_absence_minutes'));
    }

    private static function duree(int $secondes): string
    {
        return $secondes >= 60 ? intdiv($secondes + 59, 60).' minute(s)' : $secondes.' seconde(s)';
    }
}
