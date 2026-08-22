<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Ce qu'une autre maison du réseau annonce avoir en rayon.
 *
 * Un bulletin n'est pas un stock : c'est ce qu'un hôpital a déclaré à une
 * certaine heure. Entre les deux il y a la liaison, qui peut avoir traîné,
 * et la maison d'en face, qui a pu transfuser trois poches depuis. Toute la
 * question est donc l'âge : à trois heures du matin on envoie une ambulance
 * sur cette ligne, et il faut savoir si elle date de dix minutes ou de dix
 * heures.
 */
class BulletinStockSang extends Model
{
    use HasUuids;

    protected $table = 'bulletins_stock_sang';

    protected $fillable = [
        'etablissement_code', 'nom', 'ville', 'province', 'telephone',
        'stock', 'donneurs', 'publie_le', 'recu_le',
    ];

    /** En deçà, l'annonce vaut ce qu'elle dit. */
    public const FRAIS_MINUTES = 90;

    /** Au-delà, on ne l'affiche plus du tout : mieux vaut rien qu'un mensonge. */
    public const PERIME_HEURES = 24;

    protected function casts(): array
    {
        return [
            'stock' => 'array',
            'donneurs' => 'array',
            'publie_le' => 'datetime',
            'recu_le' => 'datetime',
        ];
    }

    /** Les bulletins encore dignes d'un coup de téléphone. */
    public function scopeExploitables(Builder $query): Builder
    {
        return $query->where('publie_le', '>=', now()->subHours(self::PERIME_HEURES));
    }

    public function minutesDage(): int
    {
        return max(0, (int) $this->publie_le->diffInMinutes(now()));
    }

    public function estFrais(): bool
    {
        return $this->minutesDage() <= self::FRAIS_MINUTES;
    }

    /**
     * L'âge en toutes lettres — c'est ce que lit l'infirmier de garde.
     */
    public function libelleAge(): string
    {
        $minutes = $this->minutesDage();

        if ($minutes < 2) {
            return 'à l\'instant';
        }

        if ($minutes < 60) {
            return "il y a {$minutes} min";
        }

        $heures = intdiv($minutes, 60);

        return $heures < 24
            ? 'il y a '.$heures.' h'
            : 'il y a '.intdiv($heures, 24).' j';
    }

    /**
     * Combien de poches annoncées pour ces groupes-là, dans ce produit-là.
     *
     * @param  array<int, string>  $groupes
     */
    public function nombrePour(array $groupes, string $produit): int
    {
        $parGroupe = $this->stock[$produit] ?? [];

        return (int) collect($groupes)->sum(fn (string $g) => (int) ($parGroupe[$g] ?? 0));
    }

    /** Tout produit confondu. */
    public function total(): int
    {
        return (int) collect($this->stock ?? [])
            ->sum(fn ($parGroupe) => collect($parGroupe)->sum());
    }

    /**
     * Le stock d'un produit, groupe par groupe, sans les cases vides.
     *
     * @return array<string, int>
     */
    public function parGroupe(string $produit): array
    {
        return collect($this->stock[$produit] ?? [])
            ->filter(fn ($n) => (int) $n > 0)
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** Donneurs éligibles annoncés pour ces groupes — un nombre, pas des noms. */
    public function donneursPour(array $groupes): int
    {
        return (int) collect($groupes)->sum(fn (string $g) => (int) (($this->donneurs ?? [])[$g] ?? 0));
    }
}
