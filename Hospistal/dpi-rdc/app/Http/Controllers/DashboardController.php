<?php

namespace App\Http\Controllers;

use App\Models\Lit;
use App\Models\Visit;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'consultations' => Visit::whereDate('date_entree', today())
                ->where('type', 'consultation_externe')
                ->count(),
            'admissions' => Visit::whereDate('date_entree', today())->count(),
            'lits_occupes' => Lit::where('statut', 'occupe')->count(),
        ];

        return view('dashboard', compact('stats'));
    }
}
