<?php

namespace App\Http\Controllers;

use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Prescription;
use App\Services\FacturationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CaisseController extends Controller
{
    public function index(): View
    {
        $facturesEnAttente = Facture::with(['patient', 'lignes', 'prescription'])
            ->where('statut', 'emise')
            ->orderByDesc('date_facture')
            ->get();

        return view('caisse.index', compact('facturesEnAttente'));
    }

    public function show(Facture $facture): View
    {
        $facture->load(['patient', 'lignes', 'lignesTiersPayant.assurance', 'prescription.lignes.medicament']);

        return view('caisse.show', compact('facture'));
    }

    /**
     * Génère la facture au guichet — le patient doit payer avant dispensation/soins.
     */
    public function facturer(Prescription $prescription): RedirectResponse
    {
        if (! in_array($prescription->statut, ['brouillon', 'en_attente_paiement'], true)) {
            return back()->with('error', 'Cette ordonnance n\'est plus facturable.');
        }

        $factureExistante = Facture::where('prescription_id', $prescription->id)
            ->whereIn('statut', ['emise', 'partiellement_payee'])
            ->first();

        if ($factureExistante) {
            return redirect()->route('caisse.show', $factureExistante)
                ->with('info', 'Une facture est déjà en attente de paiement.');
        }

        $facture = app(FacturationService::class)->creerFacturePrescription($prescription);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Facture émise — le patient doit régler au guichet avant la dispensation.');
    }

    public function facturerConsultation(\App\Models\Visit $visit): RedirectResponse
    {
        $existante = Facture::where('visit_id', $visit->id)
            ->whereHas('lignes', fn ($q) => $q->where('type', 'consultation'))
            ->first();

        if ($existante) {
            return redirect()->route('caisse.show', $existante);
        }

        $facture = app(FacturationService::class)->creerFactureConsultation($visit);

        return redirect()->route('caisse.show', $facture);
    }

    /** @deprecated alias */
    public function creerDepuisPrescription(Prescription $prescription): RedirectResponse
    {
        return $this->facturer($prescription);
    }
}
