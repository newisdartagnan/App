<?php

namespace App\Http\Controllers\Paiement;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\Paiement;
use App\Services\RendezVous\Affichage;
use App\Support\Montant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/** PAY-05 : « Mes paiements », factures et avoirs du patient, en PDF. Seulement les siens. */
class MesPaiementsController extends Controller
{
    public function index(Request $request): Response
    {
        $patientId = $request->user()->id;

        return Inertia::render('Paiement/MesPaiements', [
            'paiements' => Paiement::where('patient_id', $patientId)
                ->with(['rendezVous.medecin.user', 'facture'])
                ->latest()
                ->get()
                ->map(fn (Paiement $p) => [
                    'id' => $p->id,
                    'quand' => Affichage::dateHeure($p->created_at),
                    'montant' => Montant::afficher((string) $p->montant, $p->devise),
                    'moyen' => $p->moyen->libelle(),
                    'statut' => $p->statut->value,
                    'statut_libelle' => $p->statut->libelle(),
                    'rendez_vous' => $p->rendezVous ? Affichage::dateHeure($p->rendezVous->debut) : null,
                ]),
            'documents' => Facture::where('patient_id', $patientId)
                ->orderByDesc('emise_le')->orderByDesc('numero')
                ->get()
                ->map(fn (Facture $f) => [
                    'id' => $f->id,
                    'numero' => $f->numero,
                    'type' => $f->estAvoir() ? 'Avoir' : 'Facture',
                    'emise' => Affichage::jour($f->emise_le),
                    'montant' => Montant::afficher((string) $f->montant, $f->devise),
                ]),
        ]);
    }

    public function telecharger(Request $request, Facture $facture): ReponseHttp
    {
        abort_unless($facture->patient_id === $request->user()->id, 404);
        $facture->load(['patient', 'origine']);

        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false])
            ->loadView('pdf.facture', [
                'facture' => $facture,
                'type' => $facture->estAvoir() ? 'Avoir' : 'Facture',
                'emise' => $facture->emise_le->setTimezone('Africa/Kinshasa')->format('d/m/Y à H:i'),
                'patient' => $facture->patient->nomComplet(),
                // Espaces ordinaires : la police du PDF n'a pas l'espace fine insécable.
                'montant' => Montant::afficher((string) $facture->montant, $facture->devise),
                'moyen' => MoyenPaiement::tryFrom($facture->moyen)?->libelle() ?? $facture->moyen,
            ]);

        return $pdf->download($facture->numero.'.pdf')
            ->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }
}
