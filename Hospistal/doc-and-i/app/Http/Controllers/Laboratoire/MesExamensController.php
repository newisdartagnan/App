<?php

namespace App\Http\Controllers\Laboratoire;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\BonExamen;
use App\Services\Laboratoire\BonsPatient;
use App\Services\Laboratoire\LaboratoireRefuse;
use App\Services\Laboratoire\PrescriptionExamens;
use App\Services\Laboratoire\ValidationResultats;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\PaiementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRE-04, PRE-05, PRE-07, RG-01 : « Mes examens », côté patient. Seulement
 * ses bons ; résultats VALIDÉS seulement. Le code de retrait n'est montré
 * qu'une fois, juste après sa remise (donnée flash de la session).
 */
class MesExamensController extends Controller
{
    public function __construct(private readonly BonsPatient $bons) {}

    public function index(Request $request): Response
    {
        $bons = $this->bons->liste($request->user());

        return Inertia::render('Laboratoire/MesExamens', [
            'bons' => array_map(fn (array $b) => $b + ['cle' => (string) Str::uuid()], $bons),
            'laboratoires' => PrescriptionExamens::laboratoiresActifs(),
            'moyens' => array_map(fn (MoyenPaiement $m) => ['valeur' => $m->value, 'libelle' => $m->libelle(), 'mobile_money' => $m->estMobileMoney()], MoyenPaiement::cases()),
            'telephone' => $request->user()->telephone ? '0'.substr($request->user()->telephone, 4) : '',
            // Une seule fois : juste après la remise.
            'codeRetrait' => $request->session()->get('code_retrait'),
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function laboratoire(Request $request, string $bon): RedirectResponse
    {
        $donnees = $request->validate(['laboratoire_id' => ['required', 'uuid']], ['laboratoire_id.required' => 'Choisissez un laboratoire.', 'laboratoire_id.uuid' => 'Choisissez un laboratoire.']);

        try {
            $this->bons->choisirLaboratoire($request->user(), $this->sien($request, $bon), $donnees['laboratoire_id']);
        } catch (LaboratoireRefuse $refus) {
            return back()->withErrors(['examens' => $refus->getMessage()]);
        }

        return back()->with('succes', 'Laboratoire choisi. Vous pouvez payer le bon.');
    }

    public function payer(Request $request, string $bon, PaiementService $paiements): Response
    {
        $trouve = $this->sien($request, $bon);
        $donnees = $request->validate([
            'moyen' => ['required', Rule::enum(MoyenPaiement::class)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'cle' => ['required', 'string', 'max:64'],
        ], ['moyen.required' => 'Choisissez comment payer.', 'moyen.enum' => 'Choisissez comment payer.']);

        try {
            $tentative = $paiements->initierBon($trouve, $request->user(), MoyenPaiement::from($donnees['moyen']), $donnees['telephone'] ?? null, $donnees['cle']);
        } catch (PaiementRefuse $refus) {
            return back()->withErrors(['paiement' => $refus->getMessage()]);
        }

        if ($url = $tentative->initiation?->urlRedirection) {
            return Inertia::location($url);
        }

        return redirect()->route('paiement.suivi', $tentative->paiement);
    }

    public function code(Request $request, string $bon): RedirectResponse
    {
        $nouveau = $request->boolean('nouveau');

        try {
            $code = $this->bons->remettreCode($request->user(), $this->sien($request, $bon), $nouveau);
        } catch (LaboratoireRefuse $refus) {
            return back()->withErrors(['examens' => $refus->getMessage()]);
        }

        return back()->with('code_retrait', ['bon' => $bon, 'code' => $code]);
    }

    public function pdf(Request $request, string $resultat, ValidationResultats $validation): Response
    {
        try {
            return $validation->pdfPourPatient($request->user(), $resultat);
        } catch (LaboratoireRefuse) {
            abort(404);
        }
    }

    /** Cherché parmi les siens : celui d'un autre patient répond 404. */
    private function sien(Request $request, string $bon): BonExamen
    {
        abort_unless(Str::isUuid($bon), 404);

        return BonExamen::where('patient_id', $request->user()->id)->findOrFail($bon);
    }
}
