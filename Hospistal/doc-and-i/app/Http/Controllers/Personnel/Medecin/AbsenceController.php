<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Personnel\DisponibilitesMedecinService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RDV-04 : absences du médecin connecté (congés, empêchements), heure de Kinshasa. */
class AbsenceController extends ControleurMedecin
{
    private const FORMAT = 'Y-m-d\TH:i';

    public function __construct(private readonly DisponibilitesMedecinService $disponibilites) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Personnel/Medecin/Absences', [
            'absences' => $this->disponibilites->absences($this->medecin($request)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $medecin = $this->medecin($request);

        $valides = $request->validate([
            'debut' => ['required', 'date_format:'.self::FORMAT],
            'fin' => ['required', 'date_format:'.self::FORMAT],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        [, $rendezVous] = $this->disponibilites->ajouterAbsence(
            $medecin,
            CarbonImmutable::createFromFormat(self::FORMAT, $valides['debut'], DisponibilitesMedecinService::FUSEAU)->startOfMinute(),
            CarbonImmutable::createFromFormat(self::FORMAT, $valides['fin'], DisponibilitesMedecinService::FUSEAU)->startOfMinute(),
            $valides['motif'] ?? null,
        );

        $message = 'Absence enregistrée : aucun nouveau créneau ne sera proposé sur cette période.';
        if ($rendezVous > 0) {
            $message .= " Attention : {$rendezVous} rendez-vous déjà pris sur cette période ; prévenez la coordination.";
        }

        return redirect()->route('personnel.medecin.absences')->with('succes', $message);
    }

    public function destroy(Request $request, string $absence): RedirectResponse
    {
        $this->disponibilites->supprimerAbsence($this->medecin($request), $absence);

        return redirect()->route('personnel.medecin.absences')->with('succes', 'Absence supprimée.');
    }
}
