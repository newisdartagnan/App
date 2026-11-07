<?php

namespace App\Services\Dossier;

use App\Models\AvenantNote;
use App\Models\NoteConsultation;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\RendezVous\Affichage;
use App\Services\Teleconsultation\AccesRefuse;
use App\Support\Empreinte;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Note de consultation structurée (DOS-02) et ses avenants (RG-11).
 *
 * - Brouillon : motif, examen, hypothèses, diagnostic (code CIM-11 et
 *   libellé), conduite à tenir ; enregistré automatiquement pendant
 *   l'appel. Rédigé par le médecin de CE rendez-vous seulement, pendant la
 *   consultation ou après (RedactionConsultation).
 * - Signée : empreinte SHA-256 de tout le contenu et date de signature ;
 *   ensuite plus rien ne change (modèle et déclencheur en base).
 * - Avenant : texte daté, signé et empreint, ajouté à une note signée.
 *
 * Diagnostic : le référentiel CIM-11 est à fournir par le client. En
 * attendant, code et libellé sont des champs libres au format contrôlé ;
 * le code n'est vérifié que dans sa forme, jamais complété ni deviné.
 */
final class NoteService
{
    public const TEXTE_MAX = 5000;

    /**
     * Forme d'un code CIM-11 : code souche (ex. « BA00 », « 1A00.0 »,
     * « CA40.Z ») éventuellement post-coordonné par « & » ou « / » avec
     * d'autres codes souches ou des codes d'extension (« X… »). Les lettres
     * I et O n'existent pas dans la CIM-11.
     */
    public const FORMAT_CIM11 = '/^(?:[1-9A-HJ-NP-Z][A-HJ-NP-Z][0-9][0-9A-HJ-NP-Z](?:\.[0-9A-HJ-NP-Z]{1,2})?)(?:[&\/](?:[1-9A-HJ-NP-Z][A-HJ-NP-Z][0-9][0-9A-HJ-NP-Z](?:\.[0-9A-HJ-NP-Z]{1,2})?|X[0-9A-HJ-NP-Z]{3,6}))*$/';

    public function __construct(private readonly RedactionConsultation $redaction) {}

    /** La note du rendez-vous, pour son médecin. @throws AccesRefuse */
    public function etat(User $medecin, RendezVous $rdv): array
    {
        $this->redaction->exigerLecteur($medecin, $rdv);
        $note = NoteConsultation::with('avenants.auteur:id,nom,prenom')->where('rendez_vous_id', $rdv->id)->first();

        return $this->presenter($note, $rdv);
    }

    /** Enregistre le brouillon (enregistrement automatique pendant l'appel). @throws AccesRefuse|ValidationException */
    public function enregistrer(User $medecin, RendezVous $rdv, array $saisie): array
    {
        $champs = self::valider($saisie);

        $note = DB::transaction(function () use ($medecin, $rdv, $champs) {
            $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();
            $this->redaction->exigerRedacteur($medecin, $rdv);

            return $this->ecrireBrouillon($rdv, $champs);
        });

        return $this->presenter($note->load('avenants.auteur:id,nom,prenom'), $rdv);
    }

    /**
     * Signe la note (avec la dernière saisie, si elle est jointe). Une note
     * déjà signée ne se resigne pas : le second clic est refusé, rien ne bouge.
     *
     * @throws AccesRefuse|ValidationException
     */
    public function signer(User $medecin, RendezVous $rdv, ?array $saisie = null): array
    {
        $champs = $saisie === null ? null : self::valider($saisie);

        $note = DB::transaction(function () use ($medecin, $rdv, $champs) {
            $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();
            $this->redaction->exigerRedacteur($medecin, $rdv);

            $note = $champs === null
                ? NoteConsultation::where('rendez_vous_id', $rdv->id)->lockForUpdate()->first()
                : $this->ecrireBrouillon($rdv, $champs);
            if ($note === null) {
                throw new AccesRefuse('Rédigez la note avant de la signer.');
            }
            if ($note->estSignee()) {
                throw new AccesRefuse('Cette note est déjà signée. Pour la compléter, ajoutez un avenant.');
            }
            if (blank($note->motif) || blank($note->conduite)) {
                throw new AccesRefuse('Pour signer, renseignez au moins le motif et la conduite à tenir.');
            }

            $note->signee_le = CarbonImmutable::now()->startOfSecond();
            $note->empreinte = Empreinte::de(self::contenuSigne($note));
            $note->save();

            return $note;
        });

        return $this->presenter($note->load('avenants.auteur:id,nom,prenom'), $rdv);
    }

