<?php
namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function index(): View
    {
        return view('consultations.index');
    }

    public function create(Patient $patient): View
    {
        return view('consultations.create', compact('patient'));
    }

    public function show(Consultation $consultation): View
    {
        $consultation->load(['visit.patient', 'user']);
        return view('consultations.show', compact('consultation'));
    }
}