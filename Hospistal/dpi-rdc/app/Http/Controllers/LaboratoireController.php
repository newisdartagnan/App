<?php

namespace App\Http\Controllers;

use App\Models\ExamenFichier;
use App\Models\ExamenLaboratoire;
use App\Models\Patient;
use App\Models\TypeExamen;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\LaboratoireService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LaboratoireController extends Controller
{
    public function index(Request $request): View
    {
        $domaine = $request->get('domaine', 'labo');

        $examens = ExamenLaboratoire::with(['patient', 'prescripteur', 'resultats.typeExamen'])
            ->where('domaine', $domaine)
            ->orderByDesc('date_prescription')
            ->paginate(20)
            ->withQueryString();

        return view('labo.index', compact('examens', 'domaine'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->visit_id) {
            return redirect()->route('visites.index')
                ->with('info', 'Ouvrez le parcours patient pour prescrire des examens.');
        }

        $visit = Visit::with('patient')->findOrFail($request->visit_id);
        $domaine = $request->get('domaine', 'labo');

        $types = TypeExamen::where('est_actif', true)
            ->when($domaine === 'imagerie', fn ($q) => $q->where('code', 'like', 'IMG-%'))
            ->when($domaine === 'labo', fn ($q) => $q->where('code', 'not like', 'IMG-%'))
            ->orderBy('categorie')
            ->orderBy('libelle')
            ->get();

        return view('labo.create', compact('visit', 'types', 'domaine'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
            'domaine' => 'required|in:labo,imagerie',
            'types' => 'required|array|min:1',
            'types.*' => 'uuid|exists:types_examens,id',
        ]);

        $visit = Visit::findOrFail($request->visit_id);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — aucun nouvel examen possible.');
        }

        $examen = app(LaboratoireService::class)->prescrireExamens(
            $visit,
            $request->types,
            $request->domaine,
            $request->boolean('urgence'),
            $request->observations,
            $request->input('parametres', [])
        );

        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Examens prescrits — facture émise au guichet.');
    }

    public function show(ExamenLaboratoire $examen): View
    {
        $examen->load(['patient', 'visit', 'prescripteur', 'resultats.typeExamen', 'facture']);

        return view('labo.show', compact('examen'));
    }

    public function saisirResultats(Request $request, ExamenLaboratoire $examen): RedirectResponse
    {
        // Hospitalisation : le patient est servi à crédit durant le séjour,
        // tout est réglé avant la sortie. Sinon : paiement guichet d'abord.
        $aCredit = $examen->visit?->serviACredit();

        if (! $aCredit && $examen->facture && $examen->facture->statut !== 'payee') {
            return back()->with('error', 'Paiement guichet requis avant saisie des résultats.');
        }

        $request->validate(['resultats' => 'required|array']);

        app(LaboratoireService::class)->saisirResultats($examen, $request->resultats);

        return back()->with('success', 'Résultats enregistrés.');
    }

    public function valider(Request $request, ExamenLaboratoire $examen): RedirectResponse
    {
        $examen->update([
            'technique' => $request->input('technique') ?: $examen->technique,
            'recommandations' => $request->input('recommandations') ?: $examen->recommandations,
        ]);

        app(LaboratoireService::class)->valider($examen, $request->input('conclusion'));

        return back()->with('success', $examen->domaine === 'imagerie'
            ? 'Compte-rendu validé.'
            : 'Bilan validé par le biologiste.');
    }

    /**
     * Réouvre un bilan validé pour correction (bouton « Modifier »).
     */
    public function rouvrir(ExamenLaboratoire $examen): RedirectResponse
    {
        if ($examen->statut !== 'valide') {
            return back()->with('error', 'Seul un bilan validé peut être rouvert.');
        }

        $examen->update(['statut' => 'resultat_disponible']);

        return back()->with('success', 'Bilan rouvert — corrigez puis validez à nouveau.');
    }

    /**
     * Fichiers joints à un examen (photos, images, vidéos, PDF — imagerie surtout).
     */
    public function ajouterFichier(Request $request, ExamenLaboratoire $examen): RedirectResponse
    {
        $request->validate([
            'fichier' => 'required|file|max:51200|mimes:jpg,jpeg,png,gif,webp,mp4,webm,avi,pdf,dcm',
            'description' => 'nullable|string|max:255',
        ], [
            'fichier.required' => 'Choisissez un fichier.',
            'fichier.max' => 'Fichier trop lourd (max 50 Mo).',
        ]);

        $fichier = $request->file('fichier');
        $chemin = $fichier->store('examens/'.$examen->id, 'public');

        $extension = strtolower($fichier->getClientOriginalExtension());
        $type = match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']) => 'image',
            in_array($extension, ['mp4', 'webm', 'avi']) => 'video',
            $extension === 'pdf' => 'pdf',
            default => 'autre',
        };

        ExamenFichier::create([
            'examen_id' => $examen->id,
            'nom_original' => $fichier->getClientOriginalName(),
            'chemin' => $chemin,
            'type' => $type,
            'description' => $request->input('description'),
            'ajoute_par' => auth()->id(),
        ]);

        return back()->with('success', 'Fichier ajouté au dossier d\'examen.');
    }

    /**
     * Bulletin du jour : TOUS les résultats de bilans du patient pour une
     * date donnée, sur un seul document (mis à jour à chaque ajout).
     */
    public function bulletinJour(Request $request, Patient $patient): View
    {
        $date = $request->query('date', now()->toDateString());

        // Un bulletin appartient à un seul plateau technique : au laboratoire
        // il porte les analyses et la signature du biologiste, en imagerie les
        // comptes rendus et celle du radiologue. Les mélanger revenait à faire
        // contresigner par le biologiste des examens qu'il n'a pas lus.
        $domaine = $request->query('domaine') === 'imagerie' ? 'imagerie' : 'labo';

        $examens = ExamenLaboratoire::with(['resultats.typeExamen', 'prescripteur', 'laborantin'])
            ->where('patient_id', $patient->id)
            ->where('domaine', $domaine)
            ->whereDate('date_prescription', $date)
            ->orderBy('date_prescription')
            ->get();

        return view('labo.bulletin-jour', compact('patient', 'examens', 'date', 'domaine'));
    }

    /**
     * Rapport journalier et registre par unité d'analyse
     * (modèle CSK modules/labo/rapport.php).
     *
     * Le registre liste ligne par ligne chaque résultat du jour avec le nom
     * du médecin prescripteur et celui du laborantin qui l'a analysé.
     */
    public function rapport(Request $request): View
    {
        $date = $request->query('date', now()->toDateString());
        $domaine = $request->query('domaine', 'labo');

        $examens = ExamenLaboratoire::with([
            'resultats.typeExamen', 'patient', 'facture.lignes',
            'prescripteur', 'laborantin',
        ])
            ->where('domaine', $domaine)
            ->whereDate('date_prescription', $date)
            ->orderBy('date_prescription')
            ->get();

        // Registre journalier : une ligne par examen réalisé, groupé par
        // unité d'analyse (catégorie), comme le registre papier du labo.
        $registre = $examens
            ->flatMap(function (ExamenLaboratoire $examen) {
                return $examen->resultats
                    ->groupBy('type_examen_id')
                    ->map(function ($resultats) use ($examen) {
                        $type = $resultats->first()->typeExamen;
                        $ligneFacture = $examen->facture?->lignes
                            ->firstWhere('reference_id', $type->id);

                        // Panel prescrit partiellement : le noter au registre
                        $totalParametres = count($type->valeurs_reference['parametres'] ?? []);
                        $partiel = $totalParametres > 1 && $resultats->count() < $totalParametres
                            ? $resultats->count().'/'.$totalParametres.' sous-examens'
                            : null;

                        return [
                            'categorie' => $type->uniteAnalyse(),
                            'examen' => $examen,
                            'type' => $type,
                            'partiel' => $partiel,
                            'resultats' => $resultats,
                            'montant' => (float) ($ligneFacture->total_ligne ?? 0),
                            'heure' => $examen->date_resultat ?? $examen->date_prescription,
                        ];
                    })
                    ->values();
            })
            ->groupBy('categorie')
            ->sortKeys();

        $interpretations = $examens->flatMap->resultats->pluck('interpretation')->filter();

        $stats = [
            'total' => $examens->count(),
            'valides' => $examens->where('statut', 'valide')->count(),
            'en_cours' => $examens->whereNotIn('statut', ['valide', 'annule'])->count(),
            'urgents' => $examens->where('urgence', true)->count(),
            'critiques' => $interpretations->filter(fn ($i) => $i === 'critique')->count(),
            'recettes' => $examens->pluck('facture')->filter()->unique('id')
                ->where('statut', 'payee')->sum('total_ttc'),
        ];

        $stats['taux_completion'] = $stats['total'] > 0
            ? round($stats['valides'] / $stats['total'] * 100, 1)
            : 0;

        // Activité par laborantin : nombre de bilans traités dans la journée
        $parLaborantin = $examens->whereNotNull('laborantin_id')
            ->groupBy('laborantin_id')
            ->map(fn ($groupe) => [
                'nom' => trim(($groupe->first()->laborantin->prenom ?? '').' '.($groupe->first()->laborantin->nom ?? '')),
                'bilans' => $groupe->count(),
                'examens' => $groupe->flatMap->resultats->unique('type_examen_id')->count(),
            ])
            ->sortByDesc('bilans');

        return view('labo.rapport', compact(
            'date', 'domaine', 'examens', 'registre', 'stats', 'parLaborantin'
        ));
    }

    /**
     * Bon d'examen imprimable (remis au patient pour la caisse / le préleveur).
     */
    public function bon(ExamenLaboratoire $examen): View
    {
        $examen->load(['patient', 'visit', 'prescripteur', 'resultats.typeExamen', 'facture']);

        return view('labo.bon', compact('examen'));
    }

    /**
     * Bulletin de résultats (labo) ou compte-rendu (imagerie) imprimable.
     */
    public function bulletin(ExamenLaboratoire $examen): View
    {
        $examen->load(['patient', 'visit', 'prescripteur', 'laborantin', 'resultats.typeExamen', 'facture', 'fichiers']);

        return view('labo.bulletin', compact('examen'));
    }

    /**
     * Résultats en PDF, destinés au médecin prescripteur.
     *
     * C'est la destination des notifications « résultats disponibles » : le
     * prescripteur reçoit son document sans entrer dans le laboratoire ni dans
     * l'imagerie, services auxquels il n'a pas nécessairement accès. Les
     * pièces jointes suivent — les images sont incorporées, les vidéos et les
     * fichiers DICOM sont annoncés puisqu'ils ne s'impriment pas.
     */
    public function pdfResultat(ExamenLaboratoire $examen): Response
    {
        $this->autoriserLecture($examen);

        $examen->load(['patient.assurances.assurance', 'prescripteur', 'laborantin',
            'resultats.typeExamen', 'fichiers']);

        $pdf = Pdf::loadView('pdf.resultat-examen', [
            'examen' => $examen,
            'estImagerie' => $examen->domaine === 'imagerie',
            'etablissement' => config('dpi.establishment_name', config('app.name')),
            'pieces' => $this->piecesJointes($examen),
        ])->setPaper('a4');

        $nom = ($examen->domaine === 'imagerie' ? 'CR_' : 'RES_').$examen->numero_bon.'.pdf';

        // Affiché dans le navigateur : la notification ouvre le document,
        // elle ne déclenche pas un téléchargement.
        return $pdf->stream($nom);
    }

    /**
     * Pièces jointes préparées pour le PDF.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function piecesJointes(ExamenLaboratoire $examen)
    {
        return $examen->fichiers->map(function ($fichier) {
            $chemin = Storage::disk('public')->path($fichier->chemin);
            $lisible = $fichier->type === 'image' && is_file($chemin);

            return [
                'nom' => $fichier->nom_original,
                'description' => $fichier->description,
                'date' => $fichier->created_at?->format('d/m/Y H:i') ?? '—',
                'image' => $lisible ? $chemin : null,
                'mention' => match ($fichier->type) {
                    'video' => 'Séquence vidéo — consultable dans le dossier du patient.',
                    'pdf' => 'Document PDF joint — consultable dans le dossier du patient.',
                    'image' => 'Image indisponible sur le serveur.',
                    default => 'Fichier DICOM ou pièce technique — consultable dans le dossier du patient.',
                },
            ];
        });
    }

    /**
     * Qui peut lire ce document.
     *
     * Le prescripteur, toujours. Le plateau technique qui l'a produit. La
     * direction. Les autres médecins de l'établissement, parce qu'un confrère
     * de garde reprend le dossier.
     */
    private function autoriserLecture(ExamenLaboratoire $examen): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (
                $user->id === $examen->prescripteur_id
                || $user->hasAnyRole(['super_admin', 'directeur', 'medecin',
                    'infirmier_chef', 'laborantin', 'radiologue'])
            ),
            403,
            'Ce document est réservé au médecin prescripteur et à l\'équipe soignante.'
        );
    }
}
