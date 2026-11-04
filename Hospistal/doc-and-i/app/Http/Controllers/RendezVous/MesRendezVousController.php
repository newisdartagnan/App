<?php

namespace App\Http\Controllers\RendezVous;

use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Models\DemandeConsultation;
use App\Models\RendezVous;
use App\Services\RendezVous\Affichage;
use App\Services\RendezVous\CreneauxService;
use App\Services\RendezVous\DemandeConsultationService;
use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** « Mes rendez-vous » : liste, détail, annulation et report (RDV-07, RG-04). */
class MesRendezVousController extends Controller
{
    use ControleDuPatient;

    public function index(Request $request, ReservationService $service): Response
    {
        $patient = $request->user();
        // Les attentes échues apparaissent tout de suite comme expirées, sans attendre la tâche planifiée.
        $service->expirerAttentesEchues(null, $patient->id);

        $rendezVous = RendezVous::where('patient_id', $patient->id)
            ->with(['medecin.user', 'typeConsultation'])
            ->orderByDesc('debut')
            ->get()
            ->map(fn (RendezVous $rdv) => Affichage::resume($rdv));

        return Inertia::render('RendezVous/MesRendezVous', [
            'aVenir' => $rendezVous->where('a_venir', true)->reverse()->values(),
            'passes' => $rendezVous->where('a_venir', false)->values(),
            'demandesOuvertes' => DemandeConsultation::where('patient_id', $patient->id)
                ->where('statut', 'ouverte')
                ->latest()
                ->get()
                ->map(fn (DemandeConsultation $d) => [
                    'id' => $d->id,
                    'motif' => $d->motif,
                    'creee' => Affichage::jour($d->created_at),
                ]),
        ]);
    }

    public function voir(Request $request, RendezVous $rendezVous, ReservationService $service): Response
    {
        $this->assurerProprietaire($request, $rendezVous);
        $service->expirerAttentesEchues(null, $rendezVous->patient_id);

        $rdv = $rendezVous->fresh(['medecin.user', 'typeConsultation', 'demande.piecesJointes']);
        $maintenant = CarbonImmutable::now();
        $confirme = $rdv->statut === StatutRendezVous::Confirme;
        $enAttente = $rdv->statut === StatutRendezVous::EnAttentePaiement;
        $avantDebut = $maintenant < $rdv->debut;
        $tardif = $service->estTardif($rdv, $maintenant);
        $reportsEpuises = $rdv->reports >= (int) config('doc-and-i.rendez_vous.reports_max');

        return Inertia::render('RendezVous/Detail', [
            'rendezVous' => [
                ...Affichage::resume($rdv),
                'duree_minutes' => $rdv->typeConsultation->duree_minutes,
                'prix' => Affichage::montant($rdv->prix, $rdv->devise),
                'secondes_restantes' => $enAttente ? max(0, $rdv->expire_le->getTimestamp() - $maintenant->getTimestamp()) : null,
                'taux_remboursement' => $rdv->taux_remboursement,
                'motif_annulation' => $rdv->motif_annulation,
                'reports' => $rdv->reports,
            ],
            'demande' => [
                'id' => $rdv->demande->id,
                'motif' => $rdv->demande->motif,
                'symptomes' => $rdv->demande->symptomes,
                'depuis' => DemandeConsultationService::DUREES[$rdv->demande->depuis] ?? null,
                'pieces' => $rdv->demande->piecesJointes->map(fn ($p) => ['id' => $p->id, 'nom' => $p->nom_original])->values(),
                'peut_reserver' => $rdv->demande->statut === 'ouverte',
            ],
            'actions' => [
                'annuler' => $enAttente || ($confirme && $avantDebut),
                // Ce que rapporterait une annulation maintenant (RG-04).
                'taux_si_annulation' => $confirme && $avantDebut ? $service->tauxSiAnnulation($rdv, $maintenant) : null,
                'reporter' => $confirme && $avantDebut && ! $reportsEpuises
                    && (! $tardif || $rdv->reports_tardifs < (int) config('doc-and-i.rendez_vous.reports_max_tardifs')),
                'report_tardif' => $confirme && $avantDebut && $tardif,
                'reports_epuises' => $confirme && $avantDebut && $reportsEpuises,
            ],
        ]);
    }

    public function annuler(Request $request, RendezVous $rendezVous, ReservationService $service): RedirectResponse
    {
        $this->assurerProprietaire($request, $rendezVous);

        $donnees = $request->validate(['motif' => ['nullable', 'string', 'max:255']], [
            'motif.max' => 'Le motif est trop long : 255 caractères au plus.',
        ]);

        try {
            $rdv = $service->annulerParPatient($rendezVous, $donnees['motif'] ?? null);
        } catch (ReservationRefusee $e) {
            return back()->withErrors(['annulation' => $e->getMessage()]);
        }

        $message = match ($rdv->taux_remboursement) {
            null => 'Votre réservation est annulée.',
            0 => 'Votre rendez-vous est annulé. À moins de 2 heures du début, il n\'est pas remboursé.',
            100 => 'Votre rendez-vous est annulé. Il vous sera remboursé en entier.',
            default => "Votre rendez-vous est annulé. {$rdv->taux_remboursement} % du prix vous sera remboursé.",
        };

        return redirect()->route('rdv.voir', $rdv)->with('succes', $message);
    }

    public function report(Request $request, RendezVous $rendezVous, CreneauxService $creneaux, ReservationService $service): Response|RedirectResponse
    {
        $this->assurerProprietaire($request, $rendezVous);
        $rdv = $rendezVous->load(['medecin.user', 'typeConsultation']);

        if ($rdv->statut !== StatutRendezVous::Confirme || CarbonImmutable::now() >= $rdv->debut) {
            return redirect()->route('rdv.voir', $rdv)->withErrors(['creneau' => 'Seul un rendez-vous confirmé, pas encore commencé, peut être déplacé.']);
        }

        $liste = array_values(array_filter(
            $creneaux->creneaux($rdv->medecin, $rdv->typeConsultation, $rdv->id),
            fn (CarbonImmutable $debut) => $debut->getTimestamp() !== $rdv->debut->getTimestamp()
        ));

        return Inertia::render('RendezVous/Report', [
            'rendezVous' => Affichage::resume($rdv),
            'jours' => Affichage::parJour(array_map(fn ($debut) => ['debut' => $debut, 'medecin' => $rdv->medecin], $liste)),
            'tardif' => $service->estTardif($rdv, CarbonImmutable::now()),
        ]);
    }

    public function reporter(Request $request, RendezVous $rendezVous, ReservationService $service): RedirectResponse
    {
        $this->assurerProprietaire($request, $rendezVous);

        $donnees = $request->validate(['debut' => ['required', 'date']], [
            'debut.required' => 'Choisissez un créneau.',
            'debut.date' => 'Choisissez un créneau.',
        ]);

        try {
            $rdv = $service->reporter($rendezVous, CarbonImmutable::parse($donnees['debut']));
        } catch (ReservationRefusee $e) {
            return back()->withErrors(['creneau' => $e->getMessage()]);
        }

        return redirect()->route('rdv.voir', $rdv)
            ->with('succes', 'Votre rendez-vous est déplacé au '.lcfirst(Affichage::dateHeure($rdv->debut)).'.');
    }
}
