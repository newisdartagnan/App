<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntreeJournalAdministration;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\RendezVous\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** ADM-02 : journal d'administration, en lecture seule, paginé et filtrable. */
class JournalAdminController extends Controller
{
    /** Libellés des actions connues ; une action inconnue s'affiche telle quelle. */
    private const ACTIONS = [
        'personnel.compte_cree' => 'Compte créé',
        'personnel.lien_activation_emis' => 'Lien d\'activation émis',
        'personnel.compte_desactive' => 'Compte désactivé',
        'personnel.compte_reactive' => 'Compte réactivé',
        'medecin.ordre_verifie' => 'Inscription à l\'Ordre vérifiée',
        'medecin.profil_modifie' => 'Profil du médecin modifié',
        'rendez_vous.annule_par_administration' => 'Rendez-vous annulé par l\'administration',
    ];

    public function __invoke(Request $request): Response
    {
        $filtres = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'auteur' => ['nullable', 'uuid'],
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
        ]) + ['action' => null, 'auteur' => null, 'du' => null, 'au' => null];

        $page = EntreeJournalAdministration::with('auteur')
            ->when($filtres['action'], fn ($q, $a) => $q->where('action', $a))
            ->when($filtres['auteur'], fn ($q, $a) => $q->where('auteur_id', $a))
            ->when($filtres['du'], fn ($q, $d) => $q->where('cree_le', '>=', CarbonImmutable::parse($d, 'Africa/Kinshasa')->startOfDay()))
            ->when($filtres['au'], fn ($q, $d) => $q->where('cree_le', '<=', CarbonImmutable::parse($d, 'Africa/Kinshasa')->endOfDay()))
            ->orderByDesc('cree_le')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        // Noms des comptes visés, chargés en une fois ; un rendez-vous ne montre que sa date.
        $comptes = User::whereIn('id', $page->where('cible_type', 'User')->pluck('cible_id'))->get()->keyBy('id');
        $rendezVous = RendezVous::whereIn('id', $page->where('cible_type', 'RendezVous')->pluck('cible_id'))->get()->keyBy('id');

        $actions = EntreeJournalAdministration::query()->distinct()->orderBy('action')->pluck('action');

        return Inertia::render('Personnel/Admin/Journal', [
            'filtres' => $filtres,
            'actions' => $actions->map(fn ($a) => ['valeur' => $a, 'libelle' => self::ACTIONS[$a] ?? $a])->values(),
            'auteurs' => User::whereIn('id', EntreeJournalAdministration::query()->distinct()->select('auteur_id'))->orderBy('nom')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'nom' => self::nom($u)])->values(),
            'entrees' => $page->through(fn (EntreeJournalAdministration $e) => [
                'id' => $e->id,
                'quand' => Affichage::dateHeure($e->cree_le),
                'auteur' => self::nom($e->auteur),
                'action' => self::ACTIONS[$e->action] ?? $e->action,
                'cible' => match ($e->cible_type) {
                    'User' => self::nom($comptes->get($e->cible_id)),
                    'RendezVous' => ($rdv = $rendezVous->get($e->cible_id)) ? 'Rendez-vous du '.Affichage::dateHeure($rdv->debut) : 'Rendez-vous',
                    null => null,
                    default => $e->cible_type,
                },
                'details' => collect($e->details ?? [])->map(fn ($v, $k) => ['cle' => str_replace('_', ' ', (string) $k), 'valeur' => is_bool($v) ? ($v ? 'oui' : 'non') : (string) $v])->values(),
            ]),
        ]);
    }

    private static function nom(?User $u): ?string
    {
        return $u ? trim(mb_strtoupper((string) $u->nom).' '.$u->prenom) : null;
    }
}
