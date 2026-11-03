<?php

namespace App\Http\Controllers;

use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** CPT-02 : le profil du patient. */
class ProfilController extends Controller
{
    public function edit(Request $request): Response
    {
        $patient = $request->user();

        return Inertia::render('Profil/Modifier', [
            'profil' => [
                'nom' => $patient->nom,
                'postnom' => $patient->postnom,
                'prenom' => $patient->prenom,
                'sexe' => $patient->sexe,
                'date_naissance' => $patient->date_naissance?->toDateString(),
                'commune' => $patient->commune,
                'langue' => $patient->langue,
                'contact_urgence_nom' => $patient->contact_urgence_nom,
                'contact_urgence_telephone' => $patient->contact_urgence_telephone,
            ],
            'telephone' => Telephone::masquer($patient->telephone),
            'premiereFois' => ! $patient->profilComplet(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'postnom' => ['nullable', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'sexe' => ['required', Rule::in(['M', 'F'])],
            'date_naissance' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'commune' => ['nullable', 'string', 'max:100'],
            'langue' => ['required', Rule::in(['fr', 'ln'])],
            'contact_urgence_nom' => ['nullable', 'string', 'max:150'],
            'contact_urgence_telephone' => ['nullable', 'string', 'max:30'],
        ], [
            'date_naissance.before' => 'La date de naissance doit être passée.',
        ]);

        if (filled($donnees['contact_urgence_telephone'] ?? null)) {
            $donnees['contact_urgence_telephone'] = Telephone::normaliser($donnees['contact_urgence_telephone'])
                ?? throw ValidationException::withMessages([
                    'contact_urgence_telephone' => 'Ce numéro n\'est pas un numéro mobile de RDC.',
                ]);
        }

        $donnees['nom'] = mb_strtoupper(trim($donnees['nom']));

        $request->user()->update($donnees);

        return redirect()->route('espace')->with('succes', 'Profil enregistré.');
    }
}
