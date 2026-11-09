<?php

namespace App\Services\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\BonExamen;
use App\Models\Examen;
use App\Models\LigneBonExamen;
use App\Models\Partenaire;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Dossier\OrdonnanceService;
use App\Services\Dossier\RedactionConsultation;
use App\Services\Paiement\FacturationService;
use App\Services\RendezVous\Affichage;
use App\Services\Teleconsultation\AccesRefuse;
use App\Sms\EnvoyeurSms;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Prescription d'examens (PRE-04), par le médecin de CE rendez-vous, aux
 * mêmes conditions que l'ordonnance (RedactionConsultation : pendant la
 * consultation ou après qu'elle est terminée).
 *
 * - Examens actifs du catalogue ayant un prix, tous dans une même monnaie ;
 *   libellé et prix recopiés dans les lignes, montant total figé.
 * - Laboratoire choisi par le médecin, ou laissé au choix du patient (qui le
 *   choisit avant de payer).
 * - Numéro BE-AAAA-NNNNNN sans trou, dans la transaction ; une même clé de
 *   formulaire rend le même bon (double clic).
 * - SMS au patient sans contenu médical (NOT-02).
 */
final class PrescriptionExamens
{
    public function __construct(
        private readonly RedactionConsultation $redaction,
        private readonly FacturationService $facturation,
    ) {}

