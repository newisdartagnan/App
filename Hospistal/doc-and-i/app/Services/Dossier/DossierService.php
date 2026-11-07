<?php

namespace App\Services\Dossier;

use App\Models\Antecedent;
use App\Models\Constante;
use App\Models\DemandeRectification;
use App\Models\DossierPatient;
use App\Models\NoteConsultation;
use App\Models\User;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Règles du dossier médical, côté saisie (DOS-01) et rectification (DOS-04).
 *
 * - Le patient déclare antécédents, allergies, traitements, constantes et
 *   groupe sanguin ; un médecin traitant (relation de soin) en ajoute ou en
 *   retire (source « medecin »).
 * - Rien ne se supprime : retirer note la date et l'auteur, l'élément reste
 *   dans l'historique.
 * - Le patient ne retire ni ne remplace ce qu'un médecin a saisi : il en
 *   demande la rectification.
 * - Aucune valeur supposée : « je ne sais pas » reste vide.
 * - Constantes bornées par des limites de vraisemblance ; calculs en
 *   centièmes entiers (Montant), jamais en flottant.
 * - Écritures sous verrou du dossier du patient : un double envoi ne crée
 *   pas de doublon.
 */
final class DossierService
{
    /** Bornes de vraisemblance, en unités affichées au patient. */
    public const CONSTANTES = [
        'poids' => ['libelle' => 'Poids', 'unite' => 'kg', 'min' => '0.5', 'max' => '400'],
        'taille' => ['libelle' => 'Taille', 'unite' => 'cm', 'min' => '30', 'max' => '250'],
        // Tension : systolique (valeur) / diastolique (valeur2).
        'tension' => ['libelle' => 'Tension artérielle', 'unite' => 'mmHg', 'min' => '50', 'max' => '300', 'min2' => '20', 'max2' => '200'],
        'temperature' => ['libelle' => 'Température', 'unite' => '°C', 'min' => '30', 'max' => '45'],
        'glycemie' => ['libelle' => 'Glycémie', 'unite' => 'g/L', 'min' => '0.2', 'max' => '6'],
        'frequence_cardiaque' => ['libelle' => 'Fréquence cardiaque', 'unite' => 'battements/min', 'min' => '20', 'max' => '250'],
    ];

    public const CATEGORIES = [
        'allergie' => 'Allergie',
        'traitement' => 'Traitement en cours',
        'medical' => 'Antécédent médical',
        'chirurgical' => 'Antécédent chirurgical',
        'familial' => 'Antécédent familial',
    ];

    public function __construct(private readonly AccesDossier $acces) {}

    /** @throws DossierRefuse */
    public function ajouterAntecedent(User $auteur, User $patient, string $categorie, string $libelle, ?string $precision = null): Antecedent
    {
        $source = $this->source($auteur, $patient);
        $libelle = trim($libelle);
        $precision = trim((string) $precision) === '' ? null : trim((string) $precision);

        if (! array_key_exists($categorie, self::CATEGORIES)) {
            throw new DossierRefuse('Choisissez le type d\'élément.');
        }
        if ($libelle === '' || mb_strlen($libelle) > 200) {
            throw new DossierRefuse('Décrivez l\'élément en 200 caractères au plus.');
        }
        if ($precision !== null && mb_strlen($precision) > 500) {
            throw new DossierRefuse('La précision tient en 500 caractères au plus.');
        }

        return $this->sousVerrou($patient, function () use ($auteur, $patient, $categorie, $libelle, $precision, $source) {
            // Double envoi : le même élément encore présent n'est pas ajouté deux fois.
            $existant = Antecedent::where('patient_id', $patient->id)->where('categorie', $categorie)
                ->whereRaw('lower(libelle) = lower(?)', [$libelle])->whereNull('retire_le')->first();
            if ($existant) {
                return $existant;
            }

            $antecedent = new Antecedent;
            $antecedent->forceFill([
                'patient_id' => $patient->id,
                'categorie' => $categorie,
                'libelle' => $libelle,
                'precision' => $precision,
                'saisi_par' => $auteur->id,
                'source' => $source,
            ])->save();

            return $antecedent;
        });
    }

