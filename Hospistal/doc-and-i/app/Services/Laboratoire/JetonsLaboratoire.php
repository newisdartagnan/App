<?php

namespace App\Services\Laboratoire;

use App\Models\JetonLaboratoire;
use App\Models\Partenaire;
use App\Models\User;
use App\Services\Administration\JournalAdministration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Jetons d'API des laboratoires (PRE-06) : émis par l'administration pour un
 * laboratoire actif, affichés UNE fois, gardés par leur empreinte SHA-256
 * seulement, révocables. Le jeton est porté en « Authorization: Bearer ».
 */
final class JetonsLaboratoire
{
    public const PREFIXE = 'mhlab_';

    public function __construct(private readonly JournalAdministration $journal) {}

    /**
     * @return array{0: JetonLaboratoire, 1: string} le jeton et sa valeur en clair (à montrer une seule fois)
     *
     * @throws ValidationException
     */
    public function emettre(User $admin, Partenaire $laboratoire, string $libelle): array
    {
        $libelle = trim($libelle);
        if ($libelle === '' || mb_strlen($libelle) > 100) {
            throw ValidationException::withMessages(['libelle' => 'Donnez un nom au jeton (par exemple « Logiciel du laboratoire »), 100 caractères au plus.']);
        }
        if ($laboratoire->type !== 'laboratoire' || ! $laboratoire->actif) {
            throw ValidationException::withMessages(['partenaire_id' => 'Choisissez un laboratoire actif.']);
        }

        $clair = self::PREFIXE.Str::random(48);

        $jeton = DB::transaction(function () use ($admin, $laboratoire, $libelle, $clair) {
            $jeton = (new JetonLaboratoire)->forceFill([
                'partenaire_id' => $laboratoire->id,
                'empreinte' => self::empreinte($clair),
                'libelle' => $libelle,
                'cree_par' => $admin->id,
            ]);
            $jeton->save();
            // Jamais la valeur du jeton au journal : le laboratoire et le nom seulement.
            $this->journal->consigner($admin, 'jeton_laboratoire.emettre', $jeton, ['laboratoire' => $laboratoire->nom, 'libelle' => $libelle]);

            return $jeton;
        });

        return [$jeton, $clair];
    }

    /** @return bool vrai si le jeton vient d'être révoqué */
    public function revoquer(User $admin, JetonLaboratoire $jeton): bool
    {
        return DB::transaction(function () use ($admin, $jeton) {
            $j = JetonLaboratoire::whereKey($jeton->id)->lockForUpdate()->firstOrFail();
            if ($j->revoque_le !== null) {
                return false;
            }
            $j->revoque_le = CarbonImmutable::now();
            $j->save();
            $this->journal->consigner($admin, 'jeton_laboratoire.revoquer', $j, ['libelle' => $j->libelle]);

            return true;
        });
    }

    /** Le jeton présenté, s'il est valide : connu, non révoqué, laboratoire actif. */
    public function authentifier(?string $clair): ?JetonLaboratoire
    {
        if ($clair === null || ! str_starts_with($clair, self::PREFIXE) || strlen($clair) > 200) {
            return null;
        }

        $jeton = JetonLaboratoire::with('laboratoire')->where('empreinte', self::empreinte($clair))->first();
        if ($jeton === null || $jeton->revoque_le !== null || ! $jeton->laboratoire?->actif || $jeton->laboratoire->type !== 'laboratoire') {
            return null;
        }

        JetonLaboratoire::whereKey($jeton->id)->update(['dernier_usage_le' => CarbonImmutable::now()]);

        return $jeton;
    }

    public static function empreinte(string $clair): string
    {
        return hash('sha256', $clair);
    }
}
