<?php

namespace App\Models\Concerns;

trait Syncable
{
    public static function bootSyncable(): void
    {
        static::created(function ($model) {
            $model->queueForSync('create');
        });

        static::updated(function ($model) {
            if ($model->wasChanged() && ! $model->wasChanged('sync_status')) {
                $model->queueForSync('update');
            }
        });

        static::deleted(function ($model) {
            $model->queueForSync('delete');
        });
    }

    protected function queueForSync(string $action): void
    {
        $establishmentId = $this->resolveEstablishmentId();
        if (! $establishmentId) {
            return;
        }

        \App\Models\SyncQueue::create([
            'establishment_id' => $establishmentId,
            'table_name' => $this->getTable(),
            'record_id' => $this->getKey(),
            'action' => $action,
            'payload' => $action !== 'delete' ? $this->toArray() : null,
            'priority' => $this->getSyncPriority(),
            'created_at' => now(),
        ]);

        if ($this->sync_status !== 'pending') {
            $this->updateQuietly(['sync_status' => 'pending']);
        }
    }

    protected function resolveEstablishmentId(): ?string
    {
        if (! empty($this->establishment_id)) {
            return $this->establishment_id;
        }

        if (method_exists($this, 'visit') && $this->relationLoaded('visit') === false && $this->visit_id) {
            return $this->visit()->value('establishment_id');
        }

        return null;
    }

    protected function getSyncPriority(): int
    {
        return 5;
    }
}
