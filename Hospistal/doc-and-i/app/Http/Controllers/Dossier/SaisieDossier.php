<?php

namespace App\Http\Controllers\Dossier;

use App\Models\Antecedent;
use App\Models\DossierPatient;
use App\Models\User;
use App\Services\Dossier\DossierRefuse;
use App\Services\Dossier\DossierService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saisie du dossier (DOS-01), commune au patient et au médecin traitant :
 * validation de forme ici, règles dans DossierService. Un refus métier
 * revient en message sur le formulaire.
 */
trait SaisieDossier
{
    abstract protected function dossierService(): DossierService;

    protected function saisirAntecedent(Request $request, User $patient): RedirectResponse
    {
        $donnees = $request->validate([
            'categorie' => ['required', Rule::in(array_keys(DossierService::CATEGORIES))],
            'libelle' => ['required', 'string', 'max:200'],
            'precision' => ['nullable', 'string', 'max:500'],
        ], [
            'categorie.required' => 'Choisissez le type d\'élément.',
            'categorie.in' => 'Choisissez le type d\'élément.',
            'libelle.required' => 'Décrivez l\'élément.',
        ]);

        return $this->executer(fn () => $this->dossierService()->ajouterAntecedent($request->user(), $patient, $donnees['categorie'], $donnees['libelle'], $donnees['precision'] ?? null),
            'antecedent', 'Élément ajouté au dossier.');
    }

    protected function retirerElement(Request $request, Antecedent $antecedent): RedirectResponse
    {
        return $this->executer(fn () => $this->dossierService()->retirerAntecedent($request->user(), $antecedent),
            'retrait', 'Élément retiré du dossier. Il reste visible dans l\'historique.');
    }

    protected function saisirConstante(Request $request, User $patient): RedirectResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(array_keys(DossierService::CONSTANTES))],
            'valeur' => ['required', 'string', 'max:12'],
            'valeur2' => ['nullable', 'string', 'max:12'],
            'mesure_le' => ['nullable', 'date'],
        ], [
            'type.required' => 'Choisissez la mesure.',
            'type.in' => 'Choisissez la mesure.',
            'valeur.required' => 'Indiquez la valeur mesurée.',
        ]);

        return $this->executer(fn () => $this->dossierService()->ajouterConstante(
            $request->user(), $patient, $donnees['type'], $donnees['valeur'], $donnees['valeur2'] ?? null,
            isset($donnees['mesure_le']) ? CarbonImmutable::parse($donnees['mesure_le'], config('app.timezone')) : null,
        ), 'constante', 'Mesure enregistrée.');
    }

    protected function saisirGroupeSanguin(Request $request, User $patient): RedirectResponse
    {
        // Choix explicite obligatoire : un groupe, ou « je ne sais pas » (vide). Rien n'est supposé.
        $donnees = $request->validate([
            'groupe_sanguin' => ['required', Rule::in([...DossierPatient::GROUPES_SANGUINS, 'inconnu'])],
        ], [
            'groupe_sanguin.required' => 'Choisissez votre groupe sanguin, ou « je ne sais pas ».',
            'groupe_sanguin.in' => 'Groupe sanguin inconnu.',
        ]);
        $groupe = $donnees['groupe_sanguin'] === 'inconnu' ? null : $donnees['groupe_sanguin'];

        return $this->executer(fn () => $this->dossierService()->definirGroupeSanguin($request->user(), $patient, $groupe),
            'groupe_sanguin', 'Groupe sanguin enregistré.');
    }

    protected function executer(\Closure $action, string $champ, string $succes): RedirectResponse
    {
        try {
            $action();
        } catch (DossierRefuse $refus) {
            return back()->withErrors([$champ => $refus->getMessage()]);
        }

        return back()->with('succes', $succes);
    }
}
