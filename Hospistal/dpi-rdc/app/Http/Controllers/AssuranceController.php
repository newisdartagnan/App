<?php

namespace App\Http\Controllers;

use App\Models\Assurance;
use App\Models\AssuranceCouverture;
use App\Models\Forfait;
use App\Models\TarifConvention;
use App\Models\TypeConsultation;
use App\Models\TypeDiete;
use App\Models\TypeExamen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Sociétés et mutuelles conventionnées : contrat, modalités de règlement et
 * règles de couverture acte par acte.
 *
 * Le moteur de couverture existait déjà — `couvreActe()` et `tauxPourActe()`
 * interrogeaient la table des règles — mais rien ne permettait de les saisir.
 */
class AssuranceController extends Controller
{
    public function index(): View
    {
        $this->autoriser();

        return view('assurances.index', [
            'assurances' => Assurance::withCount(['couvertures', 'patientAssurances'])
                ->orderBy('nom')
                ->get(),
            'modes' => Assurance::MODES_REGLEMENT,
            'periodicites' => Assurance::PERIODICITES,
        ]);
    }

    public function show(Assurance $assurance): View
    {
        $this->autoriser();

        $assurance->load([
            'couvertures' => fn ($q) => $q->orderBy('type'),
            'tarifs' => fn ($q) => $q->orderBy('type')->orderBy('reference_libelle'),
        ]);

        return view('assurances.show', [
            'assurance' => $assurance,
            'prestations' => $this->prestationsTarifables(),
            'modes' => Assurance::MODES_REGLEMENT,
            'periodicites' => Assurance::PERIODICITES,
            'categories' => Forfait::CATEGORIES,
            'forfaits' => Forfait::where('assurance_id', $assurance->id)->orderBy('libelle')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autoriser();

        $donnees = $this->valider($request);

        if (Assurance::where('code', strtoupper($donnees['code']))->exists()) {
            return back()->withInput()->with('error', 'Une société porte déjà le code '.strtoupper($donnees['code']).'.');
        }

        $assurance = Assurance::create([
            ...$donnees,
            'code' => strtoupper($donnees['code']),
            'est_actif' => true,
        ]);

        return redirect()->route('assurances.show', $assurance)
            ->with('success', 'Convention « '.$assurance->nom.' » enregistrée. Définissez maintenant ses règles de couverture.');
    }

    public function update(Request $request, Assurance $assurance): RedirectResponse
    {
        $this->autoriser();

        $donnees = $this->valider($request, $assurance);

        $assurance->update([...$donnees, 'code' => strtoupper($donnees['code'])]);

        return back()->with('success', 'Contrat de « '.$assurance->nom.' » mis à jour.');
    }

    public function basculer(Assurance $assurance): RedirectResponse
    {
        $this->autoriser();

        $assurance->update(['est_actif' => ! $assurance->est_actif]);

        return back()->with('success', $assurance->est_actif
            ? 'Convention « '.$assurance->nom.' » réactivée.'
            : 'Convention « '.$assurance->nom.'  » suspendue — le tiers payant ne s\'appliquera plus.');
    }

    /**
     * Ajoute ou remplace une règle de couverture.
     *
     * Sans règle sur un type d'acte, la convention couvre au taux global du
     * contrat. Une règle permet d'exclure une catégorie, ou de lui appliquer
     * un taux négocié différent.
     */
    public function ajouterCouverture(Request $request, Assurance $assurance): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'type' => ['required', Rule::in(array_keys(Forfait::CATEGORIES))],
            'couvert' => 'required|boolean',
            'taux_specifique' => 'nullable|numeric|min:0|max:100',
            'reference_libelle' => 'nullable|string|max:255',
        ]);

        // Une règle qui exclut un acte ne peut pas porter de taux : les deux
        // ensemble donneraient une couverture à 0 % annoncée comme couverte.
        if (! $donnees['couvert'] && filled($donnees['taux_specifique'] ?? null)) {
            return back()->with('error',
                'Un acte exclu ne porte pas de taux : retirez le taux, ou marquez l\'acte comme couvert.');
        }

        AssuranceCouverture::updateOrCreate(
            [
                'assurance_id' => $assurance->id,
                'type' => $donnees['type'],
                'reference_id' => null,
            ],
            [
                'couvert' => (bool) $donnees['couvert'],
                'taux_specifique' => $donnees['couvert'] ? (($donnees['taux_specifique'] ?? null) ?: null) : null,
                'reference_libelle' => ($donnees['reference_libelle'] ?? null) ?: null,
            ]
        );

        $libelle = Forfait::CATEGORIES[$donnees['type']] ?? $donnees['type'];

