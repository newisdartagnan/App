<?php
namespace App\Http\Controllers;
use App\Models\Medicament;
use App\Models\Prescription;
use Illuminate\View\View;
class PharmacieController extends Controller
{
    public function dashboard(): View
    {
        return view('pharmacie.dashboard');
    }
    public function stock(): View
    {
        return view('pharmacie.stock');
    }
    public function prescriptions(): View
    {
        return view('pharmacie.prescriptions');
    }
    public function showPrescription(Prescription $prescription): View
    {
        $prescription->load(['patient', 'prescripteur', 'lignes.medicament']);
        return view('pharmacie.prescription-show', compact('prescription'));
    }
    public function medicaments(): View
    {
        return view('pharmacie.medicaments');
    }
}