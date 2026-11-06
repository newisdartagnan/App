<?php

namespace App\Services\Administration;

use App\Models\Partenaire;
use App\Models\User;
use App\Support\Telephone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADM-01 : laboratoires et pharmacies partenaires. Jamais supprimés (les
 * lots examens et ordonnances s'y référeront) : on les désactive. Numéro
 * normalisé (+243…). Chaque écriture va au journal, dans sa transaction.
 */
final class PartenairesService
{
    private const CHAMPS = ['type', 'nom', 'commune', 'adresse', 'telephone', 'email'];

    public function __construct(private readonly JournalAdministration $journal) {}

    /** @throws ValidationException */
    public function creer(User $admin, array $donnees): Partenaire
    {
        $valeurs = $this->valeurs($donnees);

        return DB::transaction(function () use ($admin, $valeurs) {
            // Verrou par nom : deux envois simultanés du même partenaire passent l'un après l'autre.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['partenaire:'.$valeurs['type'].':'.mb_strtolower($valeurs['nom'])]);
            $this->verifierNomLibre($valeurs['type'], $valeurs['nom']);

            $partenaire = Partenaire::create($valeurs + ['actif' => true]);
            $this->journal->consigner($admin, 'partenaire.creer', $partenaire, ['type' => $partenaire->type, 'nom' => $partenaire->nom]);

            return $partenaire;
        });
    }

    /**
     * @return bool vrai si quelque chose a changé
     *
     * @throws ValidationException
     */
    public function modifier(User $admin, Partenaire $partenaire, array $donnees): bool
    {
        $valeurs = $this->valeurs($donnees);

        return DB::transaction(function () use ($admin, $partenaire, $valeurs) {
            $p = Partenaire::whereKey($partenaire->id)->lockForUpdate()->firstOrFail();
            $this->verifierNomLibre($valeurs['type'], $valeurs['nom'], $p->id);

            // Des comptes du personnel sont rattachés à ce laboratoire : il ne
            // devient pas une pharmacie (revue de sécurité).
            if ($valeurs['type'] !== $p->type && DB::table('comptes_laboratoire')->where('partenaire_id', $p->id)->exists()) {
                throw ValidationException::withMessages(['type' => 'Des comptes du personnel sont rattachés à ce laboratoire : son type ne change pas.']);
            }

            $p->fill($valeurs);
            $modifies = array_keys($p->getDirty());
            if ($modifies === []) {
                return false;
            }
            $p->save();

            $this->journal->consigner($admin, 'partenaire.modifier', $p, [
                'type' => $p->type,
                'nom' => $p->nom,
                'champs_modifies' => implode(', ', $modifies),
            ]);

            return true;
        });
    }

    /** @return bool vrai si l'état a changé */
    public function changerEtat(User $admin, Partenaire $partenaire, bool $actif): bool
    {
        return DB::transaction(function () use ($admin, $partenaire, $actif) {
            $p = Partenaire::whereKey($partenaire->id)->lockForUpdate()->firstOrFail();
            if ($p->actif === $actif) {
                return false;
            }

            $p->actif = $actif;
            $p->save();
            $this->journal->consigner($admin, $actif ? 'partenaire.activer' : 'partenaire.desactiver', $p, ['type' => $p->type, 'nom' => $p->nom]);

            return true;
        });
    }

    /** @return array<string, ?string> */
    private function valeurs(array $donnees): array
    {
        $texte = fn (string $champ) => ($v = trim((string) ($donnees[$champ] ?? ''))) === '' ? null : $v;

        $valeurs = array_combine(self::CHAMPS, array_map($texte, self::CHAMPS));

        if (! in_array($valeurs['type'], Partenaire::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choisissez laboratoire ou pharmacie.']);
        }
        if ($valeurs['nom'] === null) {
            throw ValidationException::withMessages(['nom' => 'Donnez le nom du partenaire.']);
        }
        if ($valeurs['telephone'] !== null) {
            $valeurs['telephone'] = Telephone::normaliser($valeurs['telephone'])
                ?? throw ValidationException::withMessages(['telephone' => 'Ce numéro n\'est pas un numéro mobile de RDC (par exemple 081 234 56 78).']);
        }
        if ($valeurs['email'] !== null) {
            $valeurs['email'] = mb_strtolower($valeurs['email']);
        }

        return $valeurs;
    }

    private function verifierNomLibre(string $type, string $nom, ?string $saufId = null): void
    {
        $pris = Partenaire::where('type', $type)
            ->whereRaw('lower(nom) = lower(?)', [$nom])
            ->when($saufId, fn ($q) => $q->whereKeyNot($saufId))
            ->exists();

        if ($pris) {
            throw ValidationException::withMessages(['nom' => 'Un partenaire de ce type porte déjà ce nom.']);
        }
    }
}
