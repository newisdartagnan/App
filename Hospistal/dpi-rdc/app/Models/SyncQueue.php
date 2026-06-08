<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncQueue extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'sync_queue';

    protected $fillable = [
        'establishment_id', 'table_name', 'record_id', 'action',
        'payload', 'attempts', 'last_attempt_at', 'synced_at',
        'error_message', 'priority', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempt_at' => 'datetime',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}
