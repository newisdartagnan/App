<?php

namespace App\Http\Controllers\RendezVous;

use App\Http\Controllers\Controller;
use App\Models\PieceJointeDemande;
use App\Services\RendezVous\DemandeConsultationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pièces jointes d'une demande : jamais d'adresse publique. Servies par
 * cette route au seul patient propriétaire, en téléchargement, sans cache.
 */
class PieceJointeController extends Controller
{
    public function __invoke(Request $request, PieceJointeDemande $piece): StreamedResponse
    {
        abort_unless($piece->demande()->where('patient_id', $request->user()->id)->exists(), 404);

        $disque = Storage::disk(DemandeConsultationService::DISQUE);
        abort_unless($disque->exists($piece->chemin), 404);

        return $disque->download($piece->chemin, $piece->nom_original, [
            'Content-Type' => $piece->type_mime,
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
