<?php

namespace App\Services\Laboratoire;

use App\Models\Examen;
use App\Models\User;
use App\Services\Administration\JournalAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue des examens (PRE-04), géré par l'administration : créer,
 * modifier, activer ou désactiver. Jamais supprimé (des bons y renvoient).
 * Le prix est facultatif : un examen sans prix n'est pas prescriptible.
 * Aucun prix par défaut. Chaque écriture va au journal d'administration,
 * dans sa transaction.
 */
final class CatalogueExamens
{
    public function __construct(private readonly JournalAdministration $journal) {}

    /** @throws ValidationException */
    public function creer(User $admin, array $saisie): Examen
    {
        $valeurs = $this->valider($saisie);

        return DB::transaction(function () use ($admin, $valeurs) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['examen:'.$valeurs['code']]);
            if (Examen::where('code', $valeurs['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Ce code est déjà utilisé par un autre examen.']);
            }

            $examen = Examen::create($valeurs + ['actif' => true]);
            $this->journal->consigner($admin, 'examen.creer', $examen, self::details($examen));

            return $examen;
        });
    }

    /**
     * @return bool vrai si quelque chose a changé
     *
     * @throws ValidationException
     */
    public function modifier(User $admin, Examen $examen, array $saisie): bool
    {
        $valeurs = $this->valider($saisie, $examen);

        return DB::transaction(function () use ($admin, $examen, $valeurs) {
            $e = Examen::whereKey($examen->id)->lockForUpdate()->firstOrFail();
            $e->fill($valeurs);
            $modifies = array_keys($e->getDirty());
            if ($modifies === []) {
                return false;
            }
            $e->save();
            $this->journal->consigner($admin, 'examen.modifier', $e, self::details($e) + ['champs_modifies' => implode(', ', $modifies)]);

            return true;
        });
    }

    /** @return bool vrai si l'état a changé */
    public function changerEtat(User $admin, Examen $examen, bool $actif): bool
    {
        return DB::transaction(function () use ($admin, $examen, $actif) {
            $e = Examen::whereKey($examen->id)->lockForUpdate()->firstOrFail();
            if ($e->actif === $actif) {
                return false;
            }
            $e->actif = $actif;
            $e->save();
            $this->journal->consigner($admin, $actif ? 'examen.activer' : 'examen.desactiver', $e, ['code' => $e->code]);

            return true;
        });
    }

    /** @throws ValidationException */
    private function valider(array $saisie, ?Examen $existant = null): array
    {
        $valeurs = [
            'code' => mb_strtoupper(trim((string) ($saisie['code'] ?? ''))),
            'libelle' => trim((string) ($saisie['libelle'] ?? '')),
            'categorie' => $saisie['categorie'] ?? null,
            'prix' => ($p = trim((string) ($saisie['prix'] ?? ''))) === '' ? null : str_replace(',', '.', $p),
            'devise' => $saisie['devise'] ?? 'USD',
            'preparation' => ($pr = trim((string) ($saisie['preparation'] ?? ''))) === '' ? null : $pr,
        ];

        Validator::make($valeurs, [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('examens', 'code')->ignore($existant?->id)],
            'libelle' => ['required', 'string', 'min:2', 'max:200'],
            'categorie' => ['required', Rule::in(Examen::CATEGORIES)],
            'prix' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'devise' => ['required', Rule::in(config('doc-and-i.paiement.devises'))],
            'preparation' => ['nullable', 'string', 'max:255'],
        ], [
            'code.required' => 'Donnez un code court (par exemple GLY).',
            'code.regex' => 'Code : lettres, chiffres, point, tiret.',
            'code.unique' => 'Ce code est déjà utilisé par un autre examen.',
            'libelle.required' => 'Donnez le libellé de l\'examen.',
            'categorie.in' => 'Choisissez biologie ou imagerie.',
            'categorie.required' => 'Choisissez biologie ou imagerie.',
            'prix.regex' => 'Prix : un montant positif, par exemple 12.50.',
            'prix.not_in' => 'Un prix vaut plus que zéro ; laissez vide si le prix n\'est pas encore fixé.',
            'devise.in' => 'Choisissez USD ou CDF.',
        ])->validate();

        return $valeurs;
    }

    private static function details(Examen $e): array
    {
        return ['code' => $e->code, 'libelle' => $e->libelle, 'prix' => $e->prix === null ? null : (string) $e->prix, 'devise' => $e->devise];
    }
}
