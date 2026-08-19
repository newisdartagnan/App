<?php

namespace App\Http\Controllers;

use App\Models\DocumentClinique;
use App\Models\Patient;
use App\Models\PatientReferentielMedical;
use App\Models\ReferentielMedical;
use App\Services\DossierMedicalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dossier médical du patient : antécédents et allergies structurés,
 * documents cliniques (certificats, rapports, courriers).
 */
class DossierMedicalController extends Controller
{
    public function __construct(protected DossierMedicalService $dossier) {}

    public function show(Patient $patient): View
    {
        $antecedents = $this->dossier->antecedents($patient);
        $allergies = $this->dossier->allergies($patient);

        $catalogueAntecedents = $this->dossier->catalogue('antecedent');
        $catalogueAllergies = $this->dossier->catalogue('allergie');

        $documents = DocumentClinique::with('auteur')
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->get();

        return view('dossier.show', compact(
            'patient', 'antecedents', 'allergies',
            'catalogueAntecedents', 'catalogueAllergies', 'documents'
        ));
    }

    public function storeReferentiel(Request $request, Patient $patient): RedirectResponse
    {
        $request->validate([
            'referentiel_id' => 'required|uuid|exists:referentiel_medical,id',
            'severite' => 'nullable|in:legere,moderee,severe',
            'precision' => 'nullable|string|max:500',
            'date_constat' => 'nullable|date|before_or_equal:today',
        ]);

        $entree = $this->dossier->ajouter(
            $patient,
            $request->referentiel_id,
            $request->severite,
            $request->precision,
            $request->date_constat
        );

        $type = ReferentielMedical::find($request->referentiel_id)?->type;

        return back()->with('success', $type === 'allergie'
            ? 'Allergie enregistrée au dossier — elle sera confrontée aux prescriptions.'
            : 'Antécédent enregistré au dossier.');
    }

    public function destroyReferentiel(PatientReferentielMedical $entree): RedirectResponse
    {
        $entree->delete();

        return back()->with('success', 'Entrée retirée du dossier.');
    }

    public function storeDocument(Request $request, Patient $patient): RedirectResponse
    {
        $request->validate([
            'type' => 'required|in:' . implode(',', array_keys(DocumentClinique::TYPES)),
            'titre' => 'required|string|max:200',
            'contenu' => 'required|string|min:10',
            'visit_id' => 'nullable|uuid|exists:visits,id',
        ]);

        DocumentClinique::create([
            'patient_id' => $patient->id,
            'visit_id' => $request->visit_id,
            'auteur_id' => auth()->id(),
            'type' => $request->type,
            'titre' => $request->titre,
            'contenu' => $request->contenu,
            'statut' => 'redige',
        ]);

        return back()->with('success', 'Document enregistré.');
    }

    public function validerDocument(DocumentClinique $document): RedirectResponse
    {
        $document->update(['statut' => 'valide', 'valide_at' => now()]);

        return back()->with('success', 'Document validé — il peut être imprimé.');
    }

    public function imprimerDocument(DocumentClinique $document): View
    {
        $document->load(['patient', 'auteur', 'visit']);

        return view('dossier.document-imprimer', compact('document'));
    }
}
