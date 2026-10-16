<?php

namespace App\Http\Controllers;

use App\Models\Assurance;
use App\Models\BonPriseEnCharge;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Services\FacturationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bons de prise en charge d'un patient.
 *
 * Le bon se saisit à l'accueil ou au guichet, avant la facture : c'est lui
 * qui ouvre le tiers payant quand la convention l'exige. Un bon saisi par
 * erreur s'annule, il ne s'efface pas — des factures peuvent le citer.
 */
class BonPriseEnChargeController extends Controller
{
    public function index(Patient $patient): View
    {
        $this->autoriser();

        $contexte = app(FacturationService::class)->priseEnCharge($patient);

        return view('bons-prise-en-charge.index', [
            'patient' => $patient,
            'bons' => BonPriseEnCharge::with(['assurance', 'auteur'])
                ->withCount('factures')
                ->where('patient_id', $patient->id)
                ->orderByDesc('date_emission')
                ->get(),
            'assurances' => Assurance::where('est_actif', true)->orderBy('nom')->get(),
            'assuranceDuPatient' => PatientAssurance::where('patient_id', $patient->id)
                ->where('est_actif', true)
                ->value('assurance_id'),
            'motif' => $contexte['motif'],
            'bonApplique' => $contexte['bon'],
        ]);
    }

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'assurance_id' => 'required|uuid|exists:assurances,id',
            'numero' => [
                'required', 'string', 'max:60',
                Rule::unique('bons_prise_en_charge', 'numero')
                    ->where('assurance_id', $request->input('assurance_id')),
            ],
            'date_emission' => 'required|date',
            'date_expiration' => 'required|date|after_or_equal:date_emission',
            'montant_autorise' => 'nullable|numeric|min:0',
            'devise' => 'nullable|in:CDF,USD',
            'objet' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ], [
            'numero.required' => 'Recopiez le numéro porté sur le bon.',
            'numero.unique' => 'Ce numéro de bon est déjà enregistré pour cette convention.',
            'date_expiration.after_or_equal' => 'Le bon ne peut expirer avant d\'avoir été émis.',
            'date_expiration.required' => 'Indiquez jusqu\'à quand le bon est valable.',
        ]);

        $bon = BonPriseEnCharge::create([
            ...$donnees,
            'numero' => trim($donnees['numero']),
            'patient_id' => $patient->id,
            'visit_id' => $patient->visits()->whereIn('statut', ['en_attente', 'en_cours'])->latest('date_entree')->value('id'),
            'devise' => $donnees['devise'] ?? 'CDF',
            'statut' => 'valide',
            'saisi_par' => auth()->id(),
        ]);

        $message = 'Bon n° '.$bon->numero.' enregistré, valable jusqu\'au '.$bon->date_expiration->format('d/m/Y').'.';

        $motif = app(FacturationService::class)->priseEnCharge($patient->fresh())['motif'];
        $message .= $motif === null
            ? ' Le tiers payant s\'appliquera aux prochaines factures.'
            : ' Attention : '.$motif.'.';

        return back()->with($motif === null ? 'success' : 'info', $message);
    }

    public function annuler(Request $request, BonPriseEnCharge $bon): RedirectResponse
    {
        $this->autoriser();

        $request->validate(['motif' => 'required|string|min:3|max:255'], [
            'motif.required' => 'Indiquez pourquoi le bon est annulé.',
        ]);

        $bon->update([
            'statut' => 'annule',
            'notes' => trim(($bon->notes ? $bon->notes."\n" : '').'Annulé le '.now()->format('d/m/Y')
                .' par '.auth()->user()->nom_complet.' : '.$request->input('motif')),
        ]);

        return back()->with('success', 'Bon n° '.$bon->numero.' annulé : il n\'ouvrira plus le tiers payant.');
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'caissier', 'chef_facturation', 'agent_admin']),
            403,
            'Les bons de prise en charge se saisissent à l\'accueil, à la caisse ou à la facturation.'
        );
    }
}
