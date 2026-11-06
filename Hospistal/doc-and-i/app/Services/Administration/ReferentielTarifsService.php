<?php

namespace App\Services\Administration;

use App\Models\Specialite;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Support\Montant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ADM-01 : spécialités et types de consultation (durée, prix, devise).
 *
 * - Un prix vide : le type n'est pas proposé aux patients.
 * - Un changement de prix ne touche pas les rendez-vous déjà réservés : leur
 *   prix est figé à la réservation (RG-13).
 * - Une désactivation retire la spécialité ou le type de l'offre, sans
 *   toucher aux rendez-vous existants (ils se paient, se reportent et
 *   s'annulent comme avant).
 * - Chaque changement va au journal d'administration, dans sa transaction,
 *   avec l'ancienne et la nouvelle valeur.
 */
final class ReferentielTarifsService
{
    /** Durée d'un type : des créneaux de 5 en 5 minutes, de 10 minutes à 2 heures (bornes de la base). */
    public const DUREE_MIN = 10;

    public const DUREE_MAX = 120;

    public const DUREE_PAS = 5;

    public const DEVISES = ['USD', 'CDF'];

    public function __construct(private readonly JournalAdministration $journal) {}

    /** @return bool vrai si l'état a changé (un second envoi identique ne change rien) */
    public function changerEtatSpecialite(User $admin, Specialite $specialite, bool $active): bool
    {
        return DB::transaction(function () use ($admin, $specialite, $active) {
            $s = Specialite::whereKey($specialite->id)->lockForUpdate()->firstOrFail();
            if ($s->active === $active) {
                return false;
            }

            $s->active = $active;
            $s->save();
            $this->journal->consigner($admin, $active ? 'specialite.activer' : 'specialite.desactiver', $s, ['nom' => $s->nom]);

            return true;
        });
    }

    /**
     * @param  array{nom: string, duree_minutes: int|string, prix: ?string, devise: string, actif?: bool}  $donnees
     *
     * @throws ValidationException
     */
    public function creerType(User $admin, Specialite $specialite, array $donnees): TypeConsultation
    {
        $valeurs = $this->valeurs($donnees);

        return DB::transaction(function () use ($admin, $specialite, $valeurs) {
            // Verrou de la spécialité : deux créations simultanées du même nom passent l'une après l'autre.
            Specialite::whereKey($specialite->id)->lockForUpdate()->firstOrFail();
            $this->verifierNomLibre($specialite->id, $valeurs['nom']);

            $type = new TypeConsultation($valeurs);
            $type->specialite_id = $specialite->id;
            $type->save();

            $this->journal->consigner($admin, 'type_consultation.creer', $type, [
                'specialite' => $specialite->nom,
                'nom' => $type->nom,
                'duree_minutes' => $type->duree_minutes,
                'nouveau_prix' => $type->prix,
                'devise' => $type->devise,
                'actif' => $type->actif,
            ]);

            return $type;
        });
    }

    /**
     * @param  array{nom: string, duree_minutes: int|string, prix: ?string, devise: string, actif?: bool}  $donnees
     * @return bool vrai si quelque chose a changé
     *
     * @throws ValidationException
     */
    public function modifierType(User $admin, TypeConsultation $type, array $donnees): bool
    {
        $valeurs = $this->valeurs($donnees);

        return DB::transaction(function () use ($admin, $type, $valeurs) {
            $t = TypeConsultation::whereKey($type->id)->lockForUpdate()->firstOrFail();
            if ($valeurs['nom'] !== $t->nom) {
                $this->verifierNomLibre($t->specialite_id, $valeurs['nom'], $t->id);
            }

            $avant = ['nom' => $t->nom, 'duree_minutes' => $t->duree_minutes, 'prix' => $t->prix, 'devise' => $t->devise, 'actif' => $t->actif];
            $t->fill($valeurs);
            if (! $t->isDirty()) {
                return false;
            }
            $t->save();

            $this->journal->consigner($admin, 'type_consultation.modifier', $t, [
                'nom' => $t->nom,
                'ancien_nom' => $avant['nom'],
                'ancienne_duree' => $avant['duree_minutes'],
                'nouvelle_duree' => $t->duree_minutes,
                'ancien_prix' => $avant['prix'],
                'nouveau_prix' => $t->prix,
                'ancienne_devise' => $avant['devise'],
                'nouvelle_devise' => $t->devise,
                'ancien_actif' => $avant['actif'],
                'actif' => $t->actif,
            ]);

            return true;
        });
    }

    /**
     * Prix saisi (« 10 », « 10.5 », « 10,50 ») ramené à « 10.50 » par
     * Montant, jamais par un flottant. Vide : pas de prix (non proposé).
     *
     * @throws ValidationException
     */
    public static function normaliserPrix(?string $saisie, string $devise): ?string
    {
        $saisie = trim(str_replace([' ', "\u{202F}", "\u{00A0}"], '', (string) $saisie));
        if ($saisie === '') {
            return null;
        }

        try {
            $centimes = Montant::enCentimes(str_replace(',', '.', $saisie));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['prix' => 'Prix illisible : écrivez par exemple 10 ou 10.50.']);
        }

        if ($centimes <= 0) {
            throw ValidationException::withMessages(['prix' => 'Le prix doit être positif. Laissez vide pour ne pas proposer ce type.']);
        }

        if ($devise === 'CDF' && $centimes % 100 !== 0) {
            throw ValidationException::withMessages(['prix' => 'Le franc congolais se compte sans centimes.']);
        }

        if ($centimes > 99_999_999_99) {
            throw ValidationException::withMessages(['prix' => 'Prix trop élevé.']);
        }

        return Montant::depuisCentimes($centimes);
    }

    /** @throws ValidationException */
    public static function verifierDuree(int $minutes): void
    {
        if ($minutes < self::DUREE_MIN || $minutes > self::DUREE_MAX || $minutes % self::DUREE_PAS !== 0) {
            throw ValidationException::withMessages(['duree_minutes' => sprintf(
                'La durée doit cadrer avec les créneaux : de %d à %d minutes, par pas de %d minutes.',
                self::DUREE_MIN, self::DUREE_MAX, self::DUREE_PAS,
            )]);
        }
    }

    /** @return array{nom: string, duree_minutes: int, prix: ?string, devise: string, actif: bool} */
    private function valeurs(array $donnees): array
    {
        $nom = trim((string) ($donnees['nom'] ?? ''));
        if ($nom === '' || mb_strlen($nom) > 150) {
            throw ValidationException::withMessages(['nom' => 'Donnez un nom de 150 caractères au plus.']);
        }

        $devise = (string) ($donnees['devise'] ?? '');
        if (! in_array($devise, self::DEVISES, true)) {
            throw ValidationException::withMessages(['devise' => 'Choisissez USD ou CDF.']);
        }

        $duree = filter_var($donnees['duree_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($duree === false) {
            throw ValidationException::withMessages(['duree_minutes' => 'Indiquez une durée en minutes.']);
        }
        self::verifierDuree($duree);

        return [
            'nom' => $nom,
            'duree_minutes' => $duree,
            'prix' => self::normaliserPrix($donnees['prix'] ?? null, $devise),
            'devise' => $devise,
            'actif' => (bool) ($donnees['actif'] ?? true),
        ];
    }

    private function verifierNomLibre(string $specialiteId, string $nom, ?string $saufId = null): void
    {
        $pris = TypeConsultation::where('specialite_id', $specialiteId)
            ->whereRaw('lower(nom) = lower(?)', [$nom])
            ->when($saufId, fn ($q) => $q->whereKeyNot($saufId))
            ->exists();

        if ($pris) {
            throw ValidationException::withMessages(['nom' => 'Cette spécialité a déjà un type de consultation de ce nom.']);
        }
    }
}
