<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Patient;
use App\Services\DossierNumberService;
use App\Services\PatientDeduplicationService;
use App\Services\VisiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatientController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $sexe = (string) $request->query('sexe', '');

        $patients = Patient::query()
            ->when($search !== '', fn ($q) => $this->appliquerRecherche($q, $search))
            ->when($sexe !== '', fn ($q) => $q->where('sexe', $sexe))
            ->where(function ($q) {
                $q->where('merge_status', '!=', 'merged')->orWhereNull('merge_status');
            })
            ->orderBy('nom')
            ->paginate(20)
            ->withQueryString();

        return view('patients.index', compact('patients', 'search', 'sexe'));
    }

    /**
     * Recherche au fil de la frappe (fetch JSON, indépendante de Livewire).
     */
    public function recherche(Request $request)
    {
        $terme = trim((string) $request->query('q', ''));

        if (mb_strlen($terme) < 2) {
            return response()->json(['patients' => []]);
        }

        $patients = $this->appliquerRecherche(Patient::query(), $terme)
            ->where(function ($q) {
                $q->where('merge_status', '!=', 'merged')->orWhereNull('merge_status');
            })
            ->orderBy('nom')
            ->limit(15)
            ->get()
            ->map(fn (Patient $p) => [
                'id' => $p->id,
                'nom_complet' => $p->nom . ' ' . $p->prenom,
                'dossier' => $p->dossier_number,
                'date_naissance' => $p->date_naissance?->format('d/m/Y'),
                'telephone' => $p->telephone,
                'url' => route('patients.show', $p),
            ]);

        return response()->json(['patients' => $patients]);
    }

    protected function appliquerRecherche($query, string $terme)
    {
        return $query->where(function ($q) use ($terme) {
            $like = '%' . strtolower($terme) . '%';
            $q->whereRaw('LOWER(nom) LIKE ?', [$like])
                ->orWhereRaw('LOWER(prenom) LIKE ?', [$like])
                ->orWhereRaw("LOWER(nom || ' ' || prenom) LIKE ?", [$like])
                ->orWhereRaw('LOWER(dossier_number) LIKE ?', [$like])
                ->orWhereRaw("LOWER(COALESCE(telephone, '')) LIKE ?", [$like]);
        });
    }

    /**
     * Renseigne / met à jour l'assurance d'un patient et active le tiers payant.
     */
    public function majAssurance(Request $request, Patient $patient): RedirectResponse
    {
        $donnees = $request->validate([
            'assurance_nom' => 'required|string|max:100',
            'assurance_numero' => 'nullable|string|max:100',
        ], [
            'assurance_nom.required' => 'Le nom de l\'assurance est obligatoire.',
        ]);

        // Désactiver un éventuel ancien lien avant de re-résoudre
        \App\Models\PatientAssurance::where('patient_id', $patient->id)->update(['est_actif' => false]);

        $patient->update([
            'type_prise_en_charge' => 'assurance',
            'assurance_nom' => $donnees['assurance_nom'],
            'assurance_numero' => $donnees['assurance_numero'] ?? null,
        ]);

        $lien = app(\App\Services\FacturationService::class)->resolvePatientAssurance($patient->fresh());

        return back()->with('success', $lien
            ? "Assurance « {$lien->assurance->nom} » liée — prise en charge à {$lien->assurance->taux_couverture} % appliquée aux prochaines factures."
            : 'Assurance enregistrée.');
    }

    /**
     * Workflow accueil : envoi du patient à la caisse pour régler la
     * consultation avant de voir le médecin.
     */
    public function envoyerCaisse(Request $request, Patient $patient): RedirectResponse
    {
        $request->validate([
            'type' => 'required|in:consultation_externe,urgence',
            'motif' => 'nullable|string|max:500',
        ]);

        $service = app(VisiteService::class);

        if ($active = $service->visiteActive($patient)) {
            return redirect()->route('visites.show', $active)
                ->with('info', 'Ce patient a déjà une visite en cours.');
        }

        $facture = $service->envoyerEnConsultation($patient, $request->type, $request->motif);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Patient envoyé à la caisse — la consultation sera accessible au médecin après paiement.');
    }

    public function create(): View
    {
        return view('patients.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'sexe' => 'required|in:M,F,Inconnu',
            'date_naissance' => 'nullable|date|before:today',
            'lieu_naissance' => 'nullable|string|max:150',
            'telephone' => 'nullable|string|max:50',
            'province' => 'nullable|string|max:100',
            'territoire' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'profession' => 'nullable|string|max:100',
            'situation_matrimoniale' => 'nullable|in:celibataire,marie,divorce,veuf,inconnu',
            'niveau_instruction' => 'nullable|in:aucun,primaire,secondaire,superieur,inconnu',
            'contact_urgence_nom' => 'nullable|string|max:200',
            'contact_urgence_telephone' => 'nullable|string|max:50',
            'contact_urgence_lien' => 'nullable|string|max:100',
            'type_prise_en_charge' => 'required|in:prive,assurance,indigent,fonctionnaire,autre',
            'assurance_nom' => 'nullable|string|max:100',
            'assurance_numero' => 'nullable|string|max:100',
            'nationalite' => 'nullable|string|max:100',
            'confirm_no_duplicate' => 'nullable|boolean',
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
        ]);

        $nom = strtoupper(trim($data['nom']));
        $prenom = ucwords(strtolower(trim($data['prenom'])));
        $dedup = app(PatientDeduplicationService::class);

        if (! $request->boolean('confirm_no_duplicate')) {
            $found = $dedup->findDuplicates([
                'nom' => $nom,
                'prenom' => $prenom,
                'date_naissance' => $data['date_naissance'] ?? null,
                'sexe' => $data['sexe'],
                'lieu_naissance' => $data['lieu_naissance'] ?? null,
                'nom_soundex' => $dedup->metaphone($nom),
                'prenom_soundex' => $dedup->metaphone($prenom),
            ]);

            if ($found->isNotEmpty()) {
                return back()
                    ->withInput()
                    ->with('duplicates', $found->map(fn ($p) => [
                        'id' => $p->id,
                        'nom_complet' => $p->nom_complet,
                        'dossier_number' => $p->dossier_number,
                        'date_naissance' => $p->date_naissance?->format('d/m/Y'),
                        'score' => (int) round(($p->duplicate_score ?? 0) * 100),
                    ])->all());
            }
        }

        $establishment = auth()->user()?->establishment
            ?? Establishment::where('is_active', true)->first();

        if (! $establishment) {
            return back()->withInput()->withErrors([
                'nom' => 'Aucun établissement configuré pour votre compte.',
            ]);
        }

        $patient = Patient::create([
            'establishment_id' => $establishment->id,
            'dossier_number' => app(DossierNumberService::class)->generate($establishment->code),
            'nom' => $nom,
            'prenom' => $prenom,
            'nom_soundex' => $dedup->metaphone($nom),
            'prenom_soundex' => $dedup->metaphone($prenom),
            'date_naissance' => $data['date_naissance'] ?? null,
            'lieu_naissance' => $data['lieu_naissance'] ?? null,
            'sexe' => $data['sexe'],
            'nationalite' => $data['nationalite'] ?? 'Congolaise',
            'telephone' => $data['telephone'] ?? null,
            'adresse' => $data['adresse'] ?? null,
            'province' => $data['province'] ?? null,
            'territoire' => $data['territoire'] ?? null,
            'profession' => $data['profession'] ?? null,
            'situation_matrimoniale' => $data['situation_matrimoniale'] ?? 'inconnu',
            'niveau_instruction' => $data['niveau_instruction'] ?? 'inconnu',
            'contact_urgence_nom' => $data['contact_urgence_nom'] ?? null,
            'contact_urgence_telephone' => $data['contact_urgence_telephone'] ?? null,
            'contact_urgence_lien' => $data['contact_urgence_lien'] ?? null,
            'type_prise_en_charge' => $data['type_prise_en_charge'],
            'assurance_nom' => $data['assurance_nom'] ?? null,
            'assurance_numero' => $data['assurance_numero'] ?? null,
            'merge_status' => 'original',
        ]);

        return redirect()
            ->route('patients.show', $patient)
            ->with('success', 'Patient '.$patient->nom_complet.' créé — Dossier n° '.$patient->dossier_number);
    }

    public function show(Patient $patient): View
    {
        return view('patients.show', compact('patient'));
    }
}
