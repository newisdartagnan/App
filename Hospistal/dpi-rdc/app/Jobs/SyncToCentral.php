<?php

namespace App\Jobs;

use App\Models\Establishment;
use App\Models\SyncConflict;
use App\Models\SyncQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncToCentral implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $centralUrl = config('dpi.central_api_url');
        if (! $centralUrl || ! $this->isCentralReachable($centralUrl)) {
            return;
        }

        $establishment = Establishment::where('is_active', true)->first();
        if (! $establishment?->central_sync_token) {
            return;
        }

        $items = SyncQueue::whereNull('synced_at')
            ->where('attempts', '<', 5)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        foreach ($items as $item) {
            $this->syncItem($item, $establishment, $centralUrl);
        }

        $establishment->update(['last_synced_at' => now()]);
    }

    protected function syncItem(SyncQueue $item, Establishment $establishment, string $centralUrl): void
    {
        try {
            $response = Http::withToken($establishment->central_sync_token)
                ->timeout(30)
                ->post("{$centralUrl}/api/sync", [
                    'table_name' => $item->table_name,
                    'record_id' => $item->record_id,
                    'action' => $item->action,
                    'payload' => $item->payload,
                    'establishment_code' => $establishment->code,
                ]);

            if ($response->status() === 409) {
                SyncConflict::create([
                    'table_name' => $item->table_name,
                    'record_id' => $item->record_id,
                    'establishment_id' => $establishment->id,
                    'local_data' => $item->payload ?? [],
                    'central_data' => $response->json('central_data', []),
                    'created_at' => now(),
                ]);
                $item->update(['synced_at' => now(), 'error_message' => 'Conflit détecté']);
                return;
            }

            if ($response->successful()) {
                $item->update(['synced_at' => now(), 'attempts' => $item->attempts + 1]);
                return;
            }

            $item->update([
                'attempts' => $item->attempts + 1,
                'last_attempt_at' => now(),
                'error_message' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            $item->update([
                'attempts' => $item->attempts + 1,
                'last_attempt_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            if ($item->attempts >= 5) {
                Log::critical("Sync échouée après 5 tentatives: {$item->id}", ['error' => $e->getMessage()]);
            }
        }
    }

    protected function isCentralReachable(string $url): bool
    {
        try {
            return Http::timeout(5)->get("{$url}/up")->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
