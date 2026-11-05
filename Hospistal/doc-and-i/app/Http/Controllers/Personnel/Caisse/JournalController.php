<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Enums\MoyenPaiement;
use App\Models\Medecin;
use App\Services\Caisse\JournalCaisseConsultation;
use App\Services\RendezVous\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** PAY-09 : journal de caisse par jour, par moyen, par médecin ; totaux par devise. */
class JournalController extends ControleurCaisse
{
    private const JOURS_MAX = 92;

    public function __invoke(Request $request, JournalCaisseConsultation $journal): Response
    {
        $aujourdhui = CarbonImmutable::now('Africa/Kinshasa')->toDateString();
        $filtres = $request->validate([
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
            'moyen' => ['nullable', Rule::enum(MoyenPaiement::class)],
            'medecin' => ['nullable', 'uuid', Rule::exists('medecins', 'id')],
        ]) + ['du' => null, 'au' => null, 'moyen' => null, 'medecin' => null];

        $du = CarbonImmutable::parse($filtres['du'] ?? $aujourdhui, 'Africa/Kinshasa');
        $au = CarbonImmutable::parse($filtres['au'] ?? $filtres['du'] ?? $aujourdhui, 'Africa/Kinshasa');
        if ($du->diffInDays($au) > self::JOURS_MAX) {
            $au = $du->addDays(self::JOURS_MAX);
        }

        return Inertia::render('Personnel/Caisse/Journal', [
            'filtres' => ['du' => $du->toDateString(), 'au' => $au->toDateString(), 'moyen' => $filtres['moyen'], 'medecin' => $filtres['medecin']],
            'moyens' => array_map(fn (MoyenPaiement $m) => ['valeur' => $m->value, 'libelle' => $m->libelle()], MoyenPaiement::cases()),
            'medecins' => Medecin::with('user')->get()->map(fn (Medecin $m) => ['id' => $m->id, 'nom' => Affichage::medecin($m)])->sortBy('nom')->values()->all(),
            'journal' => $journal->consulter($du, $au, $filtres['moyen'], $filtres['medecin']),
        ]);
    }
}
