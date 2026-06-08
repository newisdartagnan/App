<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_name' => 'required|string|max:100',
            'record_id' => 'required|uuid',
            'action' => 'required|in:create,update,delete',
            'payload' => 'nullable|array',
            'establishment_code' => 'required|string',
        ]);

        $clinicalTables = ['consultations', 'prescriptions', 'lignes_prescription', 'dispensations', 'resultats_examens'];

        if (in_array($validated['table_name'], $clinicalTables) && $validated['action'] === 'update') {
            $existing = DB::table($validated['table_name'])
                ->where('id', $validated['record_id'])
                ->first();

            if ($existing && $this->hasConflict($existing, $validated['payload'] ?? [])) {
                return response()->json([
                    'message' => 'Conflit détecté — validation humaine requise',
                    'central_data' => (array) $existing,
                ], 409);
            }
        }

        return response()->json(['status' => 'accepted']);
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'pending_conflicts' => SyncConflict::where('resolution', 'pending')->count(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    protected function hasConflict(object $existing, array $incoming): bool
    {
        $existingUpdated = $existing->updated_at ?? null;
        $incomingUpdated = $incoming['updated_at'] ?? null;

        return $existingUpdated && $incomingUpdated && $existingUpdated !== $incomingUpdated;
    }
}
