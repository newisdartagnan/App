<?php
namespace App\Http\Controllers;

use App\Models\Consultation;
use Illuminate\View\View;

class PrescriptionController extends Controller
{
    public function create(Consultation $consultation): View
    {
        $consultation->load(['visit.patient']);
        return view('prescriptions.create', compact('consultation'));
    }
}