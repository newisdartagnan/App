<?php

namespace App\Livewire;

use App\Models\Establishment;
use App\Models\SyncConflict;
use App\Models\SyncQueue;
use Livewire\Component;

class SyncStatus extends Component
{
    public function render()
    {
        $centralUrl = config('dpi.central_api_url', env('CENTRAL_API_URL', ''));
        $isLocalMode = blank($centralUrl)
            || str_contains($centralUrl, 'localhost')
            || str_contains($centralUrl, '127.0.0.1');

        if ($isLocalMode) {
            return view('livewire.sync-status', [
                'mode' => 'local',
                'pending' => 0,
                'conflicts' => 0,
            ]);
        }

        $establishment = Establishment::first();
        $pending = SyncQueue::whereNull('synced_at')->count();
        $conflicts = SyncConflict::where('resolution', 'pending')->count();
        $lastSync = $establishment?->last_synced_at;
        $isStale = $lastSync ? $lastSync->diffInMinutes(now()) > 30 : true;

        return view('livewire.sync-status', [
            'mode' => 'remote',
            'pending' => $pending,
            'conflicts' => $conflicts,
            'lastSync' => $lastSync,
            'isStale' => $isStale,
        ]);
    }
}
