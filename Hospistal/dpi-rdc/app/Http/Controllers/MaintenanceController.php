<?php

namespace App\Http\Controllers;

use App\Jobs\WriteAuditLog;
use App\Models\User;
use App\Services\SauvegardeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Maintenance du serveur, sans y toucher physiquement.
 *
 * L'adresse à donner aux postes, les utilisateurs connectés (et les
 * déconnecter tous), les sauvegardes, le rafraîchissement de l'application,
 * et — caché derrière ?mode=dieu — le vidage des tables de démonstration
 * avant un passage en production.
 */
class MaintenanceController extends Controller
{
    /** Tables jamais proposées au vidage : comptes, droits, structure, journal. */
    public const TABLES_PROTEGEES = [
        'migrations', 'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions',
        'role_has_permissions', 'establishments', 'audit_logs', 'sessions', 'password_reset_tokens',
        'personal_access_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'parametres', 'services', 'lits', 'types_examens', 'medicaments', 'types_consultation',
    ];

    public function __construct(private readonly SauvegardeService $sauvegardes) {}

    public function index(Request $request): View
    {
        $this->autoriser();

        $sessionsBase = config('session.driver') === 'database';
        $connectes = $sessionsBase
            ? DB::table(config('session.table', 'sessions'))
                ->whereNotNull('user_id')
                ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->timestamp)
                ->orderByDesc('last_activity')
                ->get()
            : collect();
        $utilisateurs = User::whereIn('id', $connectes->pluck('user_id')->unique())->get()->keyBy('id');

        $modeDieu = $request->query('mode') === 'dieu' && config('dpi.vidage_autorise');

        return view('maintenance.index', [
            'adresse' => $request->getSchemeAndHttpHost(),
            'appUrl' => config('app.url'),
            'sessionsBase' => $sessionsBase,
            'connectes' => $connectes,
            'utilisateurs' => $utilisateurs,
            'sauvegardes' => $this->sauvegardes->liste(),
            'modeDieu' => $modeDieu,
            'tables' => $modeDieu ? $this->tablesVidables() : [],
        ]);
    }

    public function deconnecterTous(Request $request): RedirectResponse
    {
        $this->autoriser();

        if (config('session.driver') !== 'database') {
            return back()->with('error', 'Les sessions ne sont pas gardées en base : déconnexion groupée impossible.');
        }

        $n = DB::table(config('session.table', 'sessions'))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $request->user()->id))
            ->delete();
        // Le « se souvenir de moi » rouvrirait aussitôt la session.
        User::where('id', '!=', $request->user()->id)->update(['remember_token' => null]);

        WriteAuditLog::dispatch(action: 'auth.deconnexion_forcee', tableName: 'sessions');

        return back()->with('success', "{$n} session(s) fermée(s). Vous restez connecté(e).");
    }

    public function sauvegarder(): RedirectResponse
    {
        $this->autoriser();
        $resultat = $this->sauvegardes->sauvegarder();
        WriteAuditLog::dispatch(action: 'maintenance.sauvegarde', newValues: $resultat);

        return back()->with($resultat['ok'] ? 'success' : 'error', $resultat['message']);
    }

    public function telecharger(string $nom): BinaryFileResponse
    {
        $this->autoriser();
        $chemin = $this->sauvegardes->chemin($nom);
        abort_unless($chemin, 404);

        return response()->download($chemin);
    }

    /** Équivalent sûr du « Redémarrer » : caches vidés, files relancées. */
    public function rafraichir(): RedirectResponse
    {
        $this->autoriser();
        Artisan::call('optimize:clear');
        Artisan::call('queue:restart');

        return back()->with('success', 'Caches vidés et files de travail relancées.');
    }

    public function vider(Request $request): RedirectResponse
    {
        $this->autoriser();
        abort_unless(config('dpi.vidage_autorise'), 403, 'Vidage désactivé sur ce serveur.');

        $vidables = $this->tablesVidables();
        $donnees = $request->validate([
            'tables' => 'required|array|min:1',
            'tables.*' => ['string', \Illuminate\Validation\Rule::in($vidables)],
            'confirmation' => 'required|in:VIDER',
        ], [
            'tables.required' => 'Sélectionnez au moins une table.',
            'confirmation.in' => 'Tapez VIDER en majuscules pour confirmer.',
            'confirmation.required' => 'Tapez VIDER en majuscules pour confirmer.',
        ]);

        // Pas de CASCADE : on ne vide que ce qui est coché. Si une autre
        // table en dépend, PostgreSQL refuse et on dit laquelle cocher.
        $liste = implode(', ', array_map(fn ($t) => '"'.str_replace('"', '', $t).'"', $donnees['tables']));
        try {
            DB::statement("TRUNCATE TABLE {$liste} RESTRICT");
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', 'Vidage refusé : d\'autres tables dépendent de celles-ci. Cochez-les aussi. ('.str($e->getPrevious()?->getMessage())->limit(250).')');
        }

        WriteAuditLog::dispatch(action: 'maintenance.vidage', newValues: ['tables' => $donnees['tables']]);

        return redirect()->route('maintenance.index', ['mode' => 'dieu'])
            ->with('success', count($donnees['tables']).' table(s) vidée(s).');
    }

    /** @return array<int, string> */
    private function tablesVidables(): array
    {
        return collect(DB::select("select tablename from pg_tables where schemaname = current_schema() order by tablename"))
            ->pluck('tablename')
            ->reject(fn ($t) => in_array($t, self::TABLES_PROTEGEES, true) || str_starts_with($t, 'telescope_'))
            ->values()
            ->all();
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasRole('super_admin') || auth()->user()?->can('maintenance.manage'),
            403,
            'Maintenance réservée à l\'administrateur.'
        );
    }
}
