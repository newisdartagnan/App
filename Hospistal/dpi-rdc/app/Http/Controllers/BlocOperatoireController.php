<?php

namespace App\Http\Controllers;

use App\Models\ActeClinique;
use App\Models\Establishment;
use App\Models\KitOperatoire;
use App\Models\SalleOperation;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bloc opératoire : du programme préopératoire au registre.
 *
 * Une intervention passe par trois mains. Le chirurgien la demande — c'est le
 * programme préopératoire. Le bloc la planifie : une salle, une heure, une
 * équipe. L'équipe la clôture : ce qui a été fait, par qui, avec quel kit,
 * de quelle heure à quelle heure. Le registre garde tout.
 */
class BlocOperatoireController extends Controller
{
    /** Domaines d'actes qui passent par le bloc. */
    private const DOMAINES = ['chirurgie', 'maternite'];

    /**
     * Programme préopératoire : les demandes, planifiées ou non.
     */
    public function programme(Request $request): View
    {
        $debut = $request->query('debut', now()->toDateString());
        $fin = $request->query('fin', now()->addWeek()->toDateString());
        $vue = $request->query('vue') === 'planifiees' ? 'planifiees' : 'preoperatoire';
        $mesDemandes = $request->boolean('mes_demandes');

        $interventions = ActeClinique::with(['patient', 'visit.service', 'operateur', 'anesthesiste', 'demandeur', 'salle'])
            ->whereIn('domaine', self::DOMAINES)
            ->where('statut', $vue === 'planifiees' ? 'planifie' : 'prescrit')
            // Une demande sans date d'échéance reste visible : elle attend
            // justement qu'on lui en donne une.
            ->where(fn ($q) => $q->whereNull('date_prevue')
                ->orWhereBetween('date_prevue', [$debut.' 00:00:00', $fin.' 23:59:59']))
            ->when($mesDemandes, fn ($q) => $q->where(fn ($sub) => $sub
                ->where('demandeur_id', auth()->id())
                ->orWhere('prescripteur_id', auth()->id())
                ->orWhere('operateur_id', auth()->id())))
            ->orderByRaw('date_prevue IS NULL DESC')
            ->orderBy('date_prevue')
            ->get();

        return view('bloc.programme', [
            'interventions' => $interventions,
            // Le bloc doit pouvoir inscrire lui-même une demande : un
            // chirurgien qui passe annonce son intervention sur place.
            'catalogue' => array_merge(
                config('dpi.actes.chirurgie', []),
                config('dpi.actes.maternite', [])
            ),
            'sejoursOuverts' => Visit::with('patient', 'service')
                ->where('statut', 'en_cours')
                ->orderByDesc('date_entree')
                ->limit(300)
                ->get(),
            'debut' => $debut,
            'fin' => $fin,
            'vue' => $vue,
            'mesDemandes' => $mesDemandes,
            'salles' => $this->salles(),
            'chirurgiens' => $this->chirurgiens(),
            'anesthesistes' => $this->anesthesistes(),
            'anesthesies' => ActeClinique::ANESTHESIES,
        ]);
    }

