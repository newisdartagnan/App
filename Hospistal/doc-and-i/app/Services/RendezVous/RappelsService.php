<?php

namespace App\Services\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\RendezVous;
use App\Sms\EnvoyeurSms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RDV-06 : rappels par SMS avant chaque rendez-vous CONFIRMÉ, la veille
 * (24 h) puis une heure avant, une seule fois chacun. L'envoi est d'abord
 * « réservé » par une mise à jour conditionnelle (colonne encore vide) : deux
 * passages simultanés du planificateur n'envoient jamais deux fois.
 *
 * NOT-02 : aucun contenu médical dans le SMS (ni médecin, ni spécialité, ni
 * motif) : seulement l'heure.
 */
class RappelsService
{
    private const COLONNE_VEILLE = 'rappel_24h_envoye_le';

    private const COLONNE_DERNIER = 'rappel_1h_envoye_le';

    public function __construct(private readonly EnvoyeurSms $sms) {}

    /** @return int nombre de SMS envoyés */
    public function envoyer(): int
    {
        [$veille, $dernier] = array_map('intval', config('doc-and-i.rendez_vous.rappels_minutes'));
        $maintenant = CarbonImmutable::now();
        $envoyes = 0;

        // Rappel de la veille : début dans (dernier rappel, 24 h]. Plus près,
        // c'est le dernier rappel qui part, seul.
        $envoyes += $this->envoyerFenetre(self::COLONNE_VEILLE, $maintenant->addMinutes($dernier), $maintenant->addMinutes($veille), $maintenant);
        // Dernier rappel : début dans (maintenant, 1 h].
        $envoyes += $this->envoyerFenetre(self::COLONNE_DERNIER, $maintenant, $maintenant->addMinutes($dernier), $maintenant);

        return $envoyes;
    }

    /**
     * Réserve l'envoi d'un rappel : vrai pour un seul appelant, même
     * simultané (UPDATE … WHERE colonne IS NULL).
     */
    public function reserverEnvoi(string $rendezVousId, string $colonne): bool
    {
        return RendezVous::whereKey($rendezVousId)
            ->where('statut', StatutRendezVous::Confirme->value)
            ->whereNull($colonne)
            ->update([$colonne => CarbonImmutable::now()]) === 1;
    }

    private function envoyerFenetre(string $colonne, CarbonImmutable $apres, CarbonImmutable $auPlusTard, CarbonImmutable $maintenant): int
    {
        $candidats = RendezVous::query()
            ->where('statut', StatutRendezVous::Confirme->value)
            ->whereNull($colonne)
            ->where('debut', '>', $apres)
            ->where('debut', '<=', $auPlusTard)
            ->with('patient:id,telephone')
            ->get();

        $envoyes = 0;

        foreach ($candidats as $rdv) {
            if (! $rdv->patient?->telephone || ! $this->reserverEnvoi($rdv->id, $colonne)) {
                continue;
            }

            try {
                $this->sms->envoyer($rdv->patient->telephone, $this->texte($rdv, $colonne, $maintenant));
                $envoyes++;
            } catch (Throwable $e) {
                // Échec chez l'opérateur : on libère la réservation, le prochain passage réessaiera.
                RendezVous::whereKey($rdv->id)->update([$colonne => null]);
                Log::warning('Rappel de rendez-vous non envoyé, nouvel essai au prochain passage.', [
                    'rendez_vous' => $rdv->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }

        return $envoyes;
    }

    private function texte(RendezVous $rdv, string $colonne, CarbonImmutable $maintenant): string
    {
        $debut = CreneauxService::local($rdv->debut);
        $heure = $debut->format('H:i');
        $suite = ' Ouvrez l\'application quelques minutes avant.';

        if ($colonne === self::COLONNE_DERNIER) {
            return "Doc&I : rappel, votre consultation commence a {$heure}.".$suite;
        }

        $jour = match (true) {
            $debut->isSameDay($maintenant) => 'aujourd\'hui',
            $debut->isSameDay($maintenant->addDay()) => 'demain',
            default => 'le '.$debut->format('d/m'),
        };

        return "Doc&I : rappel, votre consultation est {$jour} a {$heure}.".$suite;
    }
}