    /** @throws AccesRefuse|LaboratoireRefuse|ValidationException */
    public function prescrire(User $medecin, RendezVous $rdv, array $saisie): BonExamen
    {
        $donnees = self::valider($saisie);

        try {
            [$bon, $nouveau] = DB::transaction(function () use ($medecin, $rdv, $donnees) {
                $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();
                $this->redaction->exigerRedacteur($medecin, $rdv);

                if ($existant = BonExamen::where('cle_idempotence', $donnees['cle'])->first()) {
                    return [$this->memeBon($existant, $rdv), false];
                }

                return [$this->creer($rdv, $donnees), true];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Deux envois simultanés de la même clé : le premier a gagné.
            $existant = BonExamen::where('cle_idempotence', $donnees['cle'])->first() ?? throw $e;

            return $this->memeBon($existant, $rdv);
        }

        if ($nouveau) {
            $this->prevenirPatient($bon);
        }

        return $bon;
    }

    /** Pour le composant de la salle et de la page « Note » : bons du rendez-vous, catalogue, laboratoires. @throws AccesRefuse */
    public function pourLeRendezVous(User $medecin, RendezVous $rdv): array
    {
        $this->redaction->exigerLecteur($medecin, $rdv);
        $redigeable = $this->redaction->peutRediger($rdv);

        return [
            'bons' => BonExamen::with(['lignes', 'laboratoire:id,nom'])->where('rendez_vous_id', $rdv->id)
                ->orderBy('created_at')->orderBy('numero')->get()
                ->map(fn (BonExamen $b) => self::presenter($b))->all(),
            'redigeable' => $redigeable,
            'catalogue' => $redigeable ? Examen::where('actif', true)->whereNotNull('prix')->orderBy('categorie')->orderBy('libelle')->get()
                ->map(fn (Examen $e) => [
                    'id' => $e->id, 'code' => $e->code, 'libelle' => $e->libelle, 'categorie' => $e->categorie,
                    'prix' => Montant::afficher((string) $e->prix, $e->devise), 'devise' => $e->devise, 'preparation' => $e->preparation,
                ])->all() : [],
            'laboratoires' => $redigeable ? self::laboratoiresActifs() : [],
        ];
    }

    public static function presenter(BonExamen $b): array
    {
        return [
            'id' => $b->id,
            'numero' => $b->numero,
            'statut' => $b->statut->value,
            'statutLibelle' => $b->statut->libelle(),
            'montant' => Montant::afficher((string) $b->montant, $b->devise),
            'laboratoire' => $b->laboratoire?->nom,
            'dateSouhaitee' => $b->date_souhaitee ? Affichage::jour($b->date_souhaitee) : null,
            'renseignements' => $b->renseignements,
            'prescritLe' => Affichage::dateHeure($b->created_at),
            'lignes' => $b->lignes->map(fn (LigneBonExamen $l) => ['rang' => $l->rang, 'libelle' => $l->libelle])->values()->all(),
        ];
    }

    /** @return list<array{id: string, nom: string, commune: ?string}> */
    public static function laboratoiresActifs(): array
    {
        return Partenaire::where('type', 'laboratoire')->where('actif', true)->orderBy('nom')->get(['id', 'nom', 'commune'])
            ->map(fn (Partenaire $p) => ['id' => $p->id, 'nom' => $p->nom, 'commune' => $p->commune])->all();
    }

    /** @throws ValidationException */
    public static function valider(array $saisie): array
    {
        $examens = is_array($saisie['examens'] ?? null) ? array_values($saisie['examens']) : [];
        $donnees = [
            'cle' => $saisie['cle'] ?? null,
            'examens' => $examens,
            'renseignements' => is_string($saisie['renseignements'] ?? null) && trim($saisie['renseignements']) !== '' ? trim($saisie['renseignements']) : null,
            'date_souhaitee' => is_string($saisie['date_souhaitee'] ?? null) && $saisie['date_souhaitee'] !== '' ? $saisie['date_souhaitee'] : null,
            'laboratoire_id' => is_string($saisie['laboratoire_id'] ?? null) && $saisie['laboratoire_id'] !== '' ? $saisie['laboratoire_id'] : null,
        ];
        $aujourdhui = CarbonImmutable::now('Africa/Kinshasa')->toDateString();

        Validator::make($donnees, [
            'cle' => ['required', 'uuid'],
            'examens' => ['required', 'array', 'min:1', 'max:'.config('doc-and-i.laboratoire.examens_par_bon')],
            'examens.*' => ['required', 'uuid', 'distinct'],
            'renseignements' => ['nullable', 'string', 'max:2000'],
            'date_souhaitee' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$aujourdhui, 'before_or_equal:'.CarbonImmutable::parse($aujourdhui)->addMonths(6)->toDateString()],
            'laboratoire_id' => ['nullable', 'uuid', Rule::exists('partenaires', 'id')->where('type', 'laboratoire')->where('actif', true)],
        ], [
            'cle.required' => 'Formulaire incomplet : rechargez la page.',
            'cle.uuid' => 'Formulaire incomplet : rechargez la page.',
            'examens.required' => 'Choisissez au moins un examen.',
            'examens.min' => 'Choisissez au moins un examen.',
            'examens.max' => 'Trop d\'examens pour un seul bon.',
            'examens.*.distinct' => 'Un examen est choisi deux fois.',
            'date_souhaitee.after_or_equal' => 'La date souhaitée ne peut pas être passée.',
            'date_souhaitee.before_or_equal' => 'La date souhaitée est trop lointaine (6 mois au plus).',
            'laboratoire_id.exists' => 'Ce laboratoire n\'est pas (ou plus) partenaire.',
        ], ['renseignements' => 'renseignements cliniques', 'date_souhaitee' => 'date souhaitée'])->validate();

        return $donnees;
    }

    /** Sous verrou du rendez-vous : lignes recopiées du catalogue, montant figé, numéro. */
    private function creer(RendezVous $rdv, array $donnees): BonExamen
    {
        $examens = Examen::whereIn('id', $donnees['examens'])->where('actif', true)->whereNotNull('prix')->get()->keyBy('id');
        if ($examens->count() !== count($donnees['examens'])) {
            throw new LaboratoireRefuse('Un des examens choisis n\'est plus disponible ou n\'a pas de prix. Rechargez la liste.');
        }
        $devises = $examens->pluck('devise')->unique();
        if ($devises->count() > 1) {
            throw new LaboratoireRefuse('Ces examens ne sont pas dans la même monnaie : faites un bon par monnaie.');
        }

        $centimes = $examens->sum(fn (Examen $e) => Montant::enCentimes((string) $e->prix));
        $bon = (new BonExamen)->forceFill([
            'numero' => $this->facturation->numeroSuivant('BE'),
            'rendez_vous_id' => $rdv->id,
            'medecin_id' => $rdv->medecin_id,
            'patient_id' => $rdv->patient_id,
            'laboratoire_id' => $donnees['laboratoire_id'],
            'statut' => StatutBonExamen::Prescrit,
            'renseignements' => $donnees['renseignements'],
            'date_souhaitee' => $donnees['date_souhaitee'],
            'montant' => Montant::depuisCentimes($centimes),
            'devise' => $devises->first(),
            'cle_idempotence' => $donnees['cle'],
        ]);
        $bon->save();

        foreach ($donnees['examens'] as $i => $id) {
            $examen = $examens[$id];
            (new LigneBonExamen)->forceFill([
                'bon_id' => $bon->id, 'examen_id' => $examen->id, 'rang' => $i + 1,
                'libelle' => $examen->libelle, 'prix' => $examen->prix,
            ])->save();
        }

        return $bon->load(['lignes', 'laboratoire:id,nom']);
    }

    private function memeBon(BonExamen $existant, RendezVous $rdv): BonExamen
    {
        if ($existant->rendez_vous_id !== $rdv->id) {
            throw new LaboratoireRefuse('Ce formulaire a déjà servi. Rechargez la page.');
        }

        return $existant->load(['lignes', 'laboratoire:id,nom']);
    }

    /** NOT-02 : aucun contenu médical ; une panne SMS ne défait rien (journal sans numéro). */
    private function prevenirPatient(BonExamen $bon): void
    {
        try {
            app(EnvoyeurSms::class)->envoyer($bon->patient->telephone, OrdonnanceService::SMS_EMISSION);
        } catch (Throwable $erreur) {
            Log::warning('SMS de bon d\'examen non envoyé', ['bon' => $bon->id, 'erreur' => $erreur::class]);
        }
    }
}