    /** Retirer, jamais supprimer : date et auteur ; l'élément reste dans l'historique. @throws DossierRefuse */
    public function retirerAntecedent(User $auteur, Antecedent $antecedent): Antecedent
    {
        $patient = User::findOrFail($antecedent->patient_id);
        $source = $this->source($auteur, $patient);

        return $this->sousVerrou($patient, function () use ($auteur, $antecedent, $source) {
            $a = Antecedent::whereKey($antecedent->id)->lockForUpdate()->firstOrFail();

            if ($a->retire_le !== null) {
                throw new DossierRefuse('Cet élément est déjà retiré du dossier.');
            }
            if ($source === 'patient' && $a->source === 'medecin') {
                throw new DossierRefuse('Cet élément a été saisi par un médecin : demandez-en la rectification.');
            }

            $a->forceFill(['retire_le' => CarbonImmutable::now(), 'retire_par' => $auteur->id])->save();

            return $a;
        });
    }

    /**
     * @param  string  $valeur  saisie telle quelle (« 72,5 » ou « 72.5 »)
     * @param  ?string  $valeur2  diastolique, pour la tension seulement
     *
     * @throws DossierRefuse
     */
    public function ajouterConstante(User $auteur, User $patient, string $type, string $valeur, ?string $valeur2 = null, ?CarbonImmutable $mesureLe = null): Constante
    {
        $this->source($auteur, $patient);
        $regle = self::CONSTANTES[$type] ?? throw new DossierRefuse('Choisissez la mesure.');

        $v = $this->dansLesBornes($valeur, $regle['min'], $regle['max'], $regle['libelle'], $regle['unite']);
        $v2 = null;
        if ($type === 'tension') {
            $v2 = $this->dansLesBornes((string) $valeur2, $regle['min2'], $regle['max2'], 'Tension diastolique', $regle['unite']);
            if (Montant::enCentimes($v2) >= Montant::enCentimes($v)) {
                throw new DossierRefuse('La tension maximale (systolique) doit être supérieure à la minimale (diastolique).');
            }
        } elseif ($valeur2 !== null && trim($valeur2) !== '') {
            throw new DossierRefuse('Une seule valeur pour cette mesure.');
        }

        $maintenant = CarbonImmutable::now();
        $mesureLe = ($mesureLe ?? $maintenant)->startOfMinute();
        if ($mesureLe > $maintenant->addMinutes(5)) {
            throw new DossierRefuse('La date de mesure ne peut pas être dans le futur.');
        }
        if ($mesureLe < $maintenant->subYears(5)) {
            throw new DossierRefuse('Cette mesure est trop ancienne pour être utile.');
        }

        return $this->sousVerrou($patient, function () use ($auteur, $patient, $type, $v, $v2, $mesureLe) {
            $existante = Constante::where('patient_id', $patient->id)->where('type', $type)->where('saisi_par', $auteur->id)
                ->where('valeur', $v)->where('mesure_le', $mesureLe)
                ->when($v2 === null, fn ($q) => $q->whereNull('valeur2'), fn ($q) => $q->where('valeur2', $v2))
                ->first();
            if ($existante) {
                return $existante;
            }

            $constante = new Constante;
            $constante->forceFill([
                'patient_id' => $patient->id,
                'type' => $type,
                'valeur' => $v,
                'valeur2' => $v2,
                'mesure_le' => $mesureLe,
                'saisi_par' => $auteur->id,
            ])->save();

            return $constante;
        });
    }

    /**
     * Groupe sanguin : null = « je ne sais pas » (jamais une valeur supposée).
     * Le patient ne remplace pas un groupe saisi par un médecin.
     *
     * @throws DossierRefuse
     */
    public function definirGroupeSanguin(User $auteur, User $patient, ?string $groupe): DossierPatient
    {
        $source = $this->source($auteur, $patient);
        if ($groupe !== null && ! in_array($groupe, DossierPatient::GROUPES_SANGUINS, true)) {
            throw new DossierRefuse('Groupe sanguin inconnu.');
        }

        return $this->sousVerrou($patient, function () use ($auteur, $patient, $groupe, $source) {
            $dossier = $this->dossier($patient);

            if ($source === 'patient' && $dossier->groupe_sanguin_par !== null && $dossier->groupe_sanguin_par !== $patient->id) {
                throw new DossierRefuse('Votre groupe sanguin a été saisi par un médecin : demandez-en la rectification.');
            }
            if ($dossier->groupe_sanguin === $groupe) {
                return $dossier;
            }

            $dossier->forceFill([
                'groupe_sanguin' => $groupe,
                'groupe_sanguin_par' => $groupe === null ? null : $auteur->id,
                'groupe_sanguin_le' => $groupe === null ? null : CarbonImmutable::now(),
            ])->save();

            return $dossier;
        });
    }

