<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\RapportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menu Rapports : patients, pharmacie, laboratoire, imagerie, caisse,
 * services, ambulances (le canevas SNIS a sa propre page). Par défaut sur le
 * mois en cours ; chaque rapport s'imprime et s'exporte en CSV.
 */
class RapportController extends Controller
{
    public const RAPPORTS = [
        'patients' => ['Patients', 'rapport.view'],
        'pharmacie' => ['Pharmacie', 'stock.view'],
        'laboratoire' => ['Laboratoire', 'labo.view'],
        'imagerie' => ['Imagerie', 'labo.view'],
        'caisse' => ['Caisse / Facturation', 'rapport.financier.view'],
        'services' => ['Services', 'rapport.view'],
        'ambulances' => ['Ambulances', 'ambulance.manage'],
    ];

    public function __construct(private readonly RapportService $rapports) {}

    public function index(Request $request): View
    {
        $disponibles = collect(self::RAPPORTS)->filter(fn ($r) => $this->peut($r[1]));
        abort_if($disponibles->isEmpty(), 403);

        return view('rapports.index', ['disponibles' => $disponibles]);
    }

    public function afficher(Request $request, string $rapport): View|Response
    {
        abort_unless(isset(self::RAPPORTS[$rapport]), 404);
        abort_unless($this->peut(self::RAPPORTS[$rapport][1]), 403);

        $du = $request->query('du') ?: now()->startOfMonth()->toDateString();
        $au = $request->query('au') ?: now()->toDateString();
        $serviceId = $request->query('service') ?: null;

        $donnees = match ($rapport) {
            'patients' => $this->rapports->patients($du, $au, $serviceId),
            'pharmacie' => $this->rapports->pharmacie($du, $au),
            'laboratoire' => $this->rapports->examens('labo', $du, $au),
            'imagerie' => $this->rapports->examens('imagerie', $du, $au),
            'caisse' => $this->rapports->caisse($du, $au),
            'services' => $this->rapports->services($du, $au),
            'ambulances' => $this->rapports->ambulances($du, $au),
        };

        if ($request->query('format') === 'csv') {
            return $this->csv($donnees, $rapport, $du, $au);
        }

        return view('rapports.tableau', [
            'rapport' => $rapport,
            'donnees' => $donnees,
            'du' => $du,
            'au' => $au,
            'serviceId' => $serviceId,
            'services' => $rapport === 'patients' ? Service::orderBy('nom')->get(['id', 'nom']) : collect(),
            'disponibles' => collect(self::RAPPORTS)->filter(fn ($r) => $this->peut($r[1])),
        ]);
    }

    public function caisseJour(string $jour): View
    {
        abort_unless($this->peut('rapport.financier.view'), 403);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour), 404);

        return view('rapports.caisse-jour', [
            'jour' => $jour,
            'factures' => $this->rapports->caisseJour($jour),
        ]);
    }

    private function csv(array $donnees, string $rapport, string $du, string $au): StreamedResponse
    {
        $nom = 'rapport_'.$rapport.'_'.$du.'_'.$au.'.csv';

        return response()->streamDownload(function () use ($donnees) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF"); // Excel lit l'UTF-8
            fputcsv($f, $donnees['colonnes'], ';');
            foreach ($donnees['lignes'] as $ligne) {
                fputcsv($f, array_values((array) $ligne), ';');
            }
            if (! empty($donnees['totaux'])) {
                fputcsv($f, $donnees['totaux'], ';');
            }
            fclose($f);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function peut(string $droit): bool
    {
        $u = auth()->user();

        return $u?->hasAnyRole(['super_admin', 'directeur']) || $u?->can($droit) || ($droit === 'rapport.view' && $u?->can('rapport.financier.view'));
    }
}
