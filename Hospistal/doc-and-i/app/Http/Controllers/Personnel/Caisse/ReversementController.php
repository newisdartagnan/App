<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Models\Medecin;
use App\Models\Reversement;
use App\Services\Caisse\ReversementService;
use App\Services\Paiement\PaiementRefuse;
use App\Services\RendezVous\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** PAY-09 : reversements aux médecins, préparés puis payés par deux agents différents. */
class ReversementController extends ControleurCaisse
{
    public function __construct(private readonly ReversementService $reversements) {}

    public function index(Request $request): Response
    {
        try {
            $commission = ReversementService::commissionEnCentiemesDePourcent() / 100;
        } catch (PaiementRefuse) {
            $commission = null;
        }

        $moi = $request->user()->id;

        return Inertia::render('Personnel/Caisse/Reversements', [
            'commissionPourcent' => $commission,
            'medecins' => Medecin::with('user')->get()->map(fn (Medecin $m) => ['id' => $m->id, 'nom' => Affichage::medecin($m)])->sortBy('nom')->values()->all(),
            'reversements' => array_map(
                // Quatre yeux : pas de bouton « Payer » pour qui l'a préparé.
                fn (array $r) => array_diff_key($r, ['preparePar' => true]) + ['peutPayer' => ! $r['paye'] && $r['preparePar'] !== $moi],
                $this->reversements->lister(),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $valides = $request->validate([
            'medecin' => ['required', 'uuid', Rule::exists('medecins', 'id')],
            'debut' => ['required', 'date_format:Y-m-d'],
            'fin' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->action(function () use ($valides, $request) {
            $prepares = $this->reversements->preparer(
                Medecin::findOrFail($valides['medecin']),
                CarbonImmutable::parse($valides['debut'], 'Africa/Kinshasa'),
                CarbonImmutable::parse($valides['fin'], 'Africa/Kinshasa'),
                $request->user(),
            );

            return count($prepares).' reversement(s) préparé(s) : un autre agent de caisse doit les payer.';
        }, 'medecin', 'Reversement préparé.');
    }

    public function payer(Request $request, string $reversement): RedirectResponse
    {
        $r = Reversement::findOrFail($reversement);
        $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return $this->action(
            fn () => $this->reversements->payer($r, $request->user(), $request->input('reference')),
            'reference', 'Reversement payé.',
        );
    }
}
