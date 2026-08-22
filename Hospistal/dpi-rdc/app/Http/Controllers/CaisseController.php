<?php

namespace App\Http\Controllers;

use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Prescription;
use App\Models\Visit;
use App\Services\AcompteService;
use App\Services\DeviseService;
use App\Services\FacturationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $facture->load([
            'patient', 'lignes', 'lignesTiersPayant.assurance',
            'prescription.lignes.medicament', 'paiements', 'bonsSortie',
            'imputations.acompte',
        ]);

        // Acompte encore disponible pour ce patient, tous séjours confondus :
        // une avance laissée aux urgences doit pouvoir régler l'ordonnance
        // délivrée le lendemain.
        $acomptes = app(AcompteService::class);
        $acompteDisponible = $acomptes->soldePatient($facture->patient_id);
        $acompteParDevise = $acomptes->soldeParDevise($facture->patient_id);

        return view('caisse.show', compact('facture', 'acompteDisponible', 'acompteParDevise'));
    }

    /**
     * Mobilise l'acompte du patient sur cette facture, à la demande du
     * guichet. L'imputation automatique se limite au séjour concerné ;
     * ici le caissier décide d'utiliser l'avance disponible.
     */
    public function utiliserAcompte(Facture $facture): RedirectResponse
    {
        if ($facture->statut === 'payee') {
            return back()->with('info', 'Cette facture est déjà soldée.');
        }

        $montant = app(AcompteService::class)->imputer($facture, toutLePatient: true);

        if ($montant <= 0) {
            return back()->with('error', 'Aucun acompte disponible pour ce patient.');
        }

        $facture->refresh();

        return back()->with('success',
            $facture->formater($montant).' d\'acompte imputés sur cette facture'
            .($facture->statut === 'payee'
                ? ' — elle est soldée.'
                : ' — reste '.$facture->formater($facture->soldeRestant()).' à encaisser.'));
    }

    /**
     * Encaissement par formulaire classique (aucune dépendance JavaScript).
     * Émet le bon pharmacie / labo / imagerie et débloque la file du médecin.
     */
    public function encaisser(Request $request, Facture $facture): RedirectResponse
    {
        $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'devise' => 'required|'.app(DeviseService::class)->regleValidation(),
            'mode_paiement' => 'required|in:especes,mobile_money,virement,cheque',
            'reference' => 'nullable|string|max:200',
        ], [
            'montant.required' => 'Indiquez le montant reçu.',
            'montant.min' => 'Le montant doit être positif.',
        ]);

        if ($facture->statut === 'payee') {
            return redirect()->route('caisse.show', $facture)->with('info', 'Cette facture est déjà payée.');
        }

        $prescription = $facture->prescription_id ? Prescription::find($facture->prescription_id) : null;
        $examen = $prescription ? null : ExamenLaboratoire::where('facture_id', $facture->id)->first();

        $resultat = app(FacturationService::class)->validerPaiement(
            $facture,
            (float) $request->input('montant'),
            $request->input('devise'),
            $request->input('mode_paiement'),
            $request->input('reference') ?: null,
            $prescription,
            $examen,
        );

        $message = 'Paiement enregistré.';
        if ($resultat['bon_sortie']) {
            $message .= ' Bon '.$resultat['bon_sortie']->type.' émis : '.$resultat['bon_sortie']->numero.'.';
        }

        return redirect()->route('caisse.show', $facture)->with('success', $message);
    }

    /**
     * Facture / reçu imprimable.
     */
    public function imprimer(Facture $facture): View
    {
        $facture->load(['patient', 'visit', 'lignes', 'paiements.caissier', 'lignesTiersPayant.assurance', 'bonsSortie']);

        return view('caisse.imprimer', [
            'facture' => $facture,
            'sousExamens' => $this->sousExamensFactures($facture),
        ]);
    }

    /**
     * Sous-examens couverts par chaque ligne d'examen de la facture.
     *
     * Une ligne « Ionogramme sanguin » recouvre quatre dosages : le patient
     * doit lire sur sa facture ce qu'il paie, comme il le lit sur le bulletin.
     *
     * @return array<string, array<int, string>> type d'examen → paramètres
     */
    private function sousExamensFactures(Facture $facture): array
    {
        $examens = ExamenLaboratoire::with('resultats.typeExamen')
            ->where('facture_id', $facture->id)
            ->get();

        $detail = [];

        foreach ($examens as $examen) {
            foreach ($examen->resultats->groupBy('type_examen_id') as $typeId => $resultats) {
                $parametres = $resultats
                    ->map(fn ($r) => $r->parametre)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                // Un examen à paramètre unique n'a rien à détailler : son
                // libellé dit déjà tout (une radiographie, une glycémie).
                if (count($parametres) > 1) {
                    $detail[$typeId] = $parametres;
                }
            }
        }

        return $detail;
    }

    /**
     * Génère la facture au guichet — le patient doit payer avant dispensation/soins.
     */
    public function facturer(Prescription $prescription): RedirectResponse
    {
        // 'dispensee' est facturable pour les hospitalisés servis à crédit
        if (! in_array($prescription->statut, ['brouillon', 'en_attente_paiement', 'dispensee'], true)) {
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

    public function facturerConsultation(Visit $visit): RedirectResponse
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
}
