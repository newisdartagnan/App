<?php

namespace App\Observers;

use App\Jobs\WriteAuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    protected array $excludedFields = ['password', 'offline_token', 'remember_token'];

    public function created(Model $model): void
    {
        $this->dispatch('create', $model, null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $this->dispatch('update', $model, $model->getOriginal(), $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->dispatch('delete', $model, $model->getOriginal(), null);
    }

    protected function dispatch(string $action, Model $model, ?array $old, ?array $new): void
    {
        WriteAuditLog::dispatch(
            action: "{$model->getTable()}.{$action}",
            tableName: $model->getTable(),
            recordId: $model->getKey(),
            oldValues: $this->filterSensitive($old),
            newValues: $this->filterSensitive($new),
            establishmentId: $model->establishment_id ?? null,
        );
    }

    protected function filterSensitive(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return collect($data)->except($this->excludedFields)->all();
    }
}
