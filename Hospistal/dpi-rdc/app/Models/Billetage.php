<?php

namespace App\Models;

use App\Services\DeviseService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comptage physique de la caisse par coupure, à la clôture d'une session.
 * L'écart entre le compté et le théorique est la valeur utile du contrôle.
 */
class Billetage extends Model
{
    use HasUuids;

    protected $fillable = [
        'establishment_id', 'caissier_id', 'devise', 'coupures',
        'total_compte', 'total_theorique', 'ecart',
        'debut_periode', 'fin_periode', 'observation',
    ];

    protected function casts(): array
    {
        return [
            'coupures' => 'array',
            'total_compte' => 'decimal:2',
            'total_theorique' => 'decimal:2',
            'ecart' => 'decimal:2',
            'debut_periode' => 'datetime',
            'fin_periode' => 'datetime',
        ];
    }

    /**
     * Coupures réellement en circulation, de la plus grosse à la plus
     * petite. Elles viennent du référentiel des devises : le franc
     * congolais s'arrête à 50 CDF, les pièces de 1 à 20 ne circulant plus.
     */
    public static function coupuresPour(string $devise): array
    {
        return app(DeviseService::class)->existe($devise)
            ? app(DeviseService::class)->coupures($devise)
            : app(DeviseService::class)->coupures('CDF');
    }

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function ecartSignificatif(): bool
    {
        return abs((float) $this->ecart) > 0.01;
    }
}
