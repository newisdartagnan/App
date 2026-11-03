<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** CPT-04 : consentement explicite, horodaté et révocable. */
class ConsentementController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Consentement/Accepter', [
            'version' => config('doc-and-i.consentement.version'),
            'dejaAccepte' => $request->user()->consentementActif() !== null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'teleconsultation' => ['accepted'],
            'donnees' => ['accepted'],
        ], [
            'teleconsultation.accepted' => 'Cochez cette case pour consulter à distance.',
            'donnees.accepted' => 'Cochez cette case pour que vos données de santé soient traitées.',
        ]);

        $patient = $request->user();

        DB::transaction(function () use ($patient, $request) {
            // Verrou sur le patient : deux envois rapprochés du formulaire ne
            // créent pas deux consentements actifs.
            $patient->newQuery()->whereKey($patient->getKey())->lockForUpdate()->first();

            if ($patient->consentementActif() === null) {
                $patient->consentements()->create([
                    'version' => config('doc-and-i.consentement.version'),
                    'accepte_le' => now(),
                    'ip' => $request->ip(),
                    'agent_utilisateur' => mb_substr((string) $request->userAgent(), 0, 255),
                ]);
            }
        });

        return redirect()->intended(route('espace'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->consentements()->whereNull('retire_le')->update(['retire_le' => now()]);

        return redirect()->route('consentement')
            ->with('succes', 'Votre consentement est retiré. Aucune consultation ne sera possible tant que vous ne l\'aurez pas donné de nouveau.');
    }
}
