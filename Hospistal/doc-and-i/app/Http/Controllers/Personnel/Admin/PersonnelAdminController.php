<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Models\AbsenceMedecin;
use App\Models\AuthentificationPersonnel;
use App\Models\Partenaire;
use App\Models\PlageDisponibilite;
use App\Models\Specialite;
use App\Models\User;
use App\Services\Personnel\ComptePersonnelService;
use App\Services\Personnel\DisponibilitesMedecinService;
use App\Services\RendezVous\Affichage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/**
 * ADM-01, CPT-05, CPT-06 : comptes du personnel vus par l'administrateur.
 * Les règles (rôles créables, quatre yeux, rendez-vous, journal) sont dans
 * ComptePersonnelService ; ce contrôleur valide, appelle et affiche.
 *
 * Le lien d'activation est montré UNE fois : passé en donnée « flash » à la
 * page du compte, retiré de la session à l'affichage ; jamais dans une
 * adresse, jamais au journal.
 */
class PersonnelAdminController extends Controller
{
    private const CLE_LIEN = 'personnel.admin.lien_activation';

    public const ROLES = [
        'medecin' => 'Médecin', 'caisse' => 'Caisse', 'laboratoire' => 'Laboratoire', 'coordinateur' => 'Coordination',
        'support' => 'Support', 'admin_fonctionnel' => 'Administration fonctionnelle', 'admin_technique' => 'Administration technique',
        'auditeur' => 'Audit',
    ];

    public const ETATS = ['a_activer' => 'À activer', 'actif' => 'Actif', 'desactive' => 'Désactivé'];

    public function __construct(private readonly ComptePersonnelService $comptes) {}

