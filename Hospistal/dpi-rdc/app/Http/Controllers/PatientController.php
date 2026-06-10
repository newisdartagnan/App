<?php
namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\View\View;

class PatientController extends Controller
{
    public function index(): View
    {
        return view('patients.index');
    }

    public function create(): View
    {
        return view('patients.create');
    }

    public function show(Patient $patient): View
    {
        return view('patients.show', compact('patient'));
    }
}