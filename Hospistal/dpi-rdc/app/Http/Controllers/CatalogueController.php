<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Lit;
use App\Models\Local;
use App\Models\Service;
use App\Models\TypeExamen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Le paramétrage de l'établissement, sans passer par la base de données :
 * examens de laboratoire (avec paramètres et valeurs de référence),
 * techniques d'imagerie, services, locaux de service et lits.
 *
 * Rien ne se supprime : un examen, un service ou un lit déjà utilisé reste
 * dans l'historique des dossiers. On le désactive, il ne se propose plus.
 */
class CatalogueController extends Controller
{
    // ── Examens de laboratoire et techniques d'imagerie ─────────────────

    public function examens(Request $request, string $domaine = 'labo'): View
    {
        $this->autoriserPlateau();
        $recherche = trim((string) $request->query('recherche', ''));

        $types = TypeExamen::where('domaine', $domaine)
            ->when($recherche !== '', fn ($q) => $q->whereRaw('LOWER(libelle) LIKE ?', ['%'.mb_strtolower($recherche).'%']))
            ->orderBy('categorie')->orderBy('libelle')
            ->paginate(30)->withQueryString();

        return view('catalogue.examens', compact('types', 'domaine', 'recherche'));
    }

    public function imagerie(Request $request): View
    {
        return $this->examens($request, 'imagerie');
    }

    public function creerExamen(string $domaine = 'labo'): View
    {
        $this->autoriserPlateau();

        return view('catalogue.examen-form', ['type' => new TypeExamen(['domaine' => $domaine, 'est_actif' => true]), 'domaine' => $domaine]);
    }

    public function creerImagerie(): View
    {
        return $this->creerExamen('imagerie');
    }

    public function modifierExamen(TypeExamen $type): View
    {
        $this->autoriserPlateau();

        return view('catalogue.examen-form', ['type' => $type, 'domaine' => $type->domaine]);
    }

    public function enregistrerExamen(Request $request, ?TypeExamen $type = null): RedirectResponse
    {
        $this->autoriserPlateau();
        $type ??= new TypeExamen;
        $domaine = $type->exists ? $type->domaine : ($request->input('domaine') === 'imagerie' ? 'imagerie' : 'labo');

        $donnees = $request->validate([
            'libelle' => 'required|string|max:255',
            'code' => ['nullable', 'string', 'max:50', Rule::unique('types_examens', 'code')->ignore($type->id)],
            'categorie' => ['required', Rule::in(['hematologie', 'biochimie', 'microbiologie', 'serologie', 'parasitologie', 'anatomopathologie', 'imagerie', 'autre'])],
            'modalite' => ['nullable', Rule::in(array_keys(TypeExamen::MODALITES))],
            'prix' => 'nullable|numeric|min:0',
            'delai_heures' => 'nullable|integer|min:0|max:2000',
            'fonction_tarifaire' => 'nullable|integer|min:1|max:'.\App\Models\CategorieTarifaire::NB_FONCTIONS,
            'est_actif' => 'nullable|boolean',
            'parametres' => 'nullable|array',
            'parametres.*.nom' => 'nullable|string|max:150',
            'parametres.*.unite' => 'nullable|string|max:30',
            'parametres.*.*.min' => 'nullable|numeric',
            'parametres.*.*.max' => 'nullable|numeric',
        ], ['libelle.required' => 'Donnez la désignation de l\'examen.']);

        $code = $donnees['code'] ?? null;
        if (! $code) {
            $prefixe = $domaine === 'imagerie' ? 'IMG-' : '';
            $code = $prefixe.Str::upper(Str::limit(Str::slug($donnees['libelle'], ''), 12, ''));
            $base = $code;
            for ($i = 2; TypeExamen::where('code', $code)->where('id', '!=', $type->id)->exists(); $i++) {
                $code = $base.$i;
            }
        }

        $valeurs = [];
        if ($domaine === 'labo') {
            $parametres = collect($request->input('parametres', []))
                ->filter(fn ($p) => filled($p['nom'] ?? null))
                ->map(function ($p) {
                    $ligne = ['nom' => trim($p['nom']), 'unite' => trim((string) ($p['unite'] ?? ''))];
                    foreach (['homme', 'femme', 'enfant'] as $cible) {
                        $min = $p[$cible]['min'] ?? null;
                        $max = $p[$cible]['max'] ?? null;
                        if ($min !== null && $min !== '' || $max !== null && $max !== '') {
                            $ligne[$cible] = [
                                'min' => $min === '' || $min === null ? null : (float) $min,
                                'max' => $max === '' || $max === null ? null : (float) $max,
                            ];
                        }
                    }

                    return $ligne;
                })->values()->all();
            $valeurs = ['parametres' => $parametres];
        }

        $type->fill([
            'domaine' => $domaine,
            'code' => $code,
            'libelle' => $donnees['libelle'],
            'categorie' => $domaine === 'imagerie' ? 'imagerie' : $donnees['categorie'],
            'modalite' => $domaine === 'imagerie' ? ($donnees['modalite'] ?? 'autre') : null,
            'prix' => $donnees['prix'] ?? 0,
            'delai_heures' => $donnees['delai_heures'] ?? ($domaine === 'imagerie' ? 24 : 4),
            'est_actif' => $request->boolean('est_actif', ! $type->exists || $type->est_actif),
            'fonction_tarifaire' => $donnees['fonction_tarifaire'] ?? null,
            'valeurs_reference' => $valeurs,
        ])->save();

        return redirect()->route($domaine === 'imagerie' ? 'catalogue.imagerie.index' : 'catalogue.examens.index')
            ->with('success', "« {$type->libelle} » enregistré.");
    }

