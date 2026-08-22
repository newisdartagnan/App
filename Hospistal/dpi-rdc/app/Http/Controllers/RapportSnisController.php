<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Services\RapportSnisService;
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
    public function __construct(private readonly RapportSnisService $snis) {}

    public function index(Request $request): View
    {
        $this->autoriser();

        [$annee, $mois] = $this->periode($request);

        return view('snis.rapport', [
            'annee' => $annee,
            'mois' => $mois,
            'rapport' => $this->snis->rapport($annee, $mois, $this->etablissementId()),
            'etablissement' => $this->etablissement(),
            'moisDisponibles' => $this->moisDisponibles(),
        ]);
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

        return view('snis.imprimable', [
            'rapport' => $this->snis->rapport($annee, $mois, $this->etablissementId()),
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
