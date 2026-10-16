<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Écrit une entrée du journal d'audit.
 *
 * L'auteur, son adresse et son navigateur se lisent au moment du geste, pas
 * au moment où la file traite le travail : un worker n'a ni session ni
 * requête, et le journal noterait alors chaque modification « sans auteur ».
 */
class WriteAuditLog implements ShouldQueue
{
    use Queueable;

    public ?string $userId;

    public ?string $ipAddress;

    public ?string $userAgent;

    public function __construct(
        public string $action,
        public ?string $tableName = null,
        public ?string $recordId = null,
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public ?string $establishmentId = null,
        ?string $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        public ?string $factureId = null,
    ) {
        $this->userId = $userId ?? Auth::id();
        $this->ipAddress = $ipAddress ?? Request::ip();
        $this->userAgent = $userAgent ?? Request::userAgent();
    }

    public function handle(): void
    {
        AuditLog::create([
            'user_id' => $this->userId,
            'establishment_id' => $this->establishmentId,
            'action' => $this->action,
            'table_name' => $this->tableName,
            'record_id' => $this->recordId,
            'facture_id' => $this->factureId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'created_at' => now(),
        ]);
    }
}