    public function basculerExamen(TypeExamen $type): RedirectResponse
    {
        $this->autoriserPlateau();
        $type->update(['est_actif' => ! $type->est_actif]);

        return back()->with('success', "« {$type->libelle} » ".($type->est_actif ? 'réactivé' : 'désactivé').'.');
    }

    // ── Services ─────────────────────────────────────────────────────────

    public function services(): View
    {
        $this->autoriserParametrage();
        $services = Service::withCount(['locaux', 'lits'])->orderBy('nom')->get();

        return view('catalogue.services', compact('services'));
    }

    public function creerService(): View
    {
        $this->autoriserParametrage();

        return view('catalogue.service-form', ['service' => new Service(['is_active' => true, 'type' => 'medecine'])]);
    }

    public function modifierService(Service $service): View
    {
        $this->autoriserParametrage();

        return view('catalogue.service-form', compact('service'));
    }

    public function enregistrerService(Request $request, ?Service $service = null): RedirectResponse
    {
        $this->autoriserParametrage();
        $service ??= new Service;
        $etab = $service->establishment_id ?? $this->etablissement();

        $donnees = $request->validate([
            'nom' => 'required|string|max:255',
            'code' => ['nullable', 'string', 'max:20',
                Rule::unique('services', 'code')->where('establishment_id', $etab)->ignore($service->id)],
            'type' => ['required', Rule::in(['urgence', 'medecine', 'chirurgie', 'maternite', 'pediatrie', 'labo', 'pharmacie', 'autre'])],
            'capacite_lits' => 'nullable|integer|min:0|max:2000',
        ], ['nom.required' => 'Donnez le nom du service.']);

        $code = ($donnees['code'] ?? null) ?: Str::upper(Str::limit(Str::slug($donnees['nom'], ''), 8, ''));
        $base = $code;
        for ($i = 2; Service::where('establishment_id', $etab)->where('code', $code)->where('id', '!=', $service->id)->exists(); $i++) {
            $code = Str::limit($base, 6, '').$i;
        }

        $service->fill([
            'establishment_id' => $etab,
            'nom' => $donnees['nom'],
            'code' => $code,
            'type' => $donnees['type'],
            'capacite_lits' => $donnees['capacite_lits'] ?? 0,
            'is_active' => $request->boolean('is_active', ! $service->exists || $service->is_active),
        ])->save();

        return redirect()->route('catalogue.services.index')->with('success', "Service « {$service->nom} » enregistré.");
    }

    public function basculerService(Service $service): RedirectResponse
    {
        $this->autoriserParametrage();
        $service->update(['is_active' => ! $service->is_active]);

        return back()->with('success', "Service « {$service->nom} » ".($service->is_active ? 'réactivé' : 'désactivé').'.');
    }

    // ── Locaux de services ───────────────────────────────────────────────

    public function locaux(Request $request): View
    {
        $this->autoriserParametrage();
        $serviceId = (string) $request->query('service', '');
        $locaux = Local::with('service')->withCount('lits')
            ->when($serviceId !== '', fn ($q) => $q->where('service_id', $serviceId))
            ->orderBy('nom')->get()
            ->sortBy(fn ($l) => $l->service?->nom.' '.$l->nom)->values();
        $services = Service::orderBy('nom')->get(['id', 'nom']);

        return view('catalogue.locaux', compact('locaux', 'services', 'serviceId'));
    }

