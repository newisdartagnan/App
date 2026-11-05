<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Enums\MoyenPaiement;
use App\Models\Facture;
use App\Services\RendezVous\Affichage;
use App\Support\Montant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/** PAY-05, PAY-08 : factures et avoirs retrouvés par numéro, en PDF. */
class FactureController extends ControleurCaisse
{
    public function index(Request $request): Response
    {
        $numero = mb_strtoupper(trim((string) $request->query('numero', '')));
        $trouvee = $numero === '' ? null : Facture::with('patient')->where('numero', $numero)->first();

        // La pièce trouvée, sa facture d'origine (pour un avoir) et ses avoirs (pour une facture).
        $pieces = $trouvee === null ? [] : Facture::with('patient')
            ->where(fn ($q) => $q->where('paiement_id', $trouvee->paiement_id))
            ->orderBy('emise_le')->get()
            ->map(fn (Facture $f) => self::piece($f))->all();

        return Inertia::render('Personnel/Caisse/Factures', [
            'numero' => $numero,
            'pieces' => $pieces,
        ]);
    }

    public function pdf(string $facture): ReponseHttp
    {
        $f = Facture::with(['patient', 'medecin.user', 'origine'])->findOrFail($facture);

        $emblemeChemin = public_path('images/embleme-64.png');
        $pdf = Pdf::setOptions([
            // Aucune ressource externe, aucun script dans le document.
            'isRemoteEnabled' => false,
            'isJavascriptEnabled' => false,
            'isPhpEnabled' => false,
        ])->loadView('pdf.piece', [
            'piece' => self::piece($f),
            'emise' => $f->emise_le->setTimezone('Africa/Kinshasa')->format('d/m/Y H:i'),
            'medecin' => $f->medecin ? Affichage::medecin($f->medecin) : null,
            'origine' => $f->origine?->numero,
            'embleme' => is_file($emblemeChemin) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($emblemeChemin)) : null,
        ]);

        return $pdf->download($f->numero.'.pdf');
    }

    private static function piece(Facture $f): array
    {
        return [
            'id' => $f->id,
            'numero' => $f->numero,
            'type' => $f->estAvoir() ? 'Avoir' : 'Facture',
            'patient' => self::nom($f->patient),
            'libelle' => $f->libelle,
            'montant' => Montant::afficher((string) $f->montant, $f->devise),
            'moyen' => MoyenPaiement::from($f->moyen)->libelle(),
            'emiseLe' => self::quand($f->emise_le),
        ];
    }
}
