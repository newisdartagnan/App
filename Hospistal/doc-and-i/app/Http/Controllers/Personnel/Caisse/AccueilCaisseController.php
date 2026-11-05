<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Enums\StatutRemboursement;
use App\Models\EcartRapprochement;
use App\Models\Remboursement;
use App\Models\Reversement;
use App\Services\Paiement\TarificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Accueil de la caisse : ce qui attend une action aujourd'hui. */
class AccueilCaisseController extends ControleurCaisse
{
    public function __invoke(Request $request, TarificationService $tarification): Response
    {
        $taux = $tarification->tauxDuJour();

        return Inertia::render('Personnel/Caisse/Accueil', [
            'tauxDuJour' => $taux ? (string) $taux->cdf_par_usd : null,
            'aFaire' => [
                'remboursements' => Remboursement::where('statut', StatutRemboursement::AValider->value)->count(),
                'ecarts' => EcartRapprochement::where('statut', 'ouvert')->count(),
                'reversements' => Reversement::where('statut', 'prepare')->count(),
            ],
        ]);
    }
}
