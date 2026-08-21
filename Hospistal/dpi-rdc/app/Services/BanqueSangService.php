<?php

namespace App\Services;

use App\Models\DemandeSang;
use App\Models\DonneurSang;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\Transfusion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Banque de sang : le stock, les donneurs, et la délivrance.
 *
 * Toute la sécurité tient en trois refus : une poche non dépistée ne sort
 * pas, une poche positive ne sort pas, une poche incompatible ne sort pas.
 * Ils sont écrits ici et nulle part ailleurs.
 */
class BanqueSangService
{
    /**
     * Enregistre un don : le donneur, puis la poche prélevée.
     *
     * La poche naît en quarantaine. Elle n'en sortira qu'une fois les cinq
     * marqueurs rendus négatifs.
     */
    public function enregistrerDon(DonneurSang $donneur, array $donnees): PocheSang
    {
        return DB::transaction(function () use ($donneur, $donnees) {
            $prelevement = filled($donnees['date_prelevement'] ?? null)
                ? Carbon::parse($donnees['date_prelevement'])
                : now();

            $produit = $donnees['type_produit'] ?? 'sang_total';

            $poche = PocheSang::create([
                'establishment_id' => $donneur->establishment_id,
                'donneur_id' => $donneur->id,
                'numero' => $donnees['numero'] ?? $this->genererNumeroPoche($donneur->establishment_id),
                // Le groupe de la poche est celui du donneur : on ne le
                // ressaisit pas, c'est la première source d'erreur.
                'groupe_sanguin' => $donneur->groupe_sanguin,
                'type_produit' => $produit,
                'volume_ml' => $donnees['volume_ml'] ?? 450,
                'date_prelevement' => $prelevement,
                'date_peremption' => $prelevement->copy()
                    ->addDays(PocheSang::CONSERVATION_JOURS[$produit] ?? 35),
                'statut' => 'quarantaine',
                'emplacement' => $donnees['emplacement'] ?? null,
                'notes' => $donnees['notes'] ?? null,
            ]);

            $donneur->update([
                'dernier_don' => $prelevement->toDateString(),
                'nombre_dons' => $donneur->nombre_dons + 1,
            ]);

            return $poche;
        });
    }

    /**
     * Enregistre le dépistage d'une poche.
     *
     * Négatif partout : la poche passe en rayon. Un seul marqueur positif :
     * elle est détruite, et le donneur écarté — il doit être orienté vers un
     * soignant, ce n'est pas une simple ligne de stock.
     */
    public function enregistrerDepistage(PocheSang $poche, array $resultats): PocheSang
    {
        return DB::transaction(function () use ($poche, $resultats) {
            $poche->update([
                'depistage_vih' => (bool) ($resultats['depistage_vih'] ?? false),
                'depistage_hepatite_b' => (bool) ($resultats['depistage_hepatite_b'] ?? false),
                'depistage_hepatite_c' => (bool) ($resultats['depistage_hepatite_c'] ?? false),
                'depistage_syphilis' => (bool) ($resultats['depistage_syphilis'] ?? false),
                'depistage_paludisme' => (bool) ($resultats['depistage_paludisme'] ?? false),
                'date_depistage' => now()->toDateString(),
                'depiste_par' => auth()->id(),
            ]);

            $poche->refresh();
            $positifs = $poche->marqueursPositifs();

            if ($positifs !== []) {
                $poche->update([
                    'statut' => 'detruite',
                    'notes' => trim(($poche->notes ?? '').' Dépistage positif : '.implode(', ', $positifs).'.'),
                ]);

                $poche->donneur?->update([
                    'est_eligible' => false,
                    'motif_exclusion' => 'Dépistage positif ('.implode(', ', $positifs).')',
                ]);

                return $poche->fresh();
            }

            $poche->update(['statut' => 'disponible']);

            return $poche->fresh();
        });
    }

    /**
     * Poches que la banque peut proposer pour une demande, les plus proches
     * de la péremption d'abord.
     */
    public function pochesPour(DemandeSang $demande)
    {
        return PocheSang::with('donneur')
            ->where('establishment_id', $demande->establishment_id)
            ->compatiblesAvec($demande->groupeReceveur(), $demande->type_produit)
            ->get();
    }

    /**
     * Délivre une poche et ouvre la feuille de transfusion.
     *
     * @return array{transfusion: ?Transfusion, erreur: ?string}
     */
    public function delivrer(DemandeSang $demande, PocheSang $poche, array $donnees = []): array
    {
        if (! $demande->estOuverte()) {
            return ['transfusion' => null, 'erreur' => 'Cette demande n\'est plus ouverte.'];
        }

        if ($motif = $poche->motifIndisponibilite()) {
            return ['transfusion' => null, 'erreur' => $motif];
        }

        $groupeReceveur = $demande->groupeReceveur();

        if (! $poche->estCompatibleAvec($groupeReceveur)) {
            return ['transfusion' => null, 'erreur' => sprintf(
                'Poche %s incompatible avec un receveur %s. Groupes acceptés : %s.',
                $poche->groupe_sanguin,
                $groupeReceveur ?: 'de groupe inconnu',
                implode(', ', PocheSang::groupesCompatiblesPour($groupeReceveur, $poche->type_produit))
            )];
        }

        $transfusion = DB::transaction(function () use ($demande, $poche, $donnees, $groupeReceveur) {
            $transfusion = Transfusion::create([
                'visit_id' => $demande->visit_id,
                'patient_id' => $demande->patient_id,
                'demande_id' => $demande->id,
                'poche_id' => $poche->id,
                'user_id' => auth()->id(),
                'produit' => PocheSang::PRODUIT_TRANSFUSION[$poche->type_produit] ?? 'sang_total',
                'groupe_donneur' => $poche->groupe_sanguin,
                'groupe_receveur' => $groupeReceveur,
                'numero_poche' => $poche->numero,
                'quantite' => $poche->volume_ml,
                'jour' => now()->toDateString(),
                'heure_debut' => $donnees['heure_debut'] ?? now()->format('H:i'),
                'controle_ultime' => (bool) ($donnees['controle_ultime'] ?? false),
                'hemoglobine_avant' => $donnees['hemoglobine_avant'] ?? $demande->hemoglobine,
                'incident' => 'aucun',
            ]);

            $poche->update(['statut' => 'transfusee']);

            // La demande se solde d'elle-même quand toutes ses poches sont
            // parties : le service n'a pas à venir la fermer à la main.
            $demande->update([
                'statut' => $demande->fresh()->pochesRestantes() <= 0 ? 'servie' : 'partiellement_servie',
            ]);

            return $transfusion;
        });

        return ['transfusion' => $transfusion, 'erreur' => null];
    }

