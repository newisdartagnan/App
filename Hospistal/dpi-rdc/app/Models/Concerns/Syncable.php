<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Log;

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
        try {
            $establishmentId = $this->resolveEstablishmentId();
            if (! $establishmentId) {
                return;
            }

            $payload = null;
            if ($action !== 'delete') {
                $payload = collect($this->toArray())
                    ->except(['password', 'offline_token', 'remember_token'])
                    ->all();
            }

            \App\Models\SyncQueue::create([
                'establishment_id' => $establishmentId,
                'table_name' => $this->getTable(),
                'record_id' => $this->getKey(),
                'action' => $action,
                'payload' => $payload,
                'priority' => $this->getSyncPriority(),
                'created_at' => now(),
            ]);

            if (($this->sync_status ?? null) !== 'pending') {
                $this->updateQuietly(['sync_status' => 'pending']);
            }
        } catch (\Throwable $e) {
            Log::warning('Sync queue ignorée', [
                'table' => $this->getTable(),
                'id' => $this->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveEstablishmentId(): ?string
    {
        if (! empty($this->establishment_id)) {
            return $this->establishment_id;
        }

        if ($this->visit_id ?? null) {
            return $this->visit()->value('establishment_id');
        }

        if ($this->consultation_id ?? null) {
            return \App\Models\Consultation::query()
                ->whereKey($this->consultation_id)
                ->with('visit:id,establishment_id')
                ->first()
                ?->visit
                ?->establishment_id;
        }

        return null;
    }

    protected function getSyncPriority(): int
    {
        return 5;
    }
}
