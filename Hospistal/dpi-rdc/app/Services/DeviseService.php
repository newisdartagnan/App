<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Devises du guichet.
 *
 * Le franc congolais est la monnaie de compte : tout est ramené en CDF pour
 * additionner, comparer et imputer. Une opération en dollars ou en euros
 * fige le taux qu'elle a appliqué, de sorte qu'une révision ultérieure du
 * taux ne réécrive pas les acomptes et encaissements déjà passés — un
 * acompte de 100 $ versé à 2 300 vaut 230 000 CDF pour toujours, même si le
 * dollar monte à 2 500 le lendemain.
 */
class DeviseService
{
    public function __construct(private readonly ParametreService $parametres) {}

    /**
     * Devises acceptées, avec le taux **paramétré dans l'application**.
     *
     * Le fichier de configuration ne fournit plus que les libellés, les
     * coupures et les taux de départ : le taux effectif est celui que
     * l'établissement a saisi depuis l'écran de paramétrage.
     *
     * @return array<string, array<string, mixed>>
     */
    public function referentiel(): array
    {
        $taux = $this->parametres->tauxChange();

        return collect(config('dpi.devises', []))
            ->map(fn (array $definition, string $code) => [
                ...$definition,
                'taux_cdf' => $taux[$code] ?? $definition['taux_cdf'],
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function codes(): array
    {
        return array_keys($this->referentiel());
    }

    public function pivot(): string
    {
        return config('dpi.devise_pivot', 'CDF');
    }

    public function existe(string $devise): bool
    {
        return array_key_exists($devise, $this->referentiel());
    }

    /** @return array<string, mixed> */
    public function definition(string $devise): array
    {
        $referentiel = $this->referentiel();

        if (! isset($referentiel[$devise])) {
            throw new InvalidArgumentException("Devise inconnue : {$devise}");
        }

        return $referentiel[$devise];
    }

    /** Taux de change courant d'une devise vers le franc congolais. */
    public function taux(string $devise): float
    {
        return (float) $this->definition($devise)['taux_cdf'];
    }

    public function libelle(string $devise): string
    {
        return $this->definition($devise)['libelle'];
    }

    public function symbole(string $devise): string
    {
        return $this->definition($devise)['symbole'];
    }

    /** Coupures réellement en circulation pour cette devise. */
    public function coupures(string $devise): array
    {
        return $this->definition($devise)['coupures'];
    }

    /**
     * Contre-valeur en francs congolais.
     *
     * Passer $taux pour rejouer une opération au taux qu'elle a figé plutôt
     * qu'au taux du jour.
     */
    public function versCdf(float $montant, string $devise, ?float $taux = null): float
    {
        return round($montant * ($taux ?? $this->taux($devise)), 2);
    }

    /** Montant en francs congolais exprimé dans une autre devise. */
    public function depuisCdf(float $montantCdf, string $devise, ?float $taux = null): float
    {
        $taux = $taux ?? $this->taux($devise);

        if ($taux <= 0) {
            return 0.0;
        }

        return round($montantCdf / $taux, $this->decimales($devise));
    }

    /** Conversion directe d'une devise vers une autre, via le pivot. */
    public function convertir(float $montant, string $depuis, string $vers): float
    {
        if ($depuis === $vers) {
            return $montant;
        }

        return $this->depuisCdf($this->versCdf($montant, $depuis), $vers);
    }

    public function decimales(string $devise): int
    {
        return (int) ($this->definition($devise)['decimales'] ?? 2);
    }

    /** Montant formaté avec sa devise : « 230 000 CDF », « 100,00 $ ». */
    public function formater(float $montant, string $devise): string
    {
        if (! $this->existe($devise)) {
            return number_format($montant, 2, ',', ' ').' '.$devise;
        }

        return number_format($montant, $this->decimales($devise), ',', ' ')
            .' '.$this->symbole($devise);
    }

    /**
     * Montant avec sa contre-valeur, quand la devise n'est pas le pivot :
     * « 100,00 $ (230 000 CDF) ». Le guichet voit ce qu'il a reçu et ce que
     * cela pèse dans les comptes de l'hôpital.
     */
    public function formaterAvecContreValeur(float $montant, string $devise, ?float $taux = null): string
    {
        $rendu = $this->formater($montant, $devise);

        if ($devise === $this->pivot()) {
            return $rendu;
        }

        return $rendu.' ('.$this->formater($this->versCdf($montant, $devise, $taux), $this->pivot()).')';
    }

    /**
     * Colonnes à figer sur toute écriture monétaire : la devise saisie, le
     * taux appliqué et la contre-valeur en monnaie de compte.
     *
     * @return array{devise: string, taux_change: float, montant_cdf: float}
     */
    public function empreinte(float $montant, string $devise): array
    {
        $taux = $this->taux($devise);

        return [
            'devise' => $devise,
            'taux_change' => $taux,
            'montant_cdf' => $this->versCdf($montant, $devise, $taux),
        ];
    }

    /** Règle de validation Laravel listant les devises acceptées. */
    public function regleValidation(): string
    {
        return 'in:'.implode(',', $this->codes());
    }
}
