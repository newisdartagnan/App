<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonSortie extends Model
{
    use HasUuids;
    protected $table = 'bons_sortie';
    protected $fillable = [
        'numero', 'facture_id', 'patient_id', 'emis_par',
        'type', 'statut', 'prescription_id', 'examen_id',
        'expire_at', 'utilise_at', 'imprime',
    ];
    protected function casts(): array
    {
        return [
            'expire_at' => 'datetime',
            'utilise_at' => 'datetime',
            'imprime' => 'boolean',
        ];
    }
    public function facture(): BelongsTo { return $this->belongsTo(Facture::class); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function prescription(): BelongsTo { return $this->belongsTo(Prescription::class); }
    public function examen(): BelongsTo { return $this->belongsTo(ExamenLaboratoire::class, 'examen_id'); }
    public function emetteur(): BelongsTo { return $this->belongsTo(User::class, 'emis_par'); }
    public static function genererNumero(): string
    {
        $prefix = 'BS-' . now()->format('Ymd') . '-';
        $last = static::where('numero', 'like', $prefix . '%')
            ->orderByDesc('numero')->value('numero');
        $seq = $last ? (int) substr($last, -4) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}