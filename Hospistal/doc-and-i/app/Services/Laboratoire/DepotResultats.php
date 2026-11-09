<?php

namespace App\Services\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\AlerteMedecin;
use App\Models\BonExamen;
use App\Models\LigneBonExamen;
use App\Models\ResultatExamen;
use App\Services\RendezVous\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Côté laboratoire (PRE-05, PRE-06) : le même service pour le portail et
 * pour l'API.
 *
 * - Le laboratoire ne voit que les bons PAYÉS transmis à LUI, et de chacun
 *   ce qu'il faut pour faire l'examen (identité, examens, renseignements
 *   cliniques, date souhaitée) ; rien d'autre du dossier.
 * - « Patient reçu » : le code de retrait remis au patient, comparé à son
 *   empreinte ; essais faux bornés. Le bon passe « en cours ».
 * - Dépôt : valeurs structurées (analyte, valeur, unité, normes, anormal)
 *   et/ou compte rendu PDF (type vérifié par le contenu, disque privé,
 *   quota par bon), drapeau critique. Idempotent par clé. Le bon passe
 *   « résultats déposés » ; le médecin prescripteur est alerté (à valider ;
 *   critique en plus si le drapeau est levé). Le patient ne voit rien avant
 *   la validation (PRE-07).
 */
final class DepotResultats
{
    public const DISQUE = 'local';

    /** Bons du laboratoire, du plus récent au plus ancien. */
    public function bonsDuLaboratoire(ActeurLaboratoire $acteur): array
    {
        return BonExamen::with(['lignes', 'patient:id,prenom,nom,date_naissance,sexe', 'medecin.user:id,prenom,nom'])
            ->where('laboratoire_id', $acteur->laboratoire->id)
            ->whereNotNull('paye_le')
            ->whereIn('statut', [StatutBonExamen::Paye->value, StatutBonExamen::EnCours->value, StatutBonExamen::ResultatsDeposes->value, StatutBonExamen::Valide->value])
            ->orderByDesc('paye_le')->limit(200)->get()
            ->map(fn (BonExamen $b) => $this->presenterPourLaboratoire($b))->all();
    }

    /** @throws LaboratoireRefuse */
    public function bon(ActeurLaboratoire $acteur, string $bonId): array
    {
        return $this->presenterPourLaboratoire($this->bonDuLaboratoire($acteur, $bonId));
    }

    /** Le bon, s'il est payé et transmis à CE laboratoire ; sinon la même réponse qu'un bon inconnu. @throws LaboratoireRefuse */
    public function bonDuLaboratoire(ActeurLaboratoire $acteur, string $bonIdOuNumero, bool $parNumero = false): BonExamen
    {
        $requete = BonExamen::where('laboratoire_id', $acteur->laboratoire->id)->whereNotNull('paye_le');
        $bon = $parNumero
            ? $requete->where('numero', $bonIdOuNumero)->first()
            : (Str::isUuid($bonIdOuNumero) ? $requete->whereKey($bonIdOuNumero)->first() : null);

        return $bon ?? throw new LaboratoireRefuse('Bon introuvable pour votre laboratoire.', LaboratoireRefuse::BON_INTROUVABLE);
    }