    /**
     * Planifie une intervention : salle, créneau, équipe.
     *
     * La salle est la ressource rare du bloc : on refuse un créneau déjà pris,
     * sans quoi deux équipes se retrouvent devant la même porte.
     */
    public function planifier(Request $request, ActeClinique $acte): RedirectResponse
    {
        $donnees = $request->validate([
            'salle_id' => 'required|uuid|exists:salles_operation,id',
            'date_prevue' => 'required|date',
            'duree_minutes' => 'required|integer|min:15|max:1440',
            'operateur_id' => 'required|uuid|exists:users,id',
            'anesthesiste_id' => 'nullable|uuid|exists:users,id',
            'type_anesthesie' => ['nullable', Rule::in(array_keys(ActeClinique::ANESTHESIES))],
            'diagnostic_preop' => 'nullable|string|max:1000',
            'instrumentiste' => 'nullable|string|max:150',
        ], [
            'salle_id.required' => 'Choisissez la salle d\'opération.',
            'operateur_id.required' => 'Une intervention se programme avec son chirurgien.',
            'duree_minutes.required' => 'Indiquez la durée prévue : elle réserve le créneau.',
        ]);

        if (! $acte->visit?->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — programmation impossible.');
        }

        $salle = SalleOperation::findOrFail($donnees['salle_id']);
        $debut = Carbon::parse($donnees['date_prevue']);
        $fin = $debut->copy()->addMinutes((int) $donnees['duree_minutes']);

        $conflits = $salle->occupationEntre($debut, $fin, $acte->id);

        if ($conflits->isNotEmpty()) {
            $conflit = $conflits->first();

            return back()->with('error', sprintf(
                '%s est déjà occupée de %s à %s par « %s ». Choisissez un autre créneau ou une autre salle.',
                $salle->nom,
                $conflit->date_prevue->format('H:i'),
                $conflit->finPrevue()->format('H:i'),
                $conflit->libelle
            ));
        }

        $acte->update([
            ...$donnees,
            'statut' => 'planifie',
            'consentement' => $request->boolean('consentement'),
            'urgence' => $request->boolean('urgence'),
            'demandeur_id' => $acte->demandeur_id ?? $acte->prescripteur_id,
        ]);

        return back()->with('success', sprintf(
            '« %s » programmée le %s à %s en %s.',
            $acte->libelle,
            $debut->format('d/m/Y'),
            $debut->format('H:i'),
            $salle->nom
        ));
    }

    /**
     * Horaire du bloc : une semaine, salle par salle.
     */
    public function horaire(Request $request): View
    {
        $salles = $this->salles();
        $salleCourante = $salles->firstWhere('id', $request->query('salle')) ?? $salles->first();

        $lundi = Carbon::parse($request->query('semaine', now()->toDateString()))->startOfWeek();

        $interventions = ActeClinique::with(['patient', 'operateur', 'anesthesiste'])
            ->where('salle_id', $salleCourante?->id)
            ->whereIn('statut', ['planifie', 'realise'])
            ->whereBetween('date_prevue', [$lundi, $lundi->copy()->endOfWeek()])
            ->orderBy('date_prevue')
            ->get()
            ->groupBy(fn (ActeClinique $acte) => $acte->date_prevue->toDateString());

        return view('bloc.horaire', [
            'salles' => $salles,
            'salleCourante' => $salleCourante,
            'lundi' => $lundi,
            'interventions' => $interventions,
            // Le bloc tourne de 7 h à 20 h ; la nuit reste possible mais ne
            // s'affiche pas par défaut, elle noierait la grille.
            'heures' => range(7, 19),
        ]);
    }

    /**
     * Interventions programmées, en attente de clôture.
     */
    public function interventions(Request $request): View
    {
        $debut = $request->query('debut', now()->subWeek()->toDateString());
        $fin = $request->query('fin', now()->toDateString());

        return view('bloc.interventions', [
            'interventions' => ActeClinique::with(['patient', 'visit.service', 'operateur', 'anesthesiste', 'salle'])
                ->whereIn('domaine', self::DOMAINES)
                ->where('statut', 'planifie')
                ->whereBetween('date_prevue', [$debut.' 00:00:00', $fin.' 23:59:59'])
                ->orderBy('date_prevue')
                ->get(),
            'debut' => $debut,
            'fin' => $fin,
            'kits' => $this->kits(),
            'anesthesistes' => $this->anesthesistes(),
            'anesthesies' => ActeClinique::ANESTHESIES,
        ]);
    }

