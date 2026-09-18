<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Services\RapportSnisService;
use App\Services\SystemeSanteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le rapport mensuel remonté à la zone de santé.
 *
 * Il se remplissait à la main, registre après registre, une journée entière
 * par mois. Chacun de ses chiffres était déjà en base.
 */
class RapportSnisController extends Controller
{
    public function __construct(
        private readonly RapportSnisService $snis,
        private readonly SystemeSanteService $systemes,
    ) {}

    public function index(Request $request): View
    {
        $this->autoriser();

        [$annee, $mois] = $this->periode($request);
        $rapport = $this->snis->rapport($annee, $mois, $this->etablissementId());

        return view('snis.rapport', [
            'annee' => $annee,
            'mois' => $mois,
            'rapport' => $rapport,
            'numeros' => $this->snis->numeros($rapport),
            'systemes' => SystemeSanteService::SYSTEMES,
            'systemeRetenu' => $this->systemes->systeme(),
            'peutChoisirLeSysteme' => $this->peutChoisirLeSysteme(),
            'etablissement' => $this->etablissement(),
            'moisDisponibles' => $this->moisDisponibles(),
        ]);
    }

    /**
     * Le canevas que suit l'établissement.
     *
     * Il se choisit une fois, à l'installation, et ne change plus : il décide
     * de ce que le rapport mensuel remonte à la zone de santé. D'où la
     * réserve à la direction — un rapport qui change de forme d'un mois sur
     * l'autre n'est plus comparable.
     */
    public function definirSysteme(Request $request): RedirectResponse
    {
        abort_unless($this->peutChoisirLeSysteme(), 403,
            'Le canevas du rapport engage l\'établissement : seule la direction en décide.');

        $valide = $request->validate([
            'systeme' => ['required', 'string', 'in:'.implode(',', array_keys(SystemeSanteService::SYSTEMES))],
        ]);

        $this->systemes->definir($valide['systeme']);

        $definition = SystemeSanteService::SYSTEMES[$valide['systeme']];

        return redirect()
            ->route('snis.index', $request->only('annee', 'mois'))
            ->with('success', 'Le rapport suit désormais le canevas « '
                .$definition['nom'].' » ('.$definition['sigle'].').');
    }

    /** Le même rapport en tableur, prêt à remonter. */
    public function csv(Request $request): StreamedResponse
    {
        $this->autoriser();

        [$annee, $mois] = $this->periode($request);

        $rapport = $this->snis->rapport($annee, $mois, $this->etablissementId());
        $contenu = $this->snis->versCsv($rapport, $this->etablissement());

        $nom = sprintf('SNIS_%s_%04d-%02d.csv',
            preg_replace('/[^A-Za-z0-9]+/', '-', $this->etablissement()), $annee, $mois);

        return response()->streamDownload(
            fn () => print ($contenu),
            $nom,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** Version imprimable, pour la classer au registre. */
    public function imprimer(Request $request): View|Response
    {
        $this->autoriser();

        [$annee, $mois] = $this->periode($request);

        $rapport = $this->snis->rapport($annee, $mois, $this->etablissementId());

        return view('snis.imprimable', [
            'rapport' => $rapport,
            'numeros' => $this->snis->numeros($rapport),
            'etablissement' => $this->etablissement(),
        ]);
    }

    /** @return array{int, int} */
    private function periode(Request $request): array
    {
        // Par défaut le mois écoulé : c'est celui qu'on remonte, pas le mois
        // en cours qui n'est pas terminé.
        $defaut = now()->subMonthNoOverflow();

        $annee = (int) $request->query('annee', $defaut->year);
        $mois = (int) $request->query('mois', $defaut->month);

        return [
            max(2000, min(2100, $annee)),
            max(1, min(12, $mois)),
        ];
    }

    /** Les douze derniers mois, du plus récent au plus ancien. */
    private function moisDisponibles(): array
    {
        return collect(range(0, 11))
            ->map(fn (int $recul) => now()->subMonthsNoOverflow($recul + 1))
            ->map(fn ($date) => [
                'annee' => $date->year,
                'mois' => $date->month,
                'libelle' => ucfirst($date->translatedFormat('F Y')),
            ])
            ->all();
    }

    private function etablissement(): string
    {
        return Establishment::find($this->etablissementId())?->name
            ?? config('dpi.establishment_name', config('app.name'));
    }

    private function etablissementId(): ?string
    {
        return auth()->user()?->establishment_id
            ?? Establishment::orderBy('created_at')->value('id');
    }

    /** Régler le canevas est un acte d'installation, pas de saisie mensuelle. */
    private function peutChoisirLeSysteme(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super_admin', 'directeur']);
    }

    /** Le rapport engage l'établissement devant sa zone de santé. */
    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'infirmier_chef', 'agent_admin']),
            403,
            'Rapport mensuel réservé à la direction et à l\'administration.'
        );
    }
}