    /** DOS-04 : le patient demande la correction de son dossier. @throws DossierRefuse */
    public function demanderRectification(User $patient, string $texte): DemandeRectification
    {
        if (! $this->acces->estLePatient($patient, $patient)) {
            throw new DossierRefuse('Seul le patient demande la rectification de son dossier.');
        }
        $texte = trim($texte);
        if (mb_strlen($texte) < 10 || mb_strlen($texte) > 2000) {
            throw new DossierRefuse('Expliquez ce qu\'il faut corriger (10 à 2000 caractères).');
        }

        return $this->sousVerrou($patient, function () use ($patient, $texte) {
            $existante = DemandeRectification::where('patient_id', $patient->id)->where('statut', 'ouverte')->where('demande', $texte)->first();
            if ($existante) {
                return $existante;
            }

            $demande = new DemandeRectification;
            $demande->forceFill(['patient_id' => $patient->id, 'demande' => $texte, 'statut' => 'ouverte'])->save();

            return $demande;
        });
    }

    /**
     * DOS-04 : un médecin traitant accepte ou refuse, avec un motif. Une note
     * se corrige par avenant (service de la note) : la réponse y renvoie ;
     * un antécédent se corrige par retrait puis ajout.
     *
     * @throws DossierRefuse
     */
    public function repondreRectification(User $medecin, DemandeRectification $demande, bool $accepter, string $motif, ?string $noteId = null): DemandeRectification
    {
        $patient = User::findOrFail($demande->patient_id);
        if (! $this->acces->medecinTraitant($medecin, $patient)) {
            throw new DossierRefuse('Ce dossier ne vous est pas ouvert.');
        }
        $motif = trim($motif);
        if (mb_strlen($motif) < 3 || mb_strlen($motif) > 1000) {
            throw new DossierRefuse('Donnez le motif de votre réponse (3 à 1000 caractères).');
        }
        if ($noteId !== null && ! NoteConsultation::whereKey($noteId)->where('patient_id', $patient->id)->whereNotNull('signee_le')->exists()) {
            throw new DossierRefuse('Cette note n\'est pas une note signée de ce patient.');
        }

        return $this->sousVerrou($patient, function () use ($medecin, $demande, $accepter, $motif, $noteId) {
            $d = DemandeRectification::whereKey($demande->id)->lockForUpdate()->firstOrFail();
            if ($d->statut !== 'ouverte') {
                throw new DossierRefuse('Cette demande a déjà reçu une réponse.');
            }

            $d->forceFill([
                'statut' => $accepter ? 'acceptee' : 'refusee',
                'reponse' => $motif,
                'note_id' => $accepter ? $noteId : null,
                'traitee_par' => $medecin->id,
                'traitee_le' => CarbonImmutable::now(),
            ])->save();

            return $d;
        });
    }

    public function dossier(User $patient): DossierPatient
    {
        $dossier = DossierPatient::where('patient_id', $patient->id)->first();
        if ($dossier) {
            return $dossier;
        }

        $dossier = new DossierPatient;
        $dossier->forceFill(['patient_id' => $patient->id])->save();

        return $dossier;
    }

    /** « patient » ou « medecin » selon l'auteur ; refus pour tout autre. @throws DossierRefuse */
    private function source(User $auteur, User $patient): string
    {
        return match (true) {
            $this->acces->estLePatient($auteur, $patient) => 'patient',
            $this->acces->medecinTraitant($auteur, $patient) => 'medecin',
            default => throw new DossierRefuse('Ce dossier ne vous est pas ouvert.'),
        };
    }

    /** Valeur décimale lue en centièmes entiers, bornée. @throws DossierRefuse */
    private function dansLesBornes(string $saisie, string $min, string $max, string $libelle, string $unite): string
    {
        $saisie = str_replace([',', ' '], ['.', ''], trim($saisie));
        try {
            $centiemes = Montant::enCentimes($saisie);
        } catch (InvalidArgumentException) {
            throw new DossierRefuse("{$libelle} : valeur illisible.");
        }

        if ($centiemes < Montant::enCentimes($min) || $centiemes > Montant::enCentimes($max)) {
            [$bas, $haut] = str_replace('.', ',', [$min, $max]);

            throw new DossierRefuse("{$libelle} : la valeur doit être entre {$bas} et {$haut} {$unite}. Vérifiez la mesure.");
        }

        return Montant::depuisCentimes($centiemes);
    }

    /** Écritures du dossier d'un patient l'une après l'autre (double envoi, réponses simultanées). */
    private function sousVerrou(User $patient, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($patient, $operation) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['dossier:'.$patient->id]);

            return $operation();
        });
    }
}
