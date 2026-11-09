<?php

namespace App\Services\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\AlerteMedecin;
use App\Models\BonExamen;
use App\Models\Medecin;
use App\Models\ResultatExamen;
use App\Models\User;
use App\Services\Dossier\AccesDossier;
use App\Services\Dossier\JournalAccesDossier;
use App\Services\Dossier\OrdonnanceService;
use App\Services\RendezVous\Affichage;
use App\Sms\EnvoyeurSms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Validation des résultats (PRE-07, RG-10, PRE-08).
 *
 * - Le médecin PRESCRIPTEUR voit les résultats déposés pour ses bons, les
 *   commente et les valide ; chaque lecture est notée au journal des accès
 *   du patient. Validé, un résultat ne change plus (déclencheur) ; le patient
 *   est prévenu par un SMS sans contenu et le voit dans « Mes examens ».
 * - Le coordinateur valide à la place du médecin (RG-10) seulement pour un
 *   bon escaladé « à valider depuis plus de 48 h » ; c'est tracé
 *   (valide_en_remplacement, valide_par).
 * - Tout validé : le bon passe « validé », les alertes du bon sont traitées.
 */
final class ValidationResultats
{
    public function __construct(private readonly AccesDossier $acces, private readonly JournalAccesDossier $journal) {}

    /** Bons du médecin qui attendent sa validation. @throws LaboratoireRefuse */
    public function aValider(User $user): array
    {
        $medecin = $this->medecin($user);

        return BonExamen::with(['patient:id,prenom,nom', 'laboratoire:id,nom'])
            ->where('medecin_id', $medecin->id)
            ->where('statut', StatutBonExamen::ResultatsDeposes->value)
            ->withCount(['resultats as a_valider' => fn ($q) => $q->whereNull('valide_le')])
            ->withExists(['resultats as critique' => fn ($q) => $q->whereNull('valide_le')->where('critique', true)])
            ->orderByDesc('critique')->orderBy('updated_at')->get()
            ->map(fn (BonExamen $b) => [
                'id' => $b->id, 'numero' => $b->numero,
                'patient' => trim($b->patient->prenom.' '.mb_strtoupper((string) $b->patient->nom)),
                'laboratoire' => $b->laboratoire?->nom,
                'aValider' => $b->a_valider, 'critique' => (bool) $b->critique,
            ])->all();
    }

    /** Le bon et ses résultats, pour le médecin prescripteur ou le coordinateur escaladé ; lecture journalisée. @throws LaboratoireRefuse */
    public function detail(User $user, string $bonId): array
    {
        $bon = $this->bonLisible($user, $bonId);
        $this->journal->noter($user, $bon->patient_id, 'resultats_examen', $bon->id);
        $bon->load(['lignes', 'laboratoire:id,nom', 'patient:id,prenom,nom,date_naissance,sexe', 'medecin.user:id,prenom,nom']);
        $peutValider = $this->peutValider($user, $bon);

        return [
            'bon' => [
                'id' => $bon->id, 'numero' => $bon->numero,
                'statut' => $bon->statut->value, 'statutLibelle' => $bon->statut->libelle(),
                'patient' => trim($bon->patient->prenom.' '.mb_strtoupper((string) $bon->patient->nom)),
                'age' => $bon->patient->date_naissance ? (int) $bon->patient->date_naissance->diffInYears(CarbonImmutable::now()) : null,
                'prescripteur' => Affichage::medecin($bon->medecin),
                'laboratoire' => $bon->laboratoire?->nom,
                'examens' => $bon->lignes->pluck('libelle')->all(),
                'renseignements' => $bon->renseignements,
            ],
            'resultats' => ResultatExamen::where('bon_id', $bon->id)->orderBy('depose_le')->orderBy('id')->get()
                ->map(fn (ResultatExamen $r) => BonsPatient::presenterResultat($r) + [
                    'valide' => $r->estValide(),
                    'enRemplacement' => $r->valide_en_remplacement,
                ])->all(),
            'peutValider' => $peutValider,
            'enRemplacement' => $peutValider && ! $this->estPrescripteur($user, $bon),
        ];
    }

    /**
     * Valide un résultat (avec un commentaire facultatif). Déjà validé : sans
     * effet (double clic), aucun second SMS.
     *
     * @throws LaboratoireRefuse
     */
    public function valider(User $user, string $resultatId, ?string $commentaire): ResultatExamen
    {
        $commentaire = $commentaire !== null && trim($commentaire) !== '' ? trim($commentaire) : null;
        if ($commentaire !== null && (mb_strlen($commentaire) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $commentaire))) {
            throw new LaboratoireRefuse('Commentaire trop long (2 000 caractères au plus) ou caractères non permis.');
        }

        $bonId = ResultatExamen::whereKey($resultatId)->value('bon_id') ?? throw new LaboratoireRefuse('Résultat introuvable.');

