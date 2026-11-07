<?php

namespace App\Services\Teleconsultation;

use App\Enums\StatutConsultation;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\RendezVous\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entrée dans la salle de téléconsultation (TLC-01). Seuls le patient du
 * rendez-vous et son médecin y entrent (relation de soin, vérifiée ici pour
 * chaque requête : salle, signaux, messages) ; seulement pour un rendez-vous
 * confirmé, dans la fenêtre horaire.
 */
final class AccesConsultation
{
    public const PATIENT = 'patient';

    public const MEDECIN = 'medecin';

    /** Rôle de $user dans CE rendez-vous, ou null : ni son patient, ni son médecin. */
    public function role(User $user, RendezVous $rdv): ?string
    {
        if ($rdv->patient_id === $user->id && $user->hasRole('patient')) {
            return self::PATIENT;
        }

        if ($user->hasRole('medecin') && ! $user->estDesactive()
            && DB::table('medecins')->where('id', $rdv->medecin_id)->where('user_id', $user->id)->exists()) {
            return self::MEDECIN;
        }

        return null;
    }

    public static function autreRole(string $role): string
    {
        return $role === self::PATIENT ? self::MEDECIN : self::PATIENT;
    }

    /** La salle est-elle ouverte à cette heure pour ce rendez-vous ? */
    public function dansLaFenetre(RendezVous $rdv, ?CarbonImmutable $maintenant = null): bool
    {
        $maintenant ??= CarbonImmutable::now();
        $config = config('doc-and-i.teleconsultation');

        return $maintenant >= $rdv->debut->subMinutes($config['ouverture_minutes_avant'])
            && $maintenant <= $rdv->fin->addMinutes($config['fermeture_minutes_apres']);
    }

    /**
     * Entre dans la salle : la crée au premier passage, note la première
     * arrivée du participant (il est « venu »). Elle ne passe pas « en
     * cours » ici : seule la connexion réelle des deux navigateurs le prouve
     * (Presence::battement). Idempotent : revenir après une coupure (TLC-04)
     * rouvre la même salle et compte la reconnexion.
     *
     * @return array{0: Consultation, 1: string} la salle et le rôle de $user
     *
     * @throws AccesRefuse
     */
    public function entrer(User $user, RendezVous $rdv): array
    {
        $role = $this->role($user, $rdv) ?? throw new AccesRefuse('Cette consultation ne vous concerne pas.');

        return DB::transaction(function () use ($rdv, $role) {
            $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();
            $consultation = $this->salleSousVerrou($rdv);

            // Annulé ou reporté hors de la fenêtre après l'ouverture de la salle : plus d'entrée.
            if ($consultation !== null && $rdv->statut !== StatutRendezVous::Confirme) {
                throw new AccesRefuse($this->raisonFermee($rdv));
            }

            if ($consultation === null || ! $consultation->statut->estOuverte()) {
                throw new AccesRefuse($consultation === null
                    ? $this->raisonFermee($rdv)
                    : 'Cette consultation est close ('.mb_strtolower($consultation->statut->libelle()).').');
            }

            if (! $this->dansLaFenetre($rdv)) {
                throw new AccesRefuse($this->raisonFermee($rdv));
            }

            $maintenant = CarbonImmutable::now();
            $colonne = $role === self::PATIENT ? 'patient_rejoint_le' : 'medecin_rejoint_le';
            if ($consultation->{$colonne} === null) {
                $consultation->{$colonne} = $maintenant;
            } elseif ($consultation->statut === StatutConsultation::EnCours) {
                $consultation->reconnexions = $consultation->reconnexions + 1;
            }

            // La salle ne passe « en cours » que par Presence::battement() : les deux
            // navigateurs connectés ensemble. Ouvrir la page ne prouve rien (revue de sécurité).

            $consultation->save();

            return [$consultation, $role];
        });
    }

    /** Salle du rendez-vous, créée si le rendez-vous est confirmé ; null sinon. À appeler sous verrou du rendez-vous. */
    private function salleSousVerrou(RendezVous $rdv): ?Consultation
    {
        $consultation = Consultation::where('rendez_vous_id', $rdv->id)->lockForUpdate()->first();
        if ($consultation !== null || $rdv->statut !== StatutRendezVous::Confirme) {
            return $consultation;
        }

        $consultation = new Consultation;
        $consultation->forceFill([
            'id' => (string) Str::uuid(),
            'rendez_vous_id' => $rdv->id,
            'statut' => StatutConsultation::EnAttente,
        ])->save();

        return $consultation;
    }

    private function raisonFermee(RendezVous $rdv): string
    {
        if ($rdv->statut !== StatutRendezVous::Confirme) {
            return 'Ce rendez-vous n\'est pas confirmé ('.mb_strtolower($rdv->statut->libelle()).') : pas de consultation.';
        }

        $ouverture = $rdv->debut->subMinutes(config('doc-and-i.teleconsultation.ouverture_minutes_avant'));

        return CarbonImmutable::now() < $ouverture
            ? 'La salle ouvre '.config('doc-and-i.teleconsultation.ouverture_minutes_avant').' minutes avant le rendez-vous, le '.Affichage::dateHeure($ouverture).'.'
            : 'L\'heure de cette consultation est passée.';
    }
}
