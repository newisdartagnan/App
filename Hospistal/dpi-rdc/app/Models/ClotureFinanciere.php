<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClotureFinanciere extends Model
{
    use HasUuids;

    protected $table = 'clotures_financieres';

    protected $fillable = ['mois', 'total_recettes_cdf', 'total_depenses_cdf', 'observations', 'user_id'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function estClos(\DateTimeInterface|string $date): bool
    {
        $mois = $date instanceof \DateTimeInterface ? $date->format('Y-m') : substr((string) $date, 0, 7);

        return static::where('mois', $mois)->exists();
    }
}