    /**
     * Clôture de l'intervention : le compte rendu opératoire.
     */
    public function cloturer(Request $request, ActeClinique $acte): RedirectResponse
    {
        $donnees = $request->validate([
            'heure_entree_salle' => 'required|date',
            'heure_sortie_salle' => 'required|date|after:heure_entree_salle',
            'diagnostic_postop' => 'nullable|string|max:1000',
            'compte_rendu' => 'required|string|max:5000',
            'incidents' => 'nullable|string|max:2000',
            'kits' => 'nullable|array',
            'kits.*' => 'uuid|exists:kits_operatoires,id',
            'anesthesiste_id' => 'nullable|uuid|exists:users,id',
            'type_anesthesie' => ['nullable', Rule::in(array_keys(ActeClinique::ANESTHESIES))],
        ], [
            'compte_rendu.required' => 'Le compte rendu opératoire est la pièce du dossier : il ne peut rester vide.',
            'heure_sortie_salle.after' => 'La sortie de salle ne peut précéder l\'entrée.',
        ]);

        if ($acte->statut === 'realise' || $acte->statut === 'facture') {
            return back()->with('info', 'Cette intervention est déjà clôturée.');
        }

        $acte->update([
            ...$donnees,
            'kits' => $donnees['kits'] ?? [],
            'statut' => 'realise',
            'date_realisation' => Carbon::parse($donnees['heure_sortie_salle']),
        ]);

        $duree = $acte->fresh()->dureeReelleMinutes();

        return back()->with('success', sprintf(
            'Intervention clôturée — %d minutes en salle. Elle passe au registre et devient facturable.',
            $duree ?? 0
        ));
    }

    /**
     * Registre du bloc opératoire.
     */
    public function registre(Request $request): View
    {
        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->toDateString());

        $interventions = ActeClinique::with([
            'patient.assurances.assurance', 'visit.service', 'operateur',
            'anesthesiste', 'salle', 'demandeur',
        ])
            ->whereIn('domaine', self::DOMAINES)
            ->whereIn('statut', ['realise', 'facture'])
            ->whereBetween('date_realisation', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->orderByDesc('date_realisation')
            ->get();

        return view('bloc.registre', [
            'interventions' => $interventions,
            'debut' => $debut,
            'fin' => $fin,
            // Chiffres de tête du registre : ce que la direction regarde.
            'parSalle' => $interventions->groupBy(fn ($a) => $a->salle?->nom ?? 'Non renseignée')->map->count()->sortDesc(),
            'parAnesthesie' => $interventions->groupBy(fn ($a) => $a->libelleAnesthesie())->map->count()->sortDesc(),
            'parChirurgien' => $interventions->whereNotNull('operateur_id')
                ->groupBy(fn ($a) => trim(($a->operateur?->nom ?? '').' '.($a->operateur?->prenom ?? '')))
                ->map->count()->sortDesc(),
            'dureeMoyenne' => $interventions->map->dureeReelleMinutes()->filter()->avg(),
        ]);
    }

    /** Feuille d'intervention imprimable, pour le dossier et pour la salle. */
    public function feuille(ActeClinique $acte): View
    {
        $acte->load(['patient.assurances.assurance', 'visit.service', 'operateur',
            'anesthesiste', 'demandeur', 'salle']);

        return view('bloc.feuille', ['acte' => $acte]);
    }

    /** Salles actives de l'établissement courant. */
    private function salles()
    {
        return SalleOperation::where('est_actif', true)
            ->when($this->etablissementId(), fn ($q, $id) => $q->where('establishment_id', $id))
            ->orderBy('code')
            ->get();
    }

    /** Kits disponibles, pour la clôture. */
    private function kits()
    {
        return KitOperatoire::where('est_actif', true)
            ->when($this->etablissementId(), fn ($q, $id) => $q->where('establishment_id', $id))
            ->orderBy('libelle')
            ->get();
    }

    private function chirurgiens()
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['medecin', 'super_admin']))
            ->orderBy('nom')
            ->get();
    }

    /**
     * Anesthésistes : les médecins dont la spécialité le dit. À défaut d'en
     * avoir déclaré, on propose tous les médecins — un bloc doit tourner.
     */
    private function anesthesistes()
    {
        $specialises = User::where('is_active', true)
            ->where('specialite', 'like', '%nesth%')
            ->orderBy('nom')
            ->get();

        return $specialises->isNotEmpty() ? $specialises : $this->chirurgiens();
    }

    private function etablissementId(): ?string
    {
        return auth()->user()?->establishment_id
            ?? Establishment::orderBy('created_at')->value('id');
    }
}
