<?php

namespace App\Services;

use App\Models\Establishment;
use App\Models\Parametre;
use App\Models\TauxChange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Réglages de l'établissement, modifiables depuis l'application.
 *
 * Les valeurs vivent en base et non plus dans un fichier de configuration :
 * une hausse du dollar se saisit au guichet, sans redéploiement. Le fichier
 * `config/dpi.php` ne sert plus que de valeurs de départ, quand rien n'a
 * encore été paramétré.
 */
class ParametreService
{
    private const PREFIXE_CACHE = 'dpi.parametre.';

    /** Établissement courant, ou le premier enregistré hors session. */
    public function etablissementId(): ?string
    {
        return auth()->user()?->establishment_id
            ?? Establishment::orderBy('created_at')->value('id');
    }

    public function lire(string $cle, mixed $defaut = null): mixed
    {
        $etablissement = $this->etablissementId();

        if (! $etablissement) {
            return $defaut;
        }

        $valeur = Cache::rememberForever(
            self::PREFIXE_CACHE.$etablissement.'.'.$cle,
            fn () => Parametre::where('establishment_id', $etablissement)
                ->where('cle', $cle)
                ->value('valeur')
        );

        return $valeur ?? $defaut;
    }

    public function ecrire(string $cle, mixed $valeur): void
    {
        $etablissement = $this->etablissementId();

        if (! $etablissement) {
            return;
        }

        Parametre::updateOrCreate(
            ['establishment_id' => $etablissement, 'cle' => $cle],
            ['valeur' => $valeur, 'user_id' => auth()->id()]
        );

        Cache::forget(self::PREFIXE_CACHE.$etablissement.'.'.$cle);
    }

    /**
     * Taux de change courants, devise par devise.
     *
     * @return array<string, float>
     */
    public function tauxChange(): array
    {
        $defaut = collect(config('dpi.devises', []))
            ->map(fn ($d) => (float) $d['taux_cdf'])
            ->all();

        return array_merge($defaut, array_map('floatval', (array) $this->lire('taux_change', [])));
    }

    /**
     * Révise le taux d'une devise et garde trace de la révision.
     *
     * Aucune écriture passée n'est touchée : acomptes, paiements et factures
     * portent chacun le taux qui leur a été appliqué. Seules les opérations
     * à venir utiliseront le nouveau taux.
     */
    public function reviserTaux(string $devise, float $taux, ?string $motif = null): TauxChange
    {
        return DB::transaction(function () use ($devise, $taux, $motif) {
            $courants = $this->tauxChange();
            $precedent = $courants[$devise] ?? null;

            $courants[$devise] = $taux;
            $this->ecrire('taux_change', $courants);

            return TauxChange::create([
                'establishment_id' => $this->etablissementId(),
                'devise' => $devise,
                'taux_cdf' => $taux,
                'taux_precedent' => $precedent,
                'user_id' => auth()->id(),
                'motif' => $motif,
                'applique_a' => now(),
            ]);
        });
    }

    /** Vide le cache des réglages — utile après un import ou une restauration. */
    public function oublierCache(): void
    {
        $etablissement = $this->etablissementId();

        if (! $etablissement) {
            return;
        }

        foreach (['taux_change'] as $cle) {
            Cache::forget(self::PREFIXE_CACHE.$etablissement.'.'.$cle);
        }
    }
}
