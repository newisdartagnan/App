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
        $establishment = Establishment::first();
        $pending = SyncQueue::whereNull('synced_at')->count();
        $conflicts = SyncConflict::where('resolution', 'pending')->count();
        $lastSync = $establishment?->last_synced_at;
        $isStale = $lastSync ? $lastSync->diffInMinutes(now()) > 30 : true;

        return view('livewire.sync-status', compact('pending', 'conflicts', 'lastSync', 'isStale'));
    }
}
