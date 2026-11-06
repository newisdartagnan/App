<?php

namespace App\Http\Controllers\RendezVous;

use App\Http\Controllers\Controller;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\Specialite;
use App\Models\TypeConsultation;
use App\Services\RendezVous\Affichage;
use App\Services\RendezVous\DemandeConsultationService;
use App\Services\RendezVous\QuestionnaireTri;
use App\Services\RendezVous\ReservationRefusee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** RDV-01 (demande), RDV-02 (tri, urgences), RDV-03 (choix du médecin). */
class DemandeController extends Controller
{
    use ControleDuPatient;

    public function creer(): Response
    {
        return Inertia::render('RendezVous/Demande', [
            'questions' => QuestionnaireTri::questions(),
            'durees' => collect(DemandeConsultationService::DUREES)
                ->map(fn (string $libelle, string $valeur) => ['valeur' => $valeur, 'libelle' => $libelle])
                ->values(),
            'piecesMax' => DemandeConsultationService::PIECES_MAX,
            'tailleMaxMo' => intdiv(DemandeConsultationService::TAILLE_MAX_KO, 1024),
            'extensions' => DemandeConsultationService::EXTENSIONS,
        ]);
    }

    public function enregistrer(Request $request, DemandeConsultationService $service): RedirectResponse
    {
        $request->merge(['motif' => trim((string) $request->input('motif'))]);

        $donnees = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'symptomes' => ['nullable', 'string', 'max:2000'],
            'depuis' => ['nullable', 'string', Rule::in(array_keys(DemandeConsultationService::DUREES))],
            'pieces' => ['nullable', 'array', 'max:'.DemandeConsultationService::PIECES_MAX],
            'pieces.*' => [
                'file',
                'max:'.DemandeConsultationService::TAILLE_MAX_KO,
                // Type lu dans le contenu du fichier (finfo), puis extension du nom.
                'mimetypes:'.implode(',', DemandeConsultationService::TYPES_MIME),
                'extensions:'.implode(',', DemandeConsultationService::EXTENSIONS),
            ],
            ...QuestionnaireTri::regles(),
        ], [
            'motif.required' => 'Dites en quelques mots pourquoi vous consultez.',
            'motif.max' => 'Le motif est trop long : 255 caractères au plus.',
            'symptomes.max' => 'Le texte est trop long : 2000 caractères au plus.',
            'depuis.in' => 'Choisissez une des réponses proposées.',
            'pieces.max' => 'Vous pouvez joindre '.DemandeConsultationService::PIECES_MAX.' fichiers au plus.',
            'pieces.*.file' => 'Ce fichier n\'a pas pu être envoyé. Réessayez.',
            'pieces.*.uploaded' => 'Ce fichier n\'a pas pu être envoyé : il est peut-être trop gros.',
            'pieces.*.max' => 'Ce fichier est trop gros : '.intdiv(DemandeConsultationService::TAILLE_MAX_KO, 1024).' Mo au plus.',
            'pieces.*.mimetypes' => 'Seules les photos (JPEG, PNG, WebP) et les PDF sont acceptés.',
            'pieces.*.extensions' => 'Seules les photos (JPEG, PNG, WebP) et les PDF sont acceptés.',
            ...QuestionnaireTri::messages(),
        ]);

        try {
            $demande = $service->creer($request->user(), $donnees, $request->file('pieces', []));
        } catch (ReservationRefusee $refus) {
            // Quota de pièces jointes : la demande peut repartir sans fichier.
            return back()->withErrors(['pieces' => $refus->getMessage()]);
        }

        return $demande->tri_alerte
            ? redirect()->route('rdv.demande.urgence', $demande)
            : redirect()->route('rdv.demande.choix', $demande);
    }

    public function urgence(Request $request, DemandeConsultation $demande): Response|RedirectResponse
    {
        $this->assurerProprietaire($request, $demande);

        if ($demande->statut !== 'orientee_urgence') {
            return redirect()->route('rdv.demande.choix', $demande);
        }

        $etablissement = config('doc-and-i.urgences.etablissement');
        $telephone = config('doc-and-i.urgences.telephone');

        return Inertia::render('RendezVous/Urgence', [
            'etablissement' => $etablissement,
            'telephone' => $telephone,
            'consigne' => filled($etablissement)
                ? "Rendez-vous immédiatement aux urgences : {$etablissement}."
                : 'Rendez-vous immédiatement à l\'hôpital le plus proche.',
        ]);
    }

    public function choix(Request $request, DemandeConsultation $demande): Response|RedirectResponse
    {
        $this->assurerProprietaire($request, $demande);

        if ($redirection = $this->redirectionSiNonReservable($demande)) {
            return $redirection;
        }

        // Les médecins d'une spécialité désactivée par l'administration ne sont pas proposés (ADM-01).
        $medecins = Medecin::reservables()->whereHas('specialite', fn ($q) => $q->where('active', true))->with('user')->get();

        return Inertia::render('RendezVous/Choix', [
            'demande' => ['id' => $demande->id, 'motif' => $demande->motif],
            'specialites' => Specialite::where('active', true)
                ->orderBy('ordre')->orderBy('nom')
                ->with(['typesConsultation' => fn ($q) => $q->where('actif', true)->orderBy('duree_minutes')->orderBy('nom')])
                ->get()
                ->map(fn (Specialite $s) => [
                    'id' => $s->id,
                    'nom' => $s->nom,
                    'types' => $s->typesConsultation->map(fn (TypeConsultation $t) => [
                        'id' => $t->id,
                        'nom' => $t->nom,
                        'duree_minutes' => $t->duree_minutes,
                        'prix' => Affichage::montant($t->prix, $t->devise),
                        // Un type sans prix fixé par le client ne se réserve pas.
                        'reservable' => $t->prix !== null,
                    ])->values(),
                    'nombre_medecins' => $medecins->where('specialite_id', $s->id)->count(),
                ]),
            'medecins' => $medecins
                ->sortBy(fn (Medecin $m) => mb_strtolower((string) $m->user?->nom))
                ->map(fn (Medecin $m) => [
                    'id' => $m->id,
                    'nom' => Affichage::medecin($m),
                    'specialite_id' => $m->specialite_id,
                    'langues' => $m->langues ?? [],
                    'presentation' => $m->presentation,
                ])->values(),
            'erreur' => session('errors')?->first('choix'),
        ]);
    }
}