        return back()->with('success', $donnees['couvert']
            ? 'Règle enregistrée : '.$libelle.' couvert'
                .(filled($donnees['taux_specifique'] ?? null)
                    ? ' à '.($donnees['taux_specifique'] + 0).' %.'
                    : ' au taux du contrat.')
            : 'Règle enregistrée : '.$libelle.' exclu de la prise en charge.');
    }

    /**
     * Fixe le prix négocié d'une prestation — ou de toute une catégorie,
     * pour la journée d'hospitalisation et la diète.
     */
    public function ajouterTarif(Request $request, Assurance $assurance): RedirectResponse
    {
        $this->autoriser();

        $prestations = $this->prestationsTarifables();

        $donnees = $request->validate([
            'prestation' => ['required', Rule::in(array_keys($prestations))],
            'prix' => 'required|numeric|min:0',
        ], [
            'prestation.in' => 'Choisissez une prestation de la liste.',
            'prix.required' => 'Indiquez le prix négocié.',
        ]);

        [$type, $reference] = explode(':', $donnees['prestation']) + [1 => ''];
        $libelle = $prestations[$donnees['prestation']];

        // PostgreSQL tient deux NULL pour différents : la règle de catégorie
        // se retrouve à la main plutôt que par l'index unique.
        $tarif = TarifConvention::where('assurance_id', $assurance->id)
            ->where('type', $type)
            ->when($reference === '', fn ($q) => $q->whereNull('reference_id'), fn ($q) => $q->where('reference_id', $reference))
            ->first() ?? new TarifConvention(['assurance_id' => $assurance->id, 'type' => $type, 'reference_id' => $reference ?: null]);

        $tarif->fill(['reference_libelle' => $libelle, 'prix' => $donnees['prix']])->save();

        return back()->with('success', $libelle.' : '.number_format((float) $donnees['prix'], 0, ',', ' ')
            .' CDF pour les bénéficiaires de « '.$assurance->nom.' ».');
    }

    public function supprimerTarif(TarifConvention $tarif): RedirectResponse
    {
        $this->autoriser();

        $tarif->delete();

        return back()->with('success', 'Prix négocié retiré : la prestation revient au tarif de la convention.');
    }

    /**
     * Ce qui peut recevoir un prix négocié : les catégories facturées à la
     * journée, et chaque consultation, examen ou diète du catalogue. Les
     * médicaments suivent le coefficient du contrat.
     *
     * @return array<string, string> « type:référence » → libellé
     */
    private function prestationsTarifables(): array
    {
        $liste = [
            'hospitalisation:' => 'Journée d\'hospitalisation (toutes)',
            'consultation:' => 'Consultation (toutes)',
        ];

        foreach (TypeConsultation::orderBy('libelle')->get() as $t) {
            $liste['consultation:'.$t->id] = 'Consultation — '.$t->libelle;
        }

        foreach (TypeExamen::where('est_actif', true)->orderBy('domaine')->orderBy('libelle')->get() as $t) {
            $type = $t->domaine === 'imagerie' ? 'imagerie' : 'examen_labo';
            $liste[$type.':'.$t->id] = ($t->domaine === 'imagerie' ? 'Imagerie — ' : 'Examen — ').$t->libelle;
        }

        foreach (TypeDiete::orderBy('libelle')->get() as $t) {
            $liste['diete:'.$t->id] = 'Diète — '.$t->libelle;
        }

        return $liste;
    }

    public function supprimerCouverture(AssuranceCouverture $couverture): RedirectResponse
    {
        $this->autoriser();

        $couverture->delete();

        return back()->with('success', 'Règle retirée — cette catégorie revient au taux du contrat.');
    }

    /** @return array<string, mixed> */
    private function valider(Request $request, ?Assurance $assurance = null): array
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:150',
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('assurances', 'code')->ignore($assurance?->id),
            ],
            'taux_couverture' => 'required|numeric|min:0|max:100',
            'ticket_moderateur' => 'nullable|numeric|min:0|max:100',
            'plafond_annuel_cdf' => 'nullable|numeric|min:0',
            'plafond_annuel_usd' => 'nullable|numeric|min:0',
            'delai_reglement_jours' => 'required|integer|min:0|max:365',
            'mode_reglement' => ['required', Rule::in(array_keys(Assurance::MODES_REGLEMENT))],
            'periodicite_facturation' => ['required', Rule::in(array_keys(Assurance::PERIODICITES))],
            'contact_nom' => 'nullable|string|max:150',
            'contact_telephone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:150',
            'notes' => 'nullable|string|max:2000',
            'contrat_debut' => 'nullable|date',
            'contrat_fin' => 'nullable|date|after_or_equal:contrat_debut',
            'exige_bon_prise_en_charge' => 'nullable|boolean',
            'coefficient_pct' => 'nullable|numeric|min:-90|max:500',
        ], [
            'contrat_fin.after_or_equal' => 'Le contrat ne peut finir avant d\'avoir commencé.',
            'code.unique' => 'Ce code est déjà attribué à une autre convention.',
            'taux_couverture.max' => 'Le taux de couverture ne peut pas dépasser 100 %.',
        ]);

        // Le coefficient se saisit comme on le dit : « public + 20 % ».
        $donnees['exige_bon_prise_en_charge'] = $request->boolean('exige_bon_prise_en_charge');
        $donnees['coefficient_tarif'] = round(1 + (float) ($donnees['coefficient_pct'] ?? 0) / 100, 4);
        unset($donnees['coefficient_pct']);

        return $donnees;
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'caissier', 'chef_facturation']),
            403,
            'Gestion des conventions réservée à la direction, à la facturation et à la caisse.'
        );
    }
}
