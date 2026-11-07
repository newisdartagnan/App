<?php

namespace App\Services\Dossier;

use App\Models\AlertePrescription;
use App\Models\LigneOrdonnance;
use App\Models\Ordonnance;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiement\FacturationService;
use App\Services\RendezVous\Affichage;
use App\Services\Teleconsultation\AccesRefuse;
use App\Sms\EnvoyeurSms;
use App\Support\Empreinte;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ordonnance électronique (PRE-01, PRE-02, PRE-03).
 *
 * « Signer et émettre », dans UNE transaction, sous verrou du rendez-vous :
 * alertes (allergie, interaction) toutes maintenues, numéro OR-AAAA-NNNNNN
 * sans trou (compteur de la facturation, annulé avec la transaction),
 * empreinte SHA-256 du contenu, code de vérification aléatoire du QR code.
 * Ensuite rien ne change : une erreur se corrige par une nouvelle
 * ordonnance. Plusieurs ordonnances par rendez-vous sont possibles ; une
 * même clé de formulaire rend la même (double clic, page renvoyée).
 */
final class OrdonnanceService
{
    public const LIGNES_MAX = 20;

    /** NOT-02 : aucun contenu médical dans le SMS. */
    public const SMS_EMISSION = 'MEDIHOME : un document vous attend dans votre espace.';

    public function __construct(
        private readonly RedactionConsultation $redaction,
        private readonly AlertesPrescription $alertes,
        private readonly FacturationService $facturation,
    ) {}

