<?php

namespace App\Services;

use App\Models\Accouchement;
use App\Models\ConsultationPrenatale;
use App\Models\Grossesse;
use App\Models\NouveauNe;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maternité : la fiche obstétricale de bout en bout.
 */
class MaterniteService
{
    public function __construct(private readonly DossierNumberService $dossiers) {}

    /**
     * Ouvre une fiche de grossesse.
     *
     * Une patiente n'a qu'une grossesse en cours à la fois : rouvrir une fiche
     * alors qu'une autre court reviendrait à tenir deux carnets pour le même
     * ventre. On rend celle qui existe.
     */
    public function ouvrirGrossesse(Patient $patient, array $donnees): Grossesse
    {
        $existante = Grossesse::where('patient_id', $patient->id)
            ->where('statut', 'en_cours')
            ->first();

        if ($existante) {
            return $existante;
        }

        $ddr = filled($donnees['date_dernieres_regles'] ?? null)
            ? Carbon::parse($donnees['date_dernieres_regles'])
            : null;

        return Grossesse::create([
            'establishment_id' => $patient->establishment_id,
            'patient_id' => $patient->id,
            'date_dernieres_regles' => $ddr,
            // La date prévue se calcule, sauf si la clinique en impose une
            // autre (échographie de datation plus fiable que les règles).
            'date_prevue_accouchement' => filled($donnees['date_prevue_accouchement'] ?? null)
                ? Carbon::parse($donnees['date_prevue_accouchement'])
                : Grossesse::calculerDpa($ddr),
            'gestite' => $donnees['gestite'] ?? 1,
            'parite' => $donnees['parite'] ?? 0,
            'avortements' => $donnees['avortements'] ?? 0,
            'enfants_vivants' => $donnees['enfants_vivants'] ?? 0,
            'groupe_sanguin' => ($donnees['groupe_sanguin'] ?? null) ?: $patient->groupe_sanguin,
            'antecedents' => $donnees['antecedents'] ?? null,
            'serologies' => $donnees['serologies'] ?? [],
            'grossesse_a_risque' => (bool) ($donnees['grossesse_a_risque'] ?? false),
            'motif_risque' => $donnees['motif_risque'] ?? null,
            'statut' => 'en_cours',
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Enregistre une consultation prénatale.
     *
     * Le terme se calcule d'après les dernières règles : le personnel n'a pas
     * à le compter à chaque visite, et il ne se trompe pas.
     */
    public function enregistrerConsultation(Grossesse $grossesse, array $donnees): ConsultationPrenatale
    {
        $date = filled($donnees['date_consultation'] ?? null)
            ? Carbon::parse($donnees['date_consultation'])
            : now();

        return ConsultationPrenatale::create([
            ...$donnees,
            'grossesse_id' => $grossesse->id,
            'user_id' => auth()->id(),
            'date_consultation' => $date,
            'numero' => $grossesse->consultations()->count() + 1,
            'terme_semaines' => $donnees['terme_semaines'] ?? $grossesse->termeSemaines($date),
        ]);
    }

    /**
     * Enregistre l'accouchement et clôt la grossesse.
     *
     * Chaque enfant vivant reçoit son dossier de patient : il aura ses
     * vaccins, ses consultations, sa propre histoire. L'enfant mort-né reste
     * inscrit sans dossier — il doit être compté, pas suivi.
     *
     * @param  array<int, array<string, mixed>>  $enfants
     */
    public function enregistrerAccouchement(Grossesse $grossesse, array $donnees, array $enfants): Accouchement
    {
        return DB::transaction(function () use ($grossesse, $donnees, $enfants) {
            $date = Carbon::parse($donnees['date_accouchement']);

            $accouchement = Accouchement::create([
                ...$donnees,
                'grossesse_id' => $grossesse->id,
                'patient_id' => $grossesse->patient_id,
                'date_accouchement' => $date,
                'terme_semaines' => $donnees['terme_semaines'] ?? $grossesse->termeSemaines($date),
                'accoucheur_id' => $donnees['accoucheur_id'] ?? auth()->id(),
            ]);

            $vivants = 0;

            foreach (array_values($enfants) as $rang => $enfant) {
                $statut = $enfant['statut'] ?? 'vivant';

                $nouveauNe = NouveauNe::create([
                    ...$enfant,
                    'accouchement_id' => $accouchement->id,
                    'rang' => $rang + 1,
                    'statut' => $statut,
                    'patient_id' => $statut === 'vivant'
                        ? $this->creerDossierEnfant($grossesse, $accouchement, $enfant, $rang + 1)->id
                        : null,
                ]);

                if ($nouveauNe->estVivant()) {
                    $vivants++;
                }
            }

            // La grossesse est close, et le compteur obstétrical de la mère
            // avance : une parité de plus, autant d'enfants vivants en plus.
            $grossesse->update([
                'statut' => 'accouchee',
                'date_cloture' => $date->toDateString(),
                'parite' => $grossesse->parite + 1,
                'enfants_vivants' => $grossesse->enfants_vivants + $vivants,
            ]);

            return $accouchement->fresh('nouveauNes');
        });
    }

    /**
     * Ouvre le dossier du nouveau-né.
     *
     * Il porte le nom de sa mère et le prénom qu'on lui donne — souvent
     * « Nouveau-né de X » les premiers jours, le temps du baptême.
     */
    private function creerDossierEnfant(
        Grossesse $grossesse,
        Accouchement $accouchement,
        array $enfant,
        int $rang
    ): Patient {
        $mere = $grossesse->patient;

        return Patient::create([
            'establishment_id' => $grossesse->establishment_id,
            'dossier_number' => $this->dossiers->generate($grossesse->establishment_id),
            'nom' => $mere->nom,
            'postnom' => $mere->postnom,
            'prenom' => ($enfant['prenom'] ?? null) ?: 'Nouveau-né '.$rang,
            'sexe' => $enfant['sexe'] ?? 'M',
            'date_naissance' => $accouchement->date_accouchement->toDateString(),
            'lieu_naissance' => config('dpi.establishment_name', 'Maternité'),
            'type_prise_en_charge' => $mere->type_prise_en_charge,
            'assurance_nom' => $mere->assurance_nom,
            'assurance_numero' => $mere->assurance_numero,
            'contact_urgence_nom' => $mere->nom_complet,
            'contact_urgence_telephone' => $mere->telephone,
            'contact_urgence_lien' => 'Mère',
            'adresse' => $mere->adresse,
        ]);
    }

    /**
     * Indicateurs du registre des accouchements sur une période.
     *
     * @return array<string, mixed>
     */
    public function indicateurs(string $debut, string $fin): array
    {
        $accouchements = Accouchement::with('nouveauNes')
            ->whereBetween('date_accouchement', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->get();

        $enfants = $accouchements->flatMap->nouveauNes;

        return [
            'accouchements' => $accouchements->count(),
            'cesariennes' => $accouchements->where('mode', 'cesarienne')->count(),
            'prematures' => $accouchements->filter->estPremature()->count(),
            'hemorragies' => $accouchements->filter->estHemorragique()->count(),
            'deces_maternels' => $accouchements->where('etat_mere', 'deces')->count(),
            'naissances' => $enfants->count(),
            'vivants' => $enfants->where('statut', 'vivant')->count(),
            'mort_nes' => $enfants->where('statut', 'mort_ne')->count(),
            'petit_poids' => $enfants->filter->estPetitPoids()->count(),
            'par_mode' => $accouchements->groupBy(fn ($a) => $a->libelleMode())->map->count()->sortDesc(),
        ];
    }
}