    /**
     * Passe en « périmée » tout ce qui a dépassé sa date.
     *
     * Une poche périmée qui reste marquée disponible finit par être posée :
     * le ménage se fait tous les jours, pas au moment de servir.
     */
    public function retirerPochesPerimees(): int
    {
        return PocheSang::whereIn('statut', ['quarantaine', 'disponible', 'reservee'])
            ->whereDate('date_peremption', '<', now()->toDateString())
            ->update(['statut' => 'perimee']);
    }

    /**
     * État du stock, groupe par groupe, et donneurs joignables.
     *
     * @return array<string, mixed>
     */
    public function etatDuStock(?string $etablissementId = null): array
    {
        $poches = PocheSang::query()
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        $delivrables = $poches->filter->estDelivrable();

        $donneurs = DonneurSang::query()
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        return [
            'total' => $poches->count(),
            'delivrables' => $delivrables->count(),
            'quarantaine' => $poches->where('statut', 'quarantaine')->count(),
            'perime_bientot' => $delivrables->filter(fn ($p) => $p->joursAvantPeremption() <= 7)->count(),
            'par_groupe' => collect(PocheSang::GROUPES)
                ->mapWithKeys(fn ($groupe) => [
                    $groupe => $delivrables->where('groupe_sanguin', $groupe)->count(),
                ]),
            'par_produit' => $delivrables->groupBy(fn ($p) => $p->libelleProduit())->map->count()->sortDesc(),
            'donneurs_joignables' => collect(PocheSang::GROUPES)
                ->mapWithKeys(fn ($groupe) => [
                    $groupe => $donneurs->where('groupe_sanguin', $groupe)
                        ->filter->peutDonnerMaintenant()->count(),
                ]),
        ];
    }

    /**
     * Donneurs à appeler pour un receveur donné : compatibles, éligibles,
     * délai écoulé. C'est la réponse à « où trouver du sang, cette nuit ».
     */
    public function donneursAAppeler(?string $groupeReceveur, ?string $etablissementId = null)
    {
        return DonneurSang::query()
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->compatiblesAvec($groupeReceveur)
            ->get()
            ->filter->peutDonnerMaintenant()
            // Le donneur le plus reposé en premier : celui qui n'a jamais
            // donné, puis le plus ancien don.
            ->sortBy(fn (DonneurSang $donneur) => $donneur->dernier_don?->timestamp ?? 0)
            ->values();
    }

    /** Numéro de poche : PS-2026-000001. */
    public function genererNumeroPoche(string $etablissementId): string
    {
        $prefixe = 'PS-'.now()->year.'-';

        return DB::transaction(function () use ($prefixe, $etablissementId) {
            $dernier = PocheSang::where('establishment_id', $etablissementId)
                ->where('numero', 'like', $prefixe.'%')
                ->orderByDesc('numero')
                ->lockForUpdate()
                ->value('numero');

            $sequence = $dernier && preg_match('/-(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

            return $prefixe.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }

    /** Numéro de demande : DS-2026-000001. */
    public function genererNumeroDemande(string $etablissementId): string
    {
        $prefixe = 'DS-'.now()->year.'-';

        return DB::transaction(function () use ($prefixe, $etablissementId) {
            $dernier = DemandeSang::where('establishment_id', $etablissementId)
                ->where('numero', 'like', $prefixe.'%')
                ->orderByDesc('numero')
                ->lockForUpdate()
                ->value('numero');

            $sequence = $dernier && preg_match('/-(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

            return $prefixe.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }

    /** Code donneur : DON-000001. */
    public function genererCodeDonneur(string $etablissementId): string
    {
        $dernier = DonneurSang::where('establishment_id', $etablissementId)
            ->where('code', 'like', 'DON-%')
            ->orderByDesc('code')
            ->value('code');

        $sequence = $dernier && preg_match('/-(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

        return 'DON-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** Ouvre une demande de sang pour un patient. */
    public function creerDemande(Patient $patient, array $donnees): DemandeSang
    {
        return DemandeSang::create([
            'establishment_id' => $patient->establishment_id,
            'patient_id' => $patient->id,
            'visit_id' => $donnees['visit_id'] ?? null,
            'demandeur_id' => auth()->id(),
            'numero' => $this->genererNumeroDemande($patient->establishment_id),
            'groupe_demande' => ($donnees['groupe_demande'] ?? null) ?: $patient->groupe_sanguin,
            'type_produit' => $donnees['type_produit'] ?? 'sang_total',
            'nombre_poches' => $donnees['nombre_poches'] ?? 1,
            'urgence' => (bool) ($donnees['urgence'] ?? false),
            'indication' => $donnees['indication'] ?? null,
            'hemoglobine' => $donnees['hemoglobine'] ?? null,
            'statut' => 'en_attente',
        ]);
    }
}
