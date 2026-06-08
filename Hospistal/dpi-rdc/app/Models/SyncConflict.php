<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncConflict extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'sync_conflicts';

    protected $fillable = [
        'table_name', 'record_id', 'establishment_id',
        'local_data', 'central_data', 'resolution',
        'resolved_by', 'resolved_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'local_data' => 'array',
            'central_data' => 'array',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}