    /**
     * Avenant à une note signée (RG-11) : texte, date, signature (l'auteur)
     * et empreinte. Par le médecin de CE rendez-vous. Public : la
     * rectification acceptée du dossier (DOS-04) s'en sert aussi.
     * Une même clé de formulaire rend le même avenant (double clic).
     *
     * @throws AccesRefuse|ValidationException
     */
    public function avenant(User $auteur, NoteConsultation $note, string $texte, ?string $cle = null): AvenantNote
    {
        $contenu = trim($texte);
        Validator::make(['contenu' => $contenu, 'cle' => $cle], [
            'contenu' => ['required', 'string', 'min:3', 'max:'.self::TEXTE_MAX],
            'cle' => ['nullable', 'uuid'],
        ], [], ['contenu' => 'avenant'])->validate();

        try {
            return DB::transaction(function () use ($auteur, $note, $contenu, $cle) {
                $note = NoteConsultation::whereKey($note->id)->lockForUpdate()->firstOrFail();
                $this->redaction->exigerLecteur($auteur, $note->rendezVous);

                if ($cle !== null && ($existant = AvenantNote::where('cle_idempotence', $cle)->first())) {
                    return $this->memeAvenant($existant, $note, $auteur);
                }
                if (! $note->estSignee()) {
                    throw new AccesRefuse('La note n\'est pas encore signée : modifiez-la directement.');
                }

                $avenant = new AvenantNote;
                $avenant->forceFill([
                    'note_id' => $note->id,
                    'auteur_id' => $auteur->id,
                    'contenu' => $contenu,
                    'signe_le' => CarbonImmutable::now()->startOfSecond(),
                    'cle_idempotence' => $cle,
                ]);
                $avenant->empreinte = Empreinte::de(self::contenuAvenant($avenant, $note));
                $avenant->save();

                return $avenant;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Deux envois simultanés de la même clé : le premier a gagné.
            $existant = $cle !== null ? AvenantNote::where('cle_idempotence', $cle)->first() : null;

            return $existant ? $this->memeAvenant($existant, $note, $auteur) : throw $e;
        }
    }

    /** Ce qui est signé : tout le contenu de la note, son rendez-vous, ses auteurs et la date. */
    public static function contenuSigne(NoteConsultation $note): array
    {
        $contenu = [
            'rendez_vous_id' => $note->rendez_vous_id,
            'medecin_id' => $note->medecin_id,
            'patient_id' => $note->patient_id,
            'signee_le' => self::horodatage($note->signee_le),
        ];
        foreach (NoteConsultation::CHAMPS as $champ) {
            $contenu[$champ] = $note->{$champ};
        }

        return $contenu;
    }

    public static function contenuAvenant(AvenantNote $avenant, NoteConsultation $note): array
    {
        return [
            'note_id' => $note->id,
            'note_empreinte' => $note->empreinte,
            'auteur_id' => $avenant->auteur_id,
            'contenu' => $avenant->contenu,
            'signe_le' => self::horodatage($avenant->signe_le),
        ];
    }

    /** L'empreinte recalculée correspond-elle à celle gardée ? */
    public static function estIntegre(NoteConsultation $note): bool
    {
        return $note->estSignee() && $note->empreinte !== null && Empreinte::verifier(self::contenuSigne($note), $note->empreinte);
    }

    /** Valide et normalise la saisie (espaces retirés, vide = null, code en majuscules). @throws ValidationException */
    public static function valider(array $saisie): array
    {
        $champs = [];
        foreach (NoteConsultation::CHAMPS as $champ) {
            $valeur = $saisie[$champ] ?? null;
            $valeur = is_string($valeur) ? trim($valeur) : $valeur;
            $champs[$champ] = $valeur === '' ? null : $valeur;
        }
        if (is_string($champs['diagnostic_code'])) {
            $champs['diagnostic_code'] = mb_strtoupper(preg_replace('/\s+/', '', $champs['diagnostic_code']));
        }

        $texte = ['nullable', 'string', 'max:'.self::TEXTE_MAX];
        Validator::make($champs, [
            'motif' => $texte,
            'examen' => $texte,
            'hypotheses' => $texte,
            'conduite' => $texte,
            'diagnostic_code' => ['nullable', 'string', 'max:20', 'regex:'.self::FORMAT_CIM11, 'required_with:diagnostic_libelle'],
            'diagnostic_libelle' => ['nullable', 'string', 'min:2', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/', 'required_with:diagnostic_code'],
        ], [
            'diagnostic_code.regex' => 'Le code CIM-11 n\'a pas la bonne forme (exemples : BA00, 1A00.0, CA40.Z).',
            'diagnostic_code.required_with' => 'Indiquez le code CIM-11 du diagnostic.',
            'diagnostic_libelle.required_with' => 'Indiquez le libellé du diagnostic.',
        ], [
            'motif' => 'motif', 'examen' => 'examen', 'hypotheses' => 'hypothèses', 'conduite' => 'conduite à tenir',
            'diagnostic_code' => 'code CIM-11', 'diagnostic_libelle' => 'libellé du diagnostic',
        ])->validate();

        return $champs;
    }

    /** Sous verrou du rendez-vous : crée ou met à jour le brouillon. */
    private function ecrireBrouillon(RendezVous $rdv, array $champs): NoteConsultation
    {
        $note = NoteConsultation::where('rendez_vous_id', $rdv->id)->lockForUpdate()->first();
        if ($note?->estSignee()) {
            throw new AccesRefuse('Cette note est signée : elle ne se modifie plus. Ajoutez un avenant.');
        }

        $note ??= (new NoteConsultation)->forceFill([
            'rendez_vous_id' => $rdv->id,
            'medecin_id' => $rdv->medecin_id,
            'patient_id' => $rdv->patient_id,
        ]);
        $note->forceFill($champs)->save();

        return $note;
    }

    private function memeAvenant(AvenantNote $existant, NoteConsultation $note, User $auteur): AvenantNote
    {
        if ($existant->note_id !== $note->id || $existant->auteur_id !== $auteur->id) {
            throw new AccesRefuse('Ce formulaire a déjà servi. Rechargez la page.');
        }

        return $existant;
    }

    private static function horodatage(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function presenter(?NoteConsultation $note, RendezVous $rdv): array
    {
        $champs = [];
        foreach (NoteConsultation::CHAMPS as $champ) {
            $champs[$champ] = $note?->{$champ};
        }
        // Point de départ : le motif déclaré par le patient, tant que rien n'est écrit.
        if ($note === null) {
            $champs['motif'] = $rdv->demande?->motif;
        }

        return [
            'existe' => $note !== null,
            'champs' => $champs,
            'signee' => (bool) $note?->estSignee(),
            'signeeLe' => $note?->signee_le ? Affichage::dateHeure($note->signee_le) : null,
            'empreinte' => $note?->empreinte,
            'enregistreeLe' => $note?->updated_at ? Affichage::heure($note->updated_at) : null,
            'modifiable' => ! $note?->estSignee() && $this->redaction->peutRediger($rdv),
            'avenants' => $note ? $note->avenants->map(fn (AvenantNote $a) => [
                'id' => $a->id,
                'contenu' => $a->contenu,
                'signeLe' => Affichage::dateHeure($a->signe_le),
                'auteur' => $a->auteur ? trim($a->auteur->prenom.' '.mb_strtoupper((string) $a->auteur->nom)) : null,
            ])->values()->all() : [],
        ];
    }
}
