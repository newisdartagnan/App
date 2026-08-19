<?php

namespace App\Http\Controllers;

use App\Models\PrescriptionDiete;
use App\Models\Service;
use App\Models\TacheMenage;
use App\Models\TypeDiete;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Diète et ménage : la vue de la cuisine et de l'équipe hôtelière sur les
 * patients hospitalisés. Un seul écran donne, service par service, le régime
 * à servir, la durée de séjour et l'entretien restant à faire du jour.
 */
class DieteMenageController extends Controller
{
    public function index(Request $request): View
    {
        $jour = $request->query('jour', now()->toDateString());
        $serviceId = $request->query('service_id');

        $services = Service::where('is_active', true)->orderBy('nom')->get();

        $sejours = Visit::query()
            ->where('type', 'hospitalisation')
            ->whereIn('statut', ['en_cours', 'en_attente'])
            ->when($serviceId, fn ($q) => $q->where('service_id', $serviceId))
            ->with([
                'patient',
                'service',
                'lit',
                // La diète du jour affiché, et non la seule diète ouverte :
                // une fois le séjour facturé la prescription est clôturée,
                // et la cuisine doit continuer de voir ce qu'elle sert.
                'prescriptionsDiete' => fn ($q) => $q->with('typeDiete')
                    ->whereDate('debut', '<=', $jour)
                    ->where(fn ($q2) => $q2->whereNull('fin')->orWhereDate('fin', '>=', $jour))
                    ->latest('debut'),
                'tachesMenage' => fn ($q) => $q->whereDate('jour', $jour),
            ])
            ->orderBy('service_id')
            ->get()
            ->sortBy(fn ($v) => ($v->service?->nom ?? 'ZZZ').'-'.($v->lit?->numero ?? 'ZZZ'))
            ->values();

        $types = TypeDiete::where('is_active', true)->orderBy('libelle')->get();

        // Récapitulatif pour la cuisine : combien de plateaux par régime.
        $plateaux = $sejours
            ->map(fn ($v) => $v->prescriptionsDiete->first()?->typeDiete)
            ->filter()
            ->groupBy('libelle')
            ->map->count()
            ->sortDesc();

        $sansDiete = $sejours->filter(fn ($v) => $v->prescriptionsDiete->isEmpty())->count();

        return view('diete.index', compact(
            'sejours', 'services', 'types', 'jour', 'serviceId', 'plateaux', 'sansDiete'
        ));
    }

    /**
     * Prescrit une diète : la précédente est clôturée la veille pour que la
     * facturation ne compte jamais deux régimes le même jour.
     */
    public function prescrire(Request $request, Visit $visit): RedirectResponse
    {
        $donnees = $request->validate([
            'type_diete_id' => 'required|uuid|exists:types_diete,id',
            'debut' => 'required|date',
            'observation' => 'nullable|string|max:500',
        ]);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        $debut = Carbon::parse($donnees['debut'])->startOfDay();

        $encours = $visit->prescriptionsDiete()->whereNull('fin')->get();

        foreach ($encours as $precedente) {
            if ($precedente->type_diete_id === $donnees['type_diete_id']) {
                return back()->with('error', 'Cette diète est déjà celle en cours pour ce séjour.');
            }

            // Une diète commencée le jour même est remplacée, pas facturée.
            $precedente->update([
                'fin' => $precedente->debut->greaterThanOrEqualTo($debut)
                    ? $precedente->debut
                    : $debut->copy()->subDay(),
            ]);
        }

        PrescriptionDiete::create($donnees + [
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
        ]);

        return back()->with('success', 'Diète prescrite.');
    }

    /** Clôture la diète en cours, par exemple avant une mise à jeun. */
    public function arreter(Visit $visit): RedirectResponse
    {
        $encours = $visit->prescriptionsDiete()->whereNull('fin')->get();

        if ($encours->isEmpty()) {
            return back()->with('error', 'Aucune diète en cours pour ce séjour.');
        }

        foreach ($encours as $prescription) {
            $prescription->update(['fin' => now()->toDateString()]);
        }

        return back()->with('success', 'Diète arrêtée.');
    }

    /** Enregistre une prestation de ménage : une seule par type et par jour. */
    public function menage(Request $request, Visit $visit): RedirectResponse
    {
        $donnees = $request->validate([
            'jour' => 'required|date',
            'type' => 'required|in:'.implode(',', array_keys(TacheMenage::TYPES)),
            'statut' => 'required|in:'.implode(',', array_keys(TacheMenage::STATUTS)),
            'observation' => 'nullable|string|max:500',
        ]);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        TacheMenage::updateOrCreate(
            ['visit_id' => $visit->id, 'jour' => $donnees['jour'], 'type' => $donnees['type']],
            ['statut' => $donnees['statut'], 'observation' => $donnees['observation'] ?? null, 'user_id' => auth()->id()]
        );

        return back()->with('success', 'Prestation de ménage enregistrée.');
    }

    /** Feuille de service imprimable, remise à la cuisine et à l'équipe hôtelière. */
    public function imprimer(Request $request): View
    {
        $jour = $request->query('jour', now()->toDateString());
        $serviceId = $request->query('service_id');

        $sejours = Visit::query()
            ->where('type', 'hospitalisation')
            ->whereIn('statut', ['en_cours', 'en_attente'])
            ->when($serviceId, fn ($q) => $q->where('service_id', $serviceId))
            ->with([
                'patient', 'service', 'lit',
                'prescriptionsDiete' => fn ($q) => $q->with('typeDiete')
                    ->whereDate('debut', '<=', $jour)
                    ->where(fn ($q2) => $q2->whereNull('fin')->orWhereDate('fin', '>=', $jour))
                    ->latest('debut'),
            ])
            ->get()
            ->sortBy(fn ($v) => ($v->service?->nom ?? 'ZZZ').'-'.($v->lit?->numero ?? 'ZZZ'))
            ->groupBy(fn ($v) => $v->service?->nom ?? 'Sans service');

        $service = $serviceId ? Service::find($serviceId) : null;

        return view('diete.imprimer', compact('sejours', 'jour', 'service'));
    }
}