        [$resultat, $nouveau] = DB::transaction(function () use ($user, $bonId, $resultatId, $commentaire) {
            $bon = BonExamen::whereKey($bonId)->lockForUpdate()->firstOrFail();
            $resultat = ResultatExamen::whereKey($resultatId)->lockForUpdate()->firstOrFail();
            // Déjà validé (double clic, y compris sur le dernier résultat du bon) : sans effet.
            if ($resultat->estValide() && ($this->estPrescripteur($user, $bon) || $this->estCoordinateurEscalade($user, $bon))) {
                return [$resultat, false];
            }
            if (! $this->peutValider($user, $bon) || $resultat->estValide()) {
                throw new LaboratoireRefuse('Vous ne pouvez pas valider ces résultats.');
            }

            $maintenant = CarbonImmutable::now();
            $resultat->forceFill([
                'valide_par' => $user->id,
                'valide_le' => $maintenant,
                'commentaire_medecin' => $commentaire,
                'valide_en_remplacement' => ! $this->estPrescripteur($user, $bon),
            ])->save();

            // Le résultat critique est vu et traité.
            $critiques = AlerteMedecin::where('objet_type', 'resultat_examen')->where('objet_id', $resultat->id)->whereNull('traitee_le');
            (clone $critiques)->whereNull('lue_le')->update(['lue_le' => $maintenant]);
            $critiques->update(['traitee_le' => $maintenant, 'updated_at' => $maintenant]);

            if (! ResultatExamen::where('bon_id', $bon->id)->whereNull('valide_le')->exists()) {
                if ($bon->statut === StatutBonExamen::ResultatsDeposes) {
                    $bon->transitionVers(StatutBonExamen::Valide);
                    $bon->save();
                }
                AlerteMedecin::where('objet_type', 'bon_examen')->where('objet_id', $bon->id)->whereNull('traitee_le')
                    ->update(['traitee_le' => $maintenant, 'updated_at' => $maintenant]);
                DB::table('alertes_coordination')->where('bon_id', $bon->id)->whereNull('traitee_le')
                    ->update(['traitee_le' => $maintenant, 'traitee_par' => $user->id, 'updated_at' => $maintenant]);
            }

            return [$resultat, true];
        });

        if ($nouveau) {
            $this->prevenirPatient($resultat->bon);
        }

        return $resultat;
    }

    /** Le compte rendu PDF d'un résultat : médecin prescripteur ou coordinateur escaladé ; lecture journalisée. @throws LaboratoireRefuse */
    public function pdfPourSoignant(User $user, string $resultatId): StreamedResponse
    {
        $resultat = ResultatExamen::whereKey($resultatId)->whereNotNull('pdf_chemin')->first() ?? throw new LaboratoireRefuse('Compte rendu introuvable.');
        $bon = $this->bonLisible($user, $resultat->bon_id);
        $this->journal->noter($user, $bon->patient_id, 'resultat_pdf', $resultat->id);

        return self::telecharger($resultat, $bon);
    }

    /** Le compte rendu PDF pour le patient : le sien, VALIDÉ seulement. @throws LaboratoireRefuse */
    public function pdfPourPatient(User $patient, string $resultatId): StreamedResponse
    {
        $resultat = ResultatExamen::whereKey($resultatId)->whereNotNull('pdf_chemin')->whereNotNull('valide_le')
            ->whereHas('bon', fn ($q) => $q->where('patient_id', $patient->id))->first()
            ?? throw new LaboratoireRefuse('Compte rendu introuvable.');

        return self::telecharger($resultat, $resultat->bon);
    }

    public function estPrescripteur(User $user, BonExamen $bon): bool
    {
        $medecin = $this->acces->medecinDe($user);

        return $medecin !== null && $medecin->id === $bon->medecin_id;
    }

    /** Le coordinateur n'agit que sur un bon escaladé (critique non traité, ou validation en retard). */
    public function estCoordinateurEscalade(User $user, BonExamen $bon, ?string $type = null): bool
    {
        return $user->hasRole('coordinateur') && ! $user->estDesactive()
            && DB::table('alertes_coordination')->where('bon_id', $bon->id)->when($type, fn ($q) => $q->where('type', $type))->exists();
    }

    private function peutValider(User $user, BonExamen $bon): bool
    {
        if ($bon->statut !== StatutBonExamen::ResultatsDeposes) {
            return false;
        }

        // RG-10 : le coordinateur valide à la place du médecin, après 48 h seulement.
        return $this->estPrescripteur($user, $bon) || $this->estCoordinateurEscalade($user, $bon, 'validation_en_retard');
    }

    /** @throws LaboratoireRefuse */
    private function bonLisible(User $user, string $bonId): BonExamen
    {
        $bon = BonExamen::find($bonId);
        if ($bon === null || ! ($this->estPrescripteur($user, $bon) || $this->estCoordinateurEscalade($user, $bon))) {
            throw new LaboratoireRefuse('Ces résultats ne vous sont pas ouverts.');
        }

        return $bon;
    }

    /** @throws LaboratoireRefuse */
    private function medecin(User $user): Medecin
    {
        return $this->acces->medecinDe($user) ?? throw new LaboratoireRefuse('Espace réservé aux médecins.');
    }

    private static function telecharger(ResultatExamen $resultat, BonExamen $bon): StreamedResponse
    {
        $disque = Storage::disk(DepotResultats::DISQUE);
        if (! $disque->exists($resultat->pdf_chemin)) {
            throw new LaboratoireRefuse('Compte rendu introuvable.');
        }

        // Nom de fichier sans donnée de santé : le numéro du bon.
        return $disque->download($resultat->pdf_chemin, 'compte-rendu-'.$bon->numero.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** NOT-02 : SMS sans contenu ; une panne SMS ne défait rien (journal sans numéro). */
    private function prevenirPatient(BonExamen $bon): void
    {
        try {
            app(EnvoyeurSms::class)->envoyer($bon->patient->telephone, OrdonnanceService::SMS_EMISSION);
        } catch (Throwable $erreur) {
            Log::warning('SMS de résultats non envoyé', ['bon' => $bon->id, 'erreur' => $erreur::class]);
        }
    }
}