    /** @throws AccesRefuse|AlertesAMaintenir|ValidationException */
    public function emettre(User $medecin, RendezVous $rdv, array $saisie): Ordonnance
    {
        $donnees = self::valider($saisie);

        try {
            [$ordonnance, $nouvelle] = DB::transaction(function () use ($medecin, $rdv, $donnees) {
                $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();
                $this->redaction->exigerRedacteur($medecin, $rdv);

                if ($existante = Ordonnance::where('cle_idempotence', $donnees['cle'])->first()) {
                    return [$this->memeOrdonnance($existante, $rdv), false];
                }

                $alertes = $this->alertes->pour($rdv->patient_id, $donnees['lignes']);
                $maintenues = array_map(fn ($a) => $a + ['maintenue' => in_array($a['cle'], $donnees['maintiens'], true)], $alertes);
                if (collect($maintenues)->contains('maintenue', false)) {
                    throw new AlertesAMaintenir($maintenues);
                }

                return [$this->creer($medecin, $rdv, $donnees, $alertes), true];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Deux envois simultanés de la même clé : le premier a gagné.
            $existante = Ordonnance::where('cle_idempotence', $donnees['cle'])->first() ?? throw $e;

            return $this->memeOrdonnance($existante, $rdv);
        }

        if ($nouvelle) {
            $this->prevenirPatient($ordonnance);
        }

        return $ordonnance;
    }

    /** Les ordonnances du rendez-vous, pour son médecin. @throws AccesRefuse */
    public function duRendezVous(User $medecin, RendezVous $rdv): array
    {
        $this->redaction->exigerLecteur($medecin, $rdv);

        return [
            'ordonnances' => Ordonnance::with('lignes')->where('rendez_vous_id', $rdv->id)
                ->orderBy('signee_le')->orderBy('numero')->get()
                ->map(fn (Ordonnance $o) => self::presenter($o))->all(),
            'redigeable' => $this->redaction->peutRediger($rdv),
        ];
    }

    /** Ce qui est signé : numéro, rendez-vous, auteurs, date, consignes et chaque ligne. */
    public static function contenuSigne(Ordonnance $ordonnance): array
    {
        return [
            'numero' => $ordonnance->numero,
            'rendez_vous_id' => $ordonnance->rendez_vous_id,
            'medecin_id' => $ordonnance->medecin_id,
            'patient_id' => $ordonnance->patient_id,
            'consignes' => $ordonnance->consignes,
            'signee_le' => $ordonnance->signee_le->utc()->format('Y-m-d\TH:i:s\Z'),
            'lignes' => $ordonnance->lignes->sortBy('rang')->map(function (LigneOrdonnance $l) {
                $ligne = ['rang' => $l->rang];
                foreach (LigneOrdonnance::CHAMPS as $champ) {
                    $ligne[$champ] = $l->{$champ};
                }

                return $ligne;
            })->values()->all(),
        ];
    }

    /** L'empreinte recalculée sur le contenu en base correspond-elle à celle de la signature ? */
    public static function estAuthentique(Ordonnance $ordonnance): bool
    {
        return Empreinte::verifier(self::contenuSigne($ordonnance->loadMissing('lignes')), $ordonnance->empreinte);
    }

    public static function presenter(Ordonnance $o): array
    {
        return [
            'id' => $o->id,
            'numero' => $o->numero,
            'signeeLe' => Affichage::dateHeure($o->signee_le),
            'consignes' => $o->consignes,
            'lignes' => $o->lignes->map(fn (LigneOrdonnance $l) => $l->only(['rang', ...LigneOrdonnance::CHAMPS]))->values()->all(),
        ];
    }

    /** @throws ValidationException */
    public static function valider(array $saisie): array
    {
        $lignes = [];
        foreach (array_values(is_array($saisie['lignes'] ?? null) ? $saisie['lignes'] : []) as $ligne) {
            $propre = [];
            foreach (LigneOrdonnance::CHAMPS as $champ) {
                $valeur = is_array($ligne) ? ($ligne[$champ] ?? null) : null;
                $valeur = is_string($valeur) ? trim(preg_replace('/\s+/', ' ', $valeur)) : $valeur;
                $propre[$champ] = $valeur === '' ? null : $valeur;
            }
            $lignes[] = $propre;
        }
        $donnees = [
            'cle' => $saisie['cle'] ?? null,
            'consignes' => is_string($saisie['consignes'] ?? null) && trim($saisie['consignes']) !== '' ? trim($saisie['consignes']) : null,
            'lignes' => $lignes,
            'maintiens' => array_values(array_filter(is_array($saisie['maintiens'] ?? null) ? $saisie['maintiens'] : [], 'is_string')),
        ];

        $texte = fn (int $max, bool $requis = true) => [$requis ? 'required' : 'nullable', 'string', 'max:'.$max, 'not_regex:/[\x00-\x1F\x7F]/'];
        Validator::make($donnees, [
            'cle' => ['required', 'uuid'],
            'consignes' => ['nullable', 'string', 'max:2000'],
            'lignes' => ['required', 'array', 'min:1', 'max:'.self::LIGNES_MAX],
            'lignes.*.medicament' => [...$texte(200), 'min:2'],
            'lignes.*.dosage' => $texte(100),
            'lignes.*.voie' => $texte(50),
            'lignes.*.posologie' => $texte(255),
            'lignes.*.duree' => $texte(100),
            'lignes.*.consignes' => $texte(255, false),
            'maintiens' => ['array', 'max:100'],
            'maintiens.*' => ['string', 'max:200'],
        ], [
            'cle.required' => 'Formulaire incomplet : rechargez la page.',
            'cle.uuid' => 'Formulaire incomplet : rechargez la page.',
            'lignes.required' => 'Ajoutez au moins un médicament.',
            'lignes.min' => 'Ajoutez au moins un médicament.',
        ], [
            'lignes.*.medicament' => 'médicament', 'lignes.*.dosage' => 'dosage', 'lignes.*.voie' => 'voie',
            'lignes.*.posologie' => 'posologie', 'lignes.*.duree' => 'durée', 'lignes.*.consignes' => 'consignes de la ligne',
        ])->validate();

        return $donnees;
    }

    /** Sous verrou, alertes maintenues : numéro, lignes, empreinte, code, trace des alertes. */
    private function creer(User $medecin, RendezVous $rdv, array $donnees, array $alertes): Ordonnance
    {
        $maintenant = CarbonImmutable::now()->startOfSecond();
        $ordonnance = (new Ordonnance)->forceFill([
            'numero' => $this->facturation->numeroSuivant('OR'),
            'rendez_vous_id' => $rdv->id,
            'medecin_id' => $rdv->medecin_id,
            'patient_id' => $rdv->patient_id,
            'consignes' => $donnees['consignes'],
            'signee_le' => $maintenant,
            'code_verification' => Str::random(32),
            'cle_idempotence' => $donnees['cle'],
        ]);

        $lignes = collect($donnees['lignes'])->values()->map(fn (array $l, int $i) => (new LigneOrdonnance)->forceFill(['rang' => $i + 1] + $l));
        $ordonnance->setRelation('lignes', $lignes);
        $ordonnance->empreinte = Empreinte::de(self::contenuSigne($ordonnance));
        $ordonnance->unsetRelation('lignes');
        $ordonnance->save();

        foreach ($lignes as $ligne) {
            $ligne->ordonnance_id = $ordonnance->id;
            $ligne->save();
        }
        foreach ($alertes as $alerte) {
            (new AlertePrescription)->forceFill([
                'ordonnance_id' => $ordonnance->id,
                'rang' => $alerte['rang'],
                'type' => $alerte['type'],
                'cle' => $alerte['cle'],
                'message' => Str::limit($alerte['message'], 497),
                'maintenue_par' => $medecin->id,
                'maintenue_le' => $maintenant,
            ])->save();
        }

        return $ordonnance->load('lignes');
    }

    private function memeOrdonnance(Ordonnance $existante, RendezVous $rdv): Ordonnance
    {
        if ($existante->rendez_vous_id !== $rdv->id) {
            throw new AccesRefuse('Ce formulaire a déjà servi. Rechargez la page.');
        }

        return $existante->load('lignes');
    }

    /** NOT-02 : un SMS sans contenu médical ; une panne SMS ne défait rien (journal sans numéro). */
    private function prevenirPatient(Ordonnance $ordonnance): void
    {
        try {
            app(EnvoyeurSms::class)->envoyer($ordonnance->patient->telephone, self::SMS_EMISSION);
        } catch (Throwable $erreur) {
            Log::warning('SMS d\'ordonnance non envoyé', ['ordonnance' => $ordonnance->id, 'erreur' => $erreur::class]);
        }
    }
}
