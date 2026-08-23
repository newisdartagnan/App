<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agenda des rendez-vous : vue par prestataire, recherche de créneaux libres,
 * blocage de disponibilité.
 */
class AgendaController extends Controller
{
    public function __construct(protected AgendaService $agenda) {}

    public function index(Request $request): View
    {
        $jour = $request->query('jour', now()->toDateString());
        $prestataireId = $request->query('prestataire_id');

        $prestataires = $this->agenda->prestataires();
        $prestataire = $prestataireId
            ? $prestataires->firstWhere('id', $prestataireId)
            : $prestataires->first();

        $rendezVous = $prestataire
            ? $this->agenda->journee($prestataire, $jour)
            : collect();

        $creneauxLibres = $prestataire
            ? $this->agenda->creneauxLibres($prestataire, $jour)
            : [];

        return view('agenda.index', [
            'jour' => $jour,
            'prestataires' => $prestataires,
            'prestataire' => $prestataire,
            'rendezVous' => $rendezVous,
            'creneauxLibres' => $creneauxLibres,
            'tousDuJour' => $this->agenda->duJour($jour),
            'typesConsultation' => TypeConsultation::where('est_actif', true)->orderBy('libelle')->get(),
            'patientsRecents' => Patient::orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    /**
     * Convocation à remettre au patient.
     *
     * Un rendez-vous que le patient ne repart pas avec sur un papier est un
     * rendez-vous oublié : beaucoup n'ont ni agenda ni téléphone où le noter.
     */
    public function imprimer(RendezVous $rendezVous): View
    {
        $rendezVous->load(['patient.assurances.assurance', 'prestataire', 'typeConsultation', 'creePar']);

        abort_if($rendezVous->estBloque(), 404, 'Ce créneau est une indisponibilité, pas un rendez-vous.');

        return view('agenda.convocation', [
            'rendezVous' => $rendezVous,
            // L'établissement du patient, pas celui du fichier de
            // configuration : sur le même papier, l'en-tête lisait la base
            // et le pied de page le .env — deux noms pour un hôpital.
            'etablissement' => $rendezVous->patient?->establishment?->name
                ?: config('dpi.establishment_name', config('app.name')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'dossier_number' => 'required|string|exists:patients,dossier_number',
            'prestataire_id' => 'required|uuid|exists:users,id',
            'debut' => 'required|date|after:now',
            'duree_minutes' => 'required|integer|min:10|max:480',
            'type_consultation_id' => 'nullable|uuid|exists:types_consultation,id',
            'contact' => 'nullable|string|max:40',
            'motif' => 'nullable|string|max:200',
        ], [
            'debut.after' => 'Un rendez-vous se fixe dans le futur.',
            'dossier_number.exists' => 'Aucun patient ne porte ce numéro de dossier.',
        ]);

        try {
            $rendezVous = $this->agenda->fixer(
                Patient::where('dossier_number', $request->dossier_number)->firstOrFail(),
                User::findOrFail($request->prestataire_id),
                $request->debut,
                (int) $request->duree_minutes,
                $request->type_consultation_id,
                $request->contact,
                $request->motif
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // Le patient est devant le guichet à cette seconde précise : c'est
        // maintenant qu'il faut lui donner son papier, pas dans dix minutes
        // quand il sera reparti. Le bouton s'affiche donc dans le message.
        return back()
            ->with('success', 'Rendez-vous fixé pour '.$rendezVous->patient?->nom_complet
                .' le '.$rendezVous->debut->format('d/m/Y à H:i').'.')
            ->with('imprimer', route('agenda.convocation', $rendezVous))
            ->with('imprimer_libelle', 'Imprimer le rendez-vous à remettre au patient');
    }

    public function bloquer(Request $request): RedirectResponse
    {
        $request->validate([
            'prestataire_id' => 'required|uuid|exists:users,id',
            'debut' => 'required|date',
            'duree_minutes' => 'required|integer|min:10|max:600',
            'motif' => 'nullable|string|max:200',
        ]);

        try {
            $this->agenda->bloquer(
                User::findOrFail($request->prestataire_id),
                $request->debut,
                (int) $request->duree_minutes,
                $request->motif
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Créneau bloqué.');
    }

    public function statut(Request $request, RendezVous $rendezVous): RedirectResponse
    {
        $request->validate(['statut' => 'required|in:fixe,honore,absent,annule']);

        if ($request->statut === 'annule') {
            $this->agenda->annuler($rendezVous, $request->input('motif'));

            return back()->with('success', 'Rendez-vous annulé — le créneau est de nouveau libre.');
        }

        $rendezVous->update(['statut' => $request->statut]);

        return back()->with('success', 'Rendez-vous mis à jour.');
    }

    public function destroy(RendezVous $rendezVous): RedirectResponse
    {
        if (! $rendezVous->estBloque()) {
            return back()->with('error', 'Seul un créneau bloqué peut être supprimé — annulez le rendez-vous.');
        }

        $rendezVous->delete();

        return back()->with('success', 'Créneau débloqué.');
    }
}
