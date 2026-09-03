<?php

namespace App\Http\Controllers;

use App\Models\Lit;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Models\Visit;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transfert d'un patient d'un service à un autre sans clore son séjour.
 *
 * Le transfert de sortie, lui, met fin à l'admission et oriente le patient
 * vers un autre établissement : ce sont deux gestes différents, et les
 * confondre reviendrait à fermer un dossier encore ouvert.
 */
class TransfertServiceController extends Controller
{
    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $donnees = $request->validate([
            'service_destination_id' => 'required|uuid|exists:services,id',
            'lit_destination_id' => 'nullable|uuid|exists:lits,id',
            'demandeur_id' => 'nullable|uuid|exists:users,id',
            'demandeur_nom' => 'required_without:demandeur_id|nullable|string|max:150',
            'motif' => 'required|string|max:1000',
        ], [
            'motif.required' => 'Indiquez la raison du transfert.',
            'demandeur_nom.required_without' => 'Indiquez qui demande le transfert.',
        ]);

        if ($visit->type !== 'hospitalisation') {
            return back()->with('error', 'Seul un patient hospitalisé se transfère d\'un service à un autre.');
        }

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        if ($donnees['service_destination_id'] === $visit->service_id) {
            return back()->with('error', 'Le patient est déjà dans ce service.');
        }

        // validate() ne renvoie que les clés effectivement soumises : un
        // champ laissé vide dans le formulaire n'apparaît pas dans le tableau.
        $demandeurId = $donnees['demandeur_id'] ?? null;
        $demandeur = $demandeurId ? User::find($demandeurId) : null;
        $nomDemandeur = $demandeur?->nom_complet ?: trim((string) ($donnees['demandeur_nom'] ?? ''));

        if ($nomDemandeur === '') {
            return back()->withInput()->withErrors([
                'demandeur_nom' => 'Indiquez qui demande le transfert.',
            ]);
        }

        try {
            $transfert = DB::transaction(function () use ($visit, $donnees, $demandeur, $nomDemandeur) {
                $serviceSource = $visit->service_id;
                $litSource = $visit->lit_id;

                // Le lit d'arrivée est verrouillé le temps de l'échange :
                // deux transferts simultanés ne peuvent pas viser le même lit.
                $litDestination = null;

                if (! empty($donnees['lit_destination_id'])) {
                    $litDestination = Lit::where('id', $donnees['lit_destination_id'])
                        ->where('service_id', $donnees['service_destination_id'])
                        ->where('statut', 'libre')
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($litSource) {
                    Lit::where('id', $litSource)->update(['statut' => 'libre']);
                }

                $litDestination?->update(['statut' => 'occupe']);

                // Le séjour ne change pas : même admission, même dossier,
                // mêmes factures. Seuls le service et le lit bougent.
                $visit->update([
                    'service_id' => $donnees['service_destination_id'],
                    'lit_id' => $litDestination?->id,
                ]);

                return TransfertService::create([
                    'visit_id' => $visit->id,
                    'service_source_id' => $serviceSource,
                    'lit_source_id' => $litSource,
                    'service_destination_id' => $donnees['service_destination_id'],
                    'lit_destination_id' => $litDestination?->id,
                    'demandeur_id' => $demandeur?->id,
                    'demandeur_nom' => $nomDemandeur,
                    'motif' => $donnees['motif'],
                    'user_id' => auth()->id(),
                    'transfere_a' => now(),
                ]);
            });
        } catch (ModelNotFoundException) {
            return back()->with('error', 'Ce lit vient d\'être occupé — choisissez-en un autre.');
        }

        $transfert->load(['serviceSource', 'serviceDestination', 'litDestination']);

        // L'équipe qui reçoit doit savoir qu'un patient lui arrive.
        app(NotificationService::class)->envoyer(
            service: 'hospitalisation',
            type: 'transfert_service',
            titre: 'Patient transféré en '.$transfert->serviceDestination->nom,
            message: $visit->patient->nom_complet.' arrive de '
                .($transfert->serviceSource?->nom ?? 'un autre service')
                .($transfert->litDestination ? ' — lit '.$transfert->litDestination->numero : '')
                .'. Demandé par '.$nomDemandeur.' : '.$donnees['motif'],
            referenceType: 'transfert_service',
            referenceId: $transfert->id,
            codeReference: $visit->patient->dossier_number,
            groupeDestinataire: 'infirmier_chef',
            priorite: 'haute',
            patientId: $visit->patient_id,
        );

        return redirect()
            ->route('services.dossier', [$transfert->service_destination_id, $visit])
            ->with('success', 'Patient transféré : '.$transfert->trajet()
                .($transfert->litDestination ? ' — lit '.$transfert->litDestination->numero : '')
                .'. Le séjour reste ouvert.');
    }

    /**
     * Lits libres d'un service, pour alimenter le formulaire de transfert.
     *
     * @return Collection<int, Lit>
     */
    public static function litsLibres(Service $service): Collection
    {
        return Lit::where('service_id', $service->id)
            ->where('statut', 'libre')
            ->where('is_active', true)
            ->orderBy('numero')
            ->get();
    }
}