    public function creerLocal(Request $request): View
    {
        $this->autoriserParametrage();

        return view('catalogue.local-form', [
            'local' => new Local(['type' => 'salle', 'is_active' => true, 'service_id' => $request->query('service')]),
            'services' => Service::where('is_active', true)->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function modifierLocal(Local $local): View
    {
        $this->autoriserParametrage();

        return view('catalogue.local-form', [
            'local' => $local,
            'services' => Service::orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function enregistrerLocal(Request $request, ?Local $local = null): RedirectResponse
    {
        $this->autoriserParametrage();
        $local ??= new Local;

        $donnees = $request->validate([
            'service_id' => 'required|uuid|exists:services,id',
            'nom' => ['required', 'string', 'max:100',
                Rule::unique('locaux', 'nom')->where('service_id', $request->input('service_id'))->ignore($local->id)],
            'type' => ['required', Rule::in(array_keys(Local::TYPES))],
            'etage' => 'nullable|string|max:30',
        ], ['nom.unique' => 'Ce service a déjà un local de ce nom.']);

        $local->fill($donnees + [
            'establishment_id' => Service::find($donnees['service_id'])->establishment_id,
            'is_active' => $request->boolean('is_active', ! $local->exists || $local->is_active),
        ])->save();

        return redirect()->route('catalogue.locaux.index')->with('success', "Local « {$local->nom} » enregistré.");
    }

    public function basculerLocal(Local $local): RedirectResponse
    {
        $this->autoriserParametrage();
        $local->update(['is_active' => ! $local->is_active]);

        return back()->with('success', "Local « {$local->nom} » ".($local->is_active ? 'réactivé' : 'désactivé').'.');
    }

    // ── Lits ─────────────────────────────────────────────────────────────

    public function lits(Request $request): View
    {
        $this->autoriserLits();
        $serviceId = (string) $request->query('service', '');
        $lits = Lit::with(['service', 'local'])
            ->when($serviceId !== '', fn ($q) => $q->where('service_id', $serviceId))
            ->orderBy('numero')->get()
            ->sortBy(fn ($l) => $l->service?->nom.' '.$l->local?->nom.' '.str_pad($l->numero, 10, '0', STR_PAD_LEFT))->values();
        $services = Service::orderBy('nom')->get(['id', 'nom']);

        return view('catalogue.lits', compact('lits', 'services', 'serviceId'));
    }

    public function creerLit(Request $request): View
    {
        $this->autoriserLits();

        return view('catalogue.lit-form', [
            'lit' => new Lit(['statut' => 'libre', 'is_active' => true, 'service_id' => $request->query('service')]),
            'services' => Service::where('is_active', true)->orderBy('nom')->get(['id', 'nom']),
            'locaux' => Local::with('service')->where('is_active', true)->orderBy('nom')->get(),
        ]);
    }

    public function modifierLit(Lit $lit): View
    {
        $this->autoriserLits();

        return view('catalogue.lit-form', [
            'lit' => $lit,
            'services' => Service::orderBy('nom')->get(['id', 'nom']),
            'locaux' => Local::with('service')->orderBy('nom')->get(),
        ]);
    }

    public function enregistrerLit(Request $request, ?Lit $lit = null): RedirectResponse
    {
        $this->autoriserLits();
        $lit ??= new Lit;
        $service = Service::findOrFail($request->input('service_id'));

        $donnees = $request->validate([
            'service_id' => 'required|uuid|exists:services,id',
            'local_id' => ['nullable', 'uuid', Rule::exists('locaux', 'id')->where('service_id', $request->input('service_id'))],
            'numero' => ['required', 'string', 'max:20',
                Rule::unique('lits', 'numero')->where('establishment_id', $service->establishment_id)->ignore($lit->id)],
            'statut' => ['nullable', Rule::in(['libre', 'occupe', 'maintenance', 'reserve'])],
        ], [
            'numero.unique' => 'Ce numéro de lit existe déjà dans l\'établissement.',
            'local_id.exists' => 'Ce local n\'appartient pas au service choisi.',
        ]);

        $lit->fill([
            'service_id' => $service->id,
            'establishment_id' => $service->establishment_id,
            'local_id' => $donnees['local_id'] ?? null,
            'numero' => $donnees['numero'],
            // Un lit occupé ne se libère pas depuis le paramétrage : c'est la
            // sortie du patient qui le libère.
            'statut' => $lit->statut === 'occupe' ? 'occupe' : ($donnees['statut'] ?? 'libre'),
            'is_active' => $request->boolean('is_active', ! $lit->exists || $lit->is_active),
        ])->save();

        return redirect()->route('catalogue.lits.index')->with('success', "Lit {$lit->numero} enregistré.");
    }

    public function basculerLit(Lit $lit): RedirectResponse
    {
        $this->autoriserLits();
        if ($lit->statut === 'occupe' && $lit->is_active) {
            return back()->with('error', 'Ce lit est occupé : il ne peut pas être désactivé tant que le patient y est.');
        }
        $lit->update(['is_active' => ! $lit->is_active]);

        return back()->with('success', "Lit {$lit->numero} ".($lit->is_active ? 'réactivé' : 'désactivé').'.');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function etablissement(): string
    {
        return auth()->user()->establishment_id ?? Establishment::orderBy('created_at')->value('id');
    }

    private function autoriserParametrage(): void
    {
        abort_unless(auth()->user()?->can('settings.manage') || auth()->user()?->hasAnyRole(['super_admin', 'directeur']), 403);
    }

    private function autoriserPlateau(): void
    {
        abort_unless(
            auth()->user()?->can('settings.manage') || auth()->user()?->can('labo.validate')
                || auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403
        );
    }

    private function autoriserLits(): void
    {
        abort_unless(
            auth()->user()?->can('settings.manage') || auth()->user()?->can('soin.execute')
                || auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403
        );
    }
}