    public function index(Request $request): Response
    {
        $filtres = $request->validate([
            'role' => ['nullable', Rule::in(array_keys(self::ROLES))],
            'etat' => ['nullable', Rule::in(array_keys(self::ETATS))],
        ]) + ['role' => null, 'etat' => null];

        $page = self::personnel()
            ->when($filtres['role'], fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($filtres['etat'], fn ($q, $etat) => self::filtrerEtat($q, $etat))
            ->with('roles')->orderBy('nom')->orderBy('prenom')
            ->paginate(50)->withQueryString();

        $auth = AuthentificationPersonnel::whereIn('user_id', $page->pluck('id'))->get()->keyBy('user_id');

        return Inertia::render('Personnel/Admin/Personnel/Liste', [
            'filtres' => $filtres,
            'roles' => self::ROLES,
            'etats' => self::ETATS,
            'personnel' => $page->through(fn (User $u) => [
                'id' => $u->id,
                'nom' => self::nom($u),
                'email' => $u->email,
                'roles' => $u->roles->pluck('name')->map(fn ($r) => self::ROLES[$r] ?? $r)->values(),
                'etat' => self::ETATS[self::etat($u, $auth->get($u->id))],
                'derniereConnexion' => self::quand($auth->get($u->id)?->derniere_connexion_le),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Personnel/Admin/Personnel/Nouveau', [
            'roles' => array_intersect_key(self::ROLES, array_flip(ComptePersonnelService::ROLES_CREABLES_PAR_ECRAN)),
            'specialites' => Specialite::where('active', true)->orderBy('ordre')->get(['slug', 'nom']),
            'laboratoires' => Partenaire::where('type', 'laboratoire')->where('actif', true)->orderBy('nom')->get(['id', 'nom', 'commune']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $valides = $request->validate([
            'role' => ['required', 'string', 'max:30'],
            'email' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'specialite' => ['nullable', 'string', 'max:100'],
            'ordre' => ['nullable', 'string', 'max:50'],
            'partenaire_id' => ['nullable', 'string', 'max:40'],
        ]);

        [$user, $lien] = $this->comptes->creerParAdministrateur($request->user(), $valides['role'], $valides);

        return $this->versFicheAvecLien($user, $lien, 'Compte créé. Transmettez le lien d\'activation à la personne seule.');
    }

    public function show(Request $request, string $user): ReponseHttp
    {
        $u = self::personnel()->with(['roles', 'medecin.specialite'])->findOrFail($user);
        $auth = AuthentificationPersonnel::pour($u);
        $flash = $request->session()->pull(self::CLE_LIEN);

        $reponse = Inertia::render('Personnel/Admin/Personnel/Fiche', [
            'compte' => [
                'id' => $u->id,
                'nom' => self::nom($u),
                'email' => $u->email,
                'telephone' => $u->telephone,
                'roles' => $u->roles->pluck('name')->map(fn ($r) => self::ROLES[$r] ?? $r)->values(),
                'etat' => self::etat($u, $auth),
                'etatLibelle' => self::ETATS[self::etat($u, $auth)],
                'derniereConnexion' => self::quand($auth?->derniere_connexion_le),
                'desactiveLe' => self::quand($u->desactive_le),
                'motifDesactivation' => $u->motif_desactivation,
                'laboratoire' => DB::table('comptes_laboratoire')->join('partenaires', 'partenaires.id', '=', 'comptes_laboratoire.partenaire_id')
                    ->where('comptes_laboratoire.user_id', $u->id)->value('partenaires.nom'),
                'estSoiMeme' => $u->is($request->user()),
                'estAdministrateur' => ComptePersonnelService::estHorsEcran($u),
            ],
            // Montré une fois : retiré de la session ci-dessus.
            'lienActivation' => is_array($flash) && ($flash['user_id'] ?? null) === $u->id ? $flash['lien'] : null,
            'medecin' => $u->medecin ? $this->medecin($u) : null,
            'specialites' => $u->medecin ? Specialite::where('active', true)->orderBy('ordre')->get(['slug', 'nom']) : [],
        ])->toResponse($request);

        // Lien d'activation ou planning : jamais dans un cache.
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    public function emettreLien(Request $request, string $user): RedirectResponse
    {
        $u = self::personnel()->findOrFail($user);
        $lien = $this->comptes->emettreLienParAdministrateur($u, $request->user());

        return $this->versFicheAvecLien($u, $lien, 'Nouveau lien d\'activation : l\'ancien ne fonctionne plus.');
    }

    public function desactiver(Request $request, string $user): RedirectResponse
    {
        $u = self::personnel()->findOrFail($user);
        $valides = $request->validate([
            'motif' => ['required', 'string', 'max:255'],
            'annuler_rendez_vous' => ['nullable', 'boolean'],
        ], ['motif.required' => 'Le motif de la désactivation est obligatoire.']);

        $annules = $this->comptes->desactiver($u, $request->user(), $valides['motif'], (bool) ($valides['annuler_rendez_vous'] ?? false));

        return redirect()->route('personnel.admin.personnel.fiche', $u->id)->with('succes', $annules > 0
            ? "Compte désactivé ; {$annules} rendez-vous annulé(s) avec remboursement intégral, patients prévenus."
            : 'Compte désactivé : sa session est fermée.');
    }

    public function reactiver(Request $request, string $user): RedirectResponse
    {
        $u = self::personnel()->findOrFail($user);
        $valides = $request->validate(['motif_reactivation' => ['required', 'string', 'max:255']],
            ['motif_reactivation.required' => 'Le motif de la réactivation est obligatoire.']);

        $lien = $this->comptes->reactiver($u, $request->user(), $valides['motif_reactivation']);

        return $lien !== null
            ? $this->versFicheAvecLien($u, $lien, 'Compte réactivé. Il n\'avait jamais été activé : transmettez ce nouveau lien à la personne seule.')
            : redirect()->route('personnel.admin.personnel.fiche', $u->id)->with('succes', 'Compte réactivé.');
    }

    /** Le lien voyage en donnée « flash », jamais dans l'adresse de redirection. */
    private function versFicheAvecLien(User $user, string $lien, string $message): RedirectResponse
    {
        session()->flash(self::CLE_LIEN, ['user_id' => $user->id, 'lien' => $lien]);

        return redirect()->route('personnel.admin.personnel.fiche', $user->id)->with('succes', $message);
    }

    /** Planning et rendez-vous à venir du médecin : nombres par statut, ni motif ni patient. */
    private function medecin(User $u): array
    {
        $m = $u->medecin;
        $avenir = $m->rendezVous()->where('debut', '>', now())->toBase()
            ->selectRaw('statut, COUNT(*) AS nombre')->groupBy('statut')->pluck('nombre', 'statut');

        return [
            'numeroOrdre' => $m->numero_ordre,
            'ordreVerifieLe' => self::quand($m->ordre_verifie_le),
            'specialite' => $m->specialite->slug,
            'specialiteNom' => $m->specialite->nom,
            'presentation' => $m->presentation,
            'reservable' => $m->estReservable() && ! $u->estDesactive(),
            'plages' => $m->plages()->orderBy('jour_semaine')->orderBy('heure_debut')->get()->map(fn (PlageDisponibilite $p) => [
                'id' => $p->id,
                'jour' => DisponibilitesMedecinService::JOURS[$p->jour_semaine],
                'heures' => substr($p->heure_debut, 0, 5).' – '.substr($p->heure_fin, 0, 5),
            ])->all(),
            'absences' => $m->absences()->where('fin', '>', now())->orderBy('debut')->get()->map(fn (AbsenceMedecin $a) => [
                'id' => $a->id,
                'periode' => 'Du '.Affichage::dateHeure($a->debut).' au '.Affichage::dateHeure($a->fin),
            ])->all(),
            'rendezVous' => collect(StatutRendezVous::cases())
                ->filter(fn (StatutRendezVous $s) => ($avenir[$s->value] ?? 0) > 0)
                ->map(fn (StatutRendezVous $s) => ['statut' => $s->value, 'libelle' => self::libelleStatut($s), 'nombre' => (int) $avenir[$s->value]])
                ->values()->all(),
            'confirmesAVenir' => (int) ($avenir[StatutRendezVous::Confirme->value] ?? 0),
        ];
    }

    /** Les comptes du personnel seulement : un patient n'a pas de fiche ici (404). */
    private static function personnel(): Builder
    {
        return User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', array_keys(self::ROLES)));
    }

    private static function filtrerEtat(Builder $q, string $etat): Builder
    {
        $actives = AuthentificationPersonnel::whereNotNull('active_le')->whereNotNull('totp_secret')->select('user_id');

        return match ($etat) {
            'desactive' => $q->whereNotNull('desactive_le'),
            'actif' => $q->whereNull('desactive_le')->whereIn('id', $actives),
            default => $q->whereNull('desactive_le')->whereNotIn('id', $actives),
        };
    }

    private static function etat(User $u, ?AuthentificationPersonnel $auth): string
    {
        return match (true) {
            $u->estDesactive() => 'desactive',
            (bool) $auth?->estActive() => 'actif',
            default => 'a_activer',
        };
    }

    private static function libelleStatut(StatutRendezVous $s): string
    {
        return match ($s) {
            StatutRendezVous::AnnuleParPatient => 'Annulés par le patient',
            StatutRendezVous::AnnuleParMedecin => 'Annulés par le médecin',
            default => $s->libelle(),
        };
    }

    private static function nom(?User $u): ?string
    {
        return $u ? trim(mb_strtoupper((string) $u->nom).' '.$u->prenom) : null;
    }

    private static function quand(?\DateTimeInterface $instant): ?string
    {
        return $instant ? Affichage::dateHeure($instant) : null;
    }
}
