<?php

namespace App\Http\Controllers;

use App\Models\EmpreintePatient;
use App\Models\MethodePaiement;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\PatientMethodePaiement;
use App\Models\RelationFamiliale;
use App\Services\FacturationService;
use App\Support\Image;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * La partie administrative du dossier : identité (modifier, carte, photo,
 * empreintes), relations familiales, méthodes de paiement et carte de
 * vaccination.
 */
class PatientIdentiteController extends Controller
{
    // ── Identité ─────────────────────────────────────────────────────────

    public function edit(Patient $patient): View
    {
        $this->autoriser('patient.update');

        return view('patients.edit', compact('patient'));
    }

    public function update(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser('patient.update');

        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'postnom' => 'nullable|string|max:100',
            'prenom' => 'required|string|max:100',
            'sexe' => 'required|in:M,F,Inconnu',
            // Un patient amené inconscient n'a pas toujours d'âge ni
            // d'adresse connus : on complète plus tard, on ne bloque pas.
            'date_naissance' => 'nullable|date|before_or_equal:today',
            'lieu_naissance' => 'nullable|string|max:150',
            'nationalite' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'province' => 'nullable|string|max:100',
            'territoire' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
            'profession' => 'nullable|string|max:100',
            'situation_matrimoniale' => ['nullable', Rule::in(['celibataire', 'marie', 'divorce', 'veuf', 'union_libre', 'inconnu'])],
            'contact_urgence_nom' => 'nullable|string|max:200',
            'contact_urgence_telephone' => 'nullable|string|max:50',
            'contact_urgence_lien' => 'nullable|string|max:100',
            'autres_details' => 'nullable|string|max:2000',
        ]);

        $donnees['nom'] = mb_strtoupper($donnees['nom']);
        // La recherche phonétique (doublons) suit le nom corrigé.
        $dedup = app(\App\Services\PatientDeduplicationService::class);
        $donnees['nom_soundex'] = $dedup->metaphone($donnees['nom']);
        $donnees['prenom_soundex'] = $dedup->metaphone($donnees['prenom']);
        $patient->update($donnees);

        return redirect()->route('patients.show', $patient)->with('success', 'Identité du patient mise à jour.');
    }

    /** Carte du patient (format carte bancaire), à imprimer et plastifier. */
    public function carte(Patient $patient): Response
    {
        $this->autoriser('patient.view');

        $photo = $patient->photo_path && Storage::disk('local')->exists($patient->photo_path)
            ? 'data:image/jpeg;base64,'.base64_encode(Storage::disk('local')->get($patient->photo_path))
            : null;

        $pdf = Pdf::loadView('pdf.carte-patient', [
            'patient' => $patient,
            'photo' => $photo,
            'etablissement' => config('dpi.establishment_name', config('app.name')),
        ])->setPaper([0, 0, 243, 153]); // 85,6 × 54 mm

        return $pdf->stream('carte_'.$patient->dossier_number.'.pdf');
    }

    public function photo(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser('patient.update');
        $request->validate(['photo' => 'required|image|max:8192'], [
            'photo.required' => 'Prenez ou choisissez une photo.',
            'photo.image' => 'Le fichier doit être une image.',
        ]);

        $ancien = $patient->photo_path;
        $patient->update(['photo_path' => Image::enregistrerReduite($request->file('photo'), 'photos/patients', 400)]);
        if ($ancien) {
            Storage::disk('local')->delete($ancien);
        }

        return back()->with('success', 'Photo du patient enregistrée.');
    }

    public function voirPhoto(Patient $patient): Response
    {
        $this->autoriser('patient.view');
        abort_unless($patient->photo_path && Storage::disk('local')->exists($patient->photo_path), 404);

        return response(Storage::disk('local')->get($patient->photo_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    // ── Empreintes digitales ─────────────────────────────────────────────

    public function empreintes(Patient $patient): View
    {
        $this->autoriser('patient.update');

        return view('patients.empreintes', [
            'patient' => $patient,
            'empreintes' => $patient->empreintes()->with('auteur')->get()->keyBy('doigt'),
        ]);
    }

    /**
     * Enregistre une empreinte : l'image produite par le lecteur et/ou le
     * gabarit (template) qu'il exporte. Le lecteur se branche sur le poste ;
     * son logiciel dépose le fichier, ou le colle ici.
     */
    public function enregistrerEmpreinte(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser('patient.update');
        $donnees = $request->validate([
            'doigt' => ['required', Rule::in(array_keys(EmpreintePatient::DOIGTS))],
            'image' => 'nullable|image|max:4096|required_without:gabarit',
            'gabarit' => 'nullable|string|max:20000|required_without:image',
        ], ['image.required_without' => 'Joignez l\'image du lecteur ou collez le gabarit.']);

        $empreinte = EmpreintePatient::firstOrNew(['patient_id' => $patient->id, 'doigt' => $donnees['doigt']]);
        if ($request->hasFile('image')) {
            if ($empreinte->image_path) {
                Storage::disk('local')->delete($empreinte->image_path);
            }
            $empreinte->image_path = $request->file('image')->store('empreintes/'.$patient->id, 'local');
        }
        if (filled($donnees['gabarit'] ?? null)) {
            $empreinte->gabarit = trim($donnees['gabarit']);
        }
        $empreinte->user_id = auth()->id();
        $empreinte->save();

        return back()->with('success', 'Empreinte « '.EmpreintePatient::DOIGTS[$donnees['doigt']].' » enregistrée.');
    }

    public function supprimerEmpreinte(EmpreintePatient $empreinte): RedirectResponse
    {
        $this->autoriser('patient.update');
        if ($empreinte->image_path) {
            Storage::disk('local')->delete($empreinte->image_path);
        }
        $empreinte->delete();

        return back()->with('success', 'Empreinte supprimée.');
    }

    public function imageEmpreinte(EmpreintePatient $empreinte): Response
    {
        $this->autoriser('patient.update');
        abort_unless($empreinte->image_path && Storage::disk('local')->exists($empreinte->image_path), 404);

        return response(Storage::disk('local')->get($empreinte->image_path), 200, [
            'Content-Type' => Storage::disk('local')->mimeType($empreinte->image_path) ?: 'image/png',
        ]);
    }

    // ── Relations familiales ─────────────────────────────────────────────

    public function ajouterRelation(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser('patient.update');
        $donnees = $request->validate([
            'proche_id' => ['required', 'uuid', 'exists:patients,id', Rule::notIn([$patient->id])],
            'lien' => ['required', Rule::in(array_keys(RelationFamiliale::LIENS))],
            'precision' => 'nullable|string|max:100',
        ], [
            'proche_id.required' => 'Choisissez le patient enregistré.',
            'proche_id.not_in' => 'Un patient ne peut pas être son propre parent.',
        ]);

        $existe = RelationFamiliale::where(fn ($q) => $q
            ->where(['patient_id' => $patient->id, 'proche_id' => $donnees['proche_id']])
            ->orWhere(fn ($w) => $w->where(['patient_id' => $donnees['proche_id'], 'proche_id' => $patient->id])))
            ->exists();
        if ($existe) {
            return back()->with('error', 'Ces deux dossiers sont déjà liés.');
        }

        RelationFamiliale::create($donnees + ['patient_id' => $patient->id, 'user_id' => auth()->id()]);

        return back()->with('success', 'Relation familiale ajoutée.');
    }

    public function supprimerRelation(RelationFamiliale $relation): RedirectResponse
    {
        $this->autoriser('patient.update');
        $relation->delete();

        return back()->with('success', 'Relation familiale retirée.');
    }

    /**
     * Les proches d'un patient, vus depuis son dossier : les liens qu'il a
     * déclarés et ceux où il figure comme proche, retournés (le parent d'un
     * enfant voit « Enfant »).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function proches(Patient $patient)
    {
        $directs = $patient->relationsFamiliales()->with('proche')->get()->map(fn ($r) => (object) [
            'relation' => $r, 'proche' => $r->proche, 'lien' => $r->lien,
        ]);
        $inverses = $patient->relationsInverses()->with('patient')->get()->map(fn ($r) => (object) [
            'relation' => $r, 'proche' => $r->patient, 'lien' => RelationFamiliale::INVERSES[$r->lien] ?? 'autre',
        ]);

        return $directs->concat($inverses)->filter(fn ($p) => $p->proche)->values();
    }

    // ── Méthodes de paiement ─────────────────────────────────────────────

    /**
     * Ajoute une méthode au patient : une existante (réutilisée) ou une
     * nouvelle, créée par son libellé. Liée à une assurance, elle active
     * aussi le tiers payant pour les prochaines factures.
     */
    public function ajouterMethode(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser('patient.update', 'facture.create');
        $donnees = $request->validate([
            'methode_paiement_id' => 'nullable|uuid|exists:methodes_paiement,id|required_without:libelle',
            'libelle' => 'nullable|string|max:150|required_without:methode_paiement_id',
            'type' => ['nullable', Rule::in(array_keys(MethodePaiement::TYPES))],
            'identifiant' => 'nullable|string|max:100',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
        ], [
            'libelle.required_without' => 'Choisissez une méthode existante ou saisissez le libellé d\'une nouvelle.',
            'date_fin.after_or_equal' => 'La date de fin précède la date de début.',
        ]);

        $methode = DB::transaction(function () use ($donnees, $patient) {
            $methode = filled($donnees['methode_paiement_id'] ?? null)
                ? MethodePaiement::findOrFail($donnees['methode_paiement_id'])
                : MethodePaiement::firstOrCreate(
                    ['libelle' => trim($donnees['libelle'])],
                    ['type' => $donnees['type'] ?? 'autre', 'est_actif' => true]
                );

            PatientMethodePaiement::updateOrCreate(
                ['patient_id' => $patient->id, 'methode_paiement_id' => $methode->id],
                [
                    'identifiant' => $donnees['identifiant'] ?? null,
                    'date_debut' => $donnees['date_debut'] ?? null,
                    'date_fin' => $donnees['date_fin'] ?? null,
                    'user_id' => auth()->id(),
                ]
            );

            if ($methode->assurance_id) {
                PatientAssurance::where('patient_id', $patient->id)->update(['est_actif' => false]);
                PatientAssurance::updateOrCreate(
                    ['patient_id' => $patient->id, 'assurance_id' => $methode->assurance_id],
                    [
                        // La police est obligatoire : sans identifiant fourni,
                        // le numéro de dossier en tient lieu.
                        'numero_police' => ($donnees['identifiant'] ?? null) ?: $patient->dossier_number,
                        'date_debut' => $donnees['date_debut'] ?? null,
                        'date_fin' => $donnees['date_fin'] ?? null,
                        'est_actif' => true,
                    ]
                );
                $patient->update([
                    'type_prise_en_charge' => 'assurance',
                    'assurance_nom' => $methode->assurance?->nom,
                    'assurance_numero' => $donnees['identifiant'] ?? null,
                ]);
            }

            return $methode;
        });

        return back()->with('success', "Méthode de paiement « {$methode->libelle} » ajoutée"
            .($methode->assurance_id ? ' — tiers payant activé pour les prochaines factures.' : '.'));
    }

    public function retirerMethode(PatientMethodePaiement $lien): RedirectResponse
    {
        $this->autoriser('patient.update', 'facture.create');
        $methode = $lien->methode;
        $patient = $lien->patient;
        $lien->delete();

        if ($methode?->assurance_id) {
            PatientAssurance::where('patient_id', $patient->id)
                ->where('assurance_id', $methode->assurance_id)
                ->update(['est_actif' => false]);
        }

        return back()->with('success', 'Méthode de paiement retirée du dossier.');
    }

    // ── Carte de vaccination ─────────────────────────────────────────────

    public function carteVaccination(Patient $patient): Response
    {
        $this->autoriser('patient.view');

        $pdf = Pdf::loadView('pdf.carte-vaccination', [
            'patient' => $patient,
            'vaccinations' => $patient->vaccinations()->orderBy('date_vaccination')->get(),
            'etablissement' => config('dpi.establishment_name', config('app.name')),
        ])->setPaper('a5');

        return $pdf->stream('vaccination_'.$patient->dossier_number.'.pdf');
    }

    private function autoriser(string ...$droits): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->hasAnyRole(['super_admin', 'directeur']) || collect($droits)->contains(fn ($d) => $user?->can($d)),
            403
        );
    }
}