    /**
     * « Patient reçu » : code de retrait vérifié, bon « en cours ». Un second
     * envoi (double clic, API rejouée) sur un bon déjà reçu est sans effet.
     *
     * @throws LaboratoireRefuse
     */
    public function recevoir(ActeurLaboratoire $acteur, BonExamen $bon, string $code): BonExamen
    {
        $cleEssais = 'code-retrait:'.$acteur->laboratoire->id.':'.$bon->id;
        $essais = (int) config('doc-and-i.laboratoire.essais_code_par_heure');
        if (RateLimiter::tooManyAttempts($cleEssais, $essais)) {
            throw new LaboratoireRefuse('Trop d\'essais de code pour ce bon. Réessayez dans une heure, ou demandez au patient un nouveau code.', LaboratoireRefuse::TROP_D_ESSAIS);
        }

        return DB::transaction(function () use ($acteur, $bon, $code, $cleEssais) {
            $b = BonExamen::whereKey($bon->id)->lockForUpdate()->firstOrFail();
            if ($b->laboratoire_id !== $acteur->laboratoire->id || $b->paye_le === null) {
                throw new LaboratoireRefuse('Bon introuvable pour votre laboratoire.', LaboratoireRefuse::BON_INTROUVABLE);
            }
            if (in_array($b->statut, [StatutBonExamen::EnCours, StatutBonExamen::ResultatsDeposes, StatutBonExamen::Valide], true)) {
                return $b; // Déjà reçu : sans effet.
            }
            if ($b->statut !== StatutBonExamen::Paye) {
                throw new LaboratoireRefuse('Ce bon n\'est pas à faire (statut : '.mb_strtolower($b->statut->libelle()).').');
            }
            if ($b->code_retrait_empreinte === null) {
                throw new LaboratoireRefuse('Le patient n\'a pas encore obtenu son code de retrait : il le trouve dans son espace, « Mes examens ».');
            }
            if (! hash_equals($b->code_retrait_empreinte, BonsPatient::empreinteCode($b, $code))) {
                RateLimiter::hit($cleEssais, 3600);
                throw new LaboratoireRefuse('Code de retrait incorrect.', LaboratoireRefuse::CODE_RETRAIT);
            }

            RateLimiter::clear($cleEssais);
            $b->transitionVers(StatutBonExamen::EnCours);
            $b->recu_le = CarbonImmutable::now();
            $b->recu_par = $acteur->compte?->id;
            $b->save();

            return $b;
        });
    }

    /**
     * Dépôt de résultats. $pdf : octets du compte rendu (déjà décodés).
     *
     * @throws LaboratoireRefuse|ValidationException
     */
    public function deposer(ActeurLaboratoire $acteur, BonExamen $bon, array $saisie, ?string $pdf = null): ResultatExamen
    {
        $donnees = self::valider($saisie);
        if ($donnees['valeurs'] === null && $pdf === null) {
            throw ValidationException::withMessages(['valeurs' => 'Déposez des valeurs, un compte rendu PDF, ou les deux.']);
        }
        if ($pdf !== null) {
            DocumentPdf::verifier($pdf);
        }

        // Rejeu d'un dépôt déjà reçu : le même résultat, rien de plus.
        if ($donnees['cle'] !== null && ($existant = ResultatExamen::where('cle_idempotence', $donnees['cle'])->first())) {
            return $this->memeDepot($existant, $bon);
        }

        $chemin = null;
        if ($pdf !== null) {
            $chemin = 'resultats/'.$bon->id.'/'.Str::random(40);
            if (! Storage::disk(self::DISQUE)->put($chemin, $pdf)) {
                throw new LaboratoireRefuse('Le compte rendu n\'a pas pu être enregistré. Réessayez.');
            }
        }

        try {
            return DB::transaction(function () use ($acteur, $bon, $donnees, $pdf, $chemin) {
                $b = BonExamen::whereKey($bon->id)->lockForUpdate()->firstOrFail();
                if ($b->laboratoire_id !== $acteur->laboratoire->id || $b->paye_le === null) {
                    throw new LaboratoireRefuse('Bon introuvable pour votre laboratoire.', LaboratoireRefuse::BON_INTROUVABLE);
                }
                if ($b->statut === StatutBonExamen::Paye) {
                    throw new LaboratoireRefuse('Recevez d\'abord le patient avec son code de retrait.');
                }
                if (! in_array($b->statut, [StatutBonExamen::EnCours, StatutBonExamen::ResultatsDeposes], true)) {
                    throw new LaboratoireRefuse('Ce bon n\'accepte plus de résultats (statut : '.mb_strtolower($b->statut->libelle()).').');
                }
                if ($donnees['ligne_id'] !== null && ! LigneBonExamen::where('bon_id', $b->id)->whereKey($donnees['ligne_id'])->exists()) {
                    throw new LaboratoireRefuse('Cet examen ne fait pas partie du bon.');
                }
                $this->verifierQuota($b, $pdf === null ? 0 : strlen($pdf));

                $maintenant = CarbonImmutable::now();
                $resultat = (new ResultatExamen)->forceFill([
                    'bon_id' => $b->id,
                    'ligne_id' => $donnees['ligne_id'],
                    'valeurs' => $donnees['valeurs'],
                    'pdf_chemin' => $chemin,
                    'pdf_taille' => $pdf === null ? null : strlen($pdf),
                    'pdf_empreinte' => $pdf === null ? null : hash('sha256', $pdf),
                    'critique' => $donnees['critique'],
                    'depose_par' => $acteur->compte?->id,
                    'jeton_id' => $acteur->jeton?->id,
                    'source' => $acteur->source(),
                    'depose_le' => $maintenant,
                    'cle_idempotence' => $donnees['cle'],
                ]);
                $resultat->save();

                if ($b->statut === StatutBonExamen::EnCours) {
                    $b->transitionVers(StatutBonExamen::ResultatsDeposes);
                    $b->save();
                }
                $this->alerterMedecin($b, $resultat);

                return $resultat;
            });
        } catch (Throwable $e) {
            if ($chemin !== null) {
                Storage::disk(self::DISQUE)->delete($chemin);
            }
            if ($e instanceof UniqueConstraintViolationException && $donnees['cle'] !== null
                && ($existant = ResultatExamen::where('cle_idempotence', $donnees['cle'])->first())) {
                return $this->memeDepot($existant, $bon);
            }
            throw $e;
        }
    }

