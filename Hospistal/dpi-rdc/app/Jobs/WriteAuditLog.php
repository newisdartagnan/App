<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class WriteAuditLog implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $action,
        public ?string $tableName = null,
        public ?string $recordId = null,
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public ?string $establishmentId = null,
    ) {}

    public function handle(): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'establishment_id' => $this->establishmentId,
            'action' => $this->action,
            'table_name' => $this->tableName,
            'record_id' => $this->recordId,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'created_at' => now(),
        ]);
    }
}
