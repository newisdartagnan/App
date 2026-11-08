<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Models\Ordonnance;
use App\Services\Dossier\DocumentOrdonnance;
use App\Services\RendezVous\Affichage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/** PRE-01 : « Mes ordonnances », côté patient. Seulement les siennes. */
class MesOrdonnancesController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dossier/MesOrdonnances', [
            'ordonnances' => Ordonnance::where('patient_id', $request->user()->id)
                ->with(['medecin.user:id,nom,prenom'])->withCount('lignes')
                ->orderByDesc('signee_le')->orderByDesc('numero')
                ->get()
                ->map(fn (Ordonnance $o) => [
                    'id' => $o->id,
                    'numero' => $o->numero,
                    'emise' => Affichage::jour($o->signee_le),
                    'medecin' => Affichage::medecin($o->medecin),
                    'lignes' => $o->lignes_count,
                ]),
        ]);
    }

    public function pdf(Request $request, string $ordonnance, DocumentOrdonnance $document): ReponseHttp
    {
        // Cherchée parmi les siennes : celle d'un autre patient répond 404.
        return $document->pdf(Ordonnance::where('patient_id', $request->user()->id)->findOrFail($ordonnance));
    }
}