    /** @throws ValidationException */
    public static function valider(array $saisie): array
    {
        $valeurs = $saisie['valeurs'] ?? null;
        if (is_array($valeurs)) {
            $valeurs = array_values(array_map(function ($v) {
                $v = is_array($v) ? $v : [];
                $texte = fn ($cle) => is_scalar($v[$cle] ?? null) && trim((string) $v[$cle]) !== '' ? trim((string) $v[$cle]) : null;

                return [
                    'analyte' => $texte('analyte'),
                    'valeur' => $texte('valeur'),
                    'unite' => $texte('unite'),
                    'normes' => $texte('normes'),
                    'anormal' => filter_var($v['anormal'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }, $valeurs));
            // Lignes laissées vides dans le formulaire : ignorées.
            $valeurs = array_values(array_filter($valeurs, fn ($v) => $v['analyte'] !== null || $v['valeur'] !== null));
            $valeurs = $valeurs === [] ? null : $valeurs;
        }

        $donnees = [
            'valeurs' => $valeurs,
            'critique' => filter_var($saisie['critique'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'cle' => is_string($saisie['cle'] ?? null) && $saisie['cle'] !== '' ? $saisie['cle'] : null,
            'ligne_id' => is_string($saisie['ligne_id'] ?? null) && $saisie['ligne_id'] !== '' ? $saisie['ligne_id'] : null,
        ];
        $texte = fn (int $max, bool $requis = false) => [$requis ? 'required' : 'nullable', 'string', 'max:'.$max, 'not_regex:/[\x00-\x1F\x7F]/'];

        Validator::make($donnees, [
            'valeurs' => ['nullable', 'array', 'max:'.config('doc-and-i.laboratoire.valeurs_par_resultat')],
            'valeurs.*.analyte' => $texte(100, true),
            'valeurs.*.valeur' => $texte(100, true),
            'valeurs.*.unite' => $texte(30),
            'valeurs.*.normes' => $texte(100),
            'cle' => ['nullable', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'ligne_id' => ['nullable', 'uuid'],
        ], [
            'valeurs.*.analyte.required' => 'Chaque valeur a besoin de son analyte (par exemple « Glycémie »).',
            'valeurs.*.valeur.required' => 'Chaque analyte a besoin de sa valeur.',
            'cle.regex' => 'Clé d\'idempotence : lettres, chiffres et tirets, 16 à 64 caractères.',
            'cle.min' => 'Clé d\'idempotence : lettres, chiffres et tirets, 16 à 64 caractères.',
        ], ['valeurs.*.unite' => 'unité', 'valeurs.*.normes' => 'normes'])->validate();

        return $donnees;
    }

    private function verifierQuota(BonExamen $bon, int $octetsEnPlus): void
    {
        $deja = ResultatExamen::where('bon_id', $bon->id)->toBase()->selectRaw('COUNT(*) AS nombre, COALESCE(SUM(pdf_taille), 0) AS octets')->first();
        if ((int) $deja->nombre >= (int) config('doc-and-i.laboratoire.resultats_par_bon')) {
            throw new LaboratoireRefuse('Trop de dépôts pour ce bon : contactez MEDIHOME.');
        }
        $max = (int) config('doc-and-i.laboratoire.pdf_octets_par_bon');
        if ((int) $deja->octets + $octetsEnPlus > $max) {
            throw new LaboratoireRefuse('Quota de comptes rendus atteint pour ce bon ('.intdiv($max, 1024 * 1024).' Mo).');
        }
    }

    /**
     * Alertes au médecin prescripteur (PRE-08) : une « à valider » ouverte par
     * bon ; une « critique » par résultat critique. Messages sans donnée de santé.
     */
    private function alerterMedecin(BonExamen $bon, ResultatExamen $resultat): void
    {
        $aValider = AlerteMedecin::where('objet_type', 'bon_examen')->where('objet_id', $bon->id)
            ->where('type', 'resultat_a_valider')->whereNull('traitee_le')->exists();
        if (! $aValider) {
            (new AlerteMedecin)->forceFill([
                'medecin_id' => $bon->medecin_id, 'patient_id' => $bon->patient_id,
                'type' => 'resultat_a_valider', 'gravite' => 'urgent',
                'objet_type' => 'bon_examen', 'objet_id' => $bon->id,
                'message' => 'Résultats d\'examen à valider (bon '.$bon->numero.').',
            ])->save();
        }

        if ($resultat->critique) {
            (new AlerteMedecin)->forceFill([
                'medecin_id' => $bon->medecin_id, 'patient_id' => $bon->patient_id,
                'type' => 'resultat_critique', 'gravite' => 'critique',
                'objet_type' => 'resultat_examen', 'objet_id' => $resultat->id,
                'message' => 'Résultat critique à voir sans attendre (bon '.$bon->numero.').',
            ])->save();
        }
    }

    private function memeDepot(ResultatExamen $existant, BonExamen $bon): ResultatExamen
    {
        if ($existant->bon_id !== $bon->id) {
            throw new LaboratoireRefuse('Cette clé d\'envoi a déjà servi pour un autre bon.');
        }

        return $existant;
    }

    /** Ce que voit le laboratoire d'un bon : de quoi faire l'examen, rien d'autre. */
    private function presenterPourLaboratoire(BonExamen $b): array
    {
        $patient = $b->patient;

        return [
            'id' => $b->id,
            'numero' => $b->numero,
            'statut' => $b->statut->value,
            'statutLibelle' => $b->statut->libelle(),
            'payeLe' => $b->paye_le ? Affichage::dateHeure($b->paye_le) : null,
            'patient' => trim($patient->prenom.' '.mb_strtoupper((string) $patient->nom)),
            'age' => $patient->date_naissance ? (int) $patient->date_naissance->diffInYears(CarbonImmutable::now()) : null,
            'sexe' => match ($patient->sexe) {
                'F' => 'Femme', 'M' => 'Homme', default => null
            },
            'prescripteur' => Affichage::medecin($b->medecin),
            'examens' => $b->lignes->map(fn (LigneBonExamen $l) => ['id' => $l->id, 'rang' => $l->rang, 'libelle' => $l->libelle])->values()->all(),
            'renseignements' => $b->renseignements,
            'dateSouhaitee' => $b->date_souhaitee ? Affichage::jour($b->date_souhaitee) : null,
            'recu' => $b->recu_le !== null,
            'depots' => ResultatExamen::where('bon_id', $b->id)->count(),
        ];
    }
}
