<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\ResultatExamen;
use App\Models\TypeConsultation;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Support\Plateau;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que l'usage a révélé et que les tests ne voyaient pas.
 *
 * Un filtre qui ne filtre pas, un rendez-vous qu'on ne peut pas remettre au
 * patient, un registre d'imagerie qui parle de laborantins, des colonnes
 * vides à chaque ligne : rien de tout cela n'empêche l'application de
 * fonctionner, et tout cela suffit à faire douter de l'outil.
 */
class CorrectionsAuditTerrainTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);
    }

    protected function patient(string $nom, string $dossier): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => $dossier,
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => 'F',
            'date_naissance' => now()->subYears(30)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function visiteConsultee(Patient $patient): Visit
    {
        $visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'termine',
            'date_entree' => now()->subDay(),
            'motif_consultation' => 'Contrôle',
        ]);

        Consultation::create([
            'visit_id' => $visite->id,
            'user_id' => $this->admin->id,
            'date_consultation' => now()->subDay(),
            'diagnostics' => [['libelle' => 'RAS', 'type' => 'principal']],
            'statut' => 'finalise',
        ]);

        return $visite;
    }

    // ═══════════════════════════════════════════════════════════
    // Le filtre de la file d'attente
    // ═══════════════════════════════════════════════════════════

    public function test_le_filtre_de_recherche_filtre_vraiment(): void
    {
        $this->visiteConsultee($this->patient('KIBWANGA', 'PAT-F-0001'));
        $this->visiteConsultee($this->patient('MWEMBO', 'PAT-F-0002'));

        // Les filtres passaient par un script : on tapait un nom et la liste
        // ne bougeait pas.
        $this->get(route('consultations.index'))
            ->assertOk()->assertSee('KIBWANGA')->assertSee('MWEMBO');

        $this->get(route('consultations.index', ['recherche' => 'KIBWANGA']))
            ->assertOk()->assertSee('KIBWANGA')->assertDontSee('MWEMBO');
    }

    public function test_le_filtre_par_numero_de_dossier_marche_aussi(): void
    {
        $this->visiteConsultee($this->patient('KIBWANGA', 'PAT-F-0001'));
        $this->visiteConsultee($this->patient('MWEMBO', 'PAT-F-0002'));

        $this->get(route('consultations.index', ['recherche' => 'PAT-F-0002']))
            ->assertOk()->assertSee('MWEMBO')->assertDontSee('KIBWANGA');
    }

    public function test_le_filtre_par_jour_et_par_statut_marche(): void
    {
        $ancienne = $this->visiteConsultee($this->patient('ANCIEN', 'PAT-F-0003'));
        $ancienne->update(['date_entree' => now()->subMonth()]);

        $recente = $this->visiteConsultee($this->patient('RECENT', 'PAT-F-0004'));

        $this->get(route('consultations.index', ['date' => $recente->date_entree->toDateString()]))
            ->assertOk()->assertSee('RECENT')->assertDontSee('ANCIEN');

        $recente->update(['statut' => 'en_cours']);

        $this->get(route('consultations.index', ['statut' => 'en_cours']))
            ->assertOk()->assertSee('RECENT')->assertDontSee('ANCIEN');
    }

    public function test_le_filtre_se_met_en_favori(): void
    {
        $this->visiteConsultee($this->patient('KIBWANGA', 'PAT-F-0001'));

        // L'adresse porte le filtre : elle se partage et se remet en favori.
        $this->get(route('consultations.index', ['recherche' => 'KIBWANGA']))
            ->assertOk()
            ->assertSee('name="recherche"', false)
            ->assertSee('value="KIBWANGA"', false)
            ->assertSee('Tout afficher');
    }

    public function test_lecran_des_consultations_ne_depend_plus_dun_script(): void
    {
        $this->visiteConsultee($this->patient('KIBWANGA', 'PAT-F-0001'));

        $this->get(route('consultations.index'))
            ->assertOk()
            ->assertDontSee('wire:model', false);
    }

    // ═══════════════════════════════════════════════════════════
    // La convocation remise au patient
    // ═══════════════════════════════════════════════════════════

    protected function rendezVous(): RendezVous
    {
        // L'agenda ne liste que les médecins et infirmiers chefs.
        $this->admin->assignRole('medecin');

        return RendezVous::create([
            'establishment_id' => $this->etab->id,
            'patient_id' => $this->patient('NGOMA', 'PAT-RV-0001')->id,
            'prestataire_id' => $this->admin->id,
            'type_consultation_id' => TypeConsultation::where('est_actif', true)->value('id'),
            'cree_par' => $this->admin->id,
            'debut' => now()->addWeek()->setTime(9, 30),
            'duree_minutes' => 30,
            'statut' => 'fixe',
            'motif' => 'Contrôle post-opératoire',
            'contact' => '0810000000',
        ]);
    }

    public function test_le_rendez_vous_simprime_pour_le_patient(): void
    {
        $rdv = $this->rendezVous();

        // Un rendez-vous que le patient ne repart pas avec sur un papier est
        // un rendez-vous oublié.
        $this->get(route('agenda.convocation', $rdv))
            ->assertOk()
            ->assertSee('RENDEZ-VOUS')
            ->assertSee('NGOMA')
            ->assertSee($rdv->debut->format('H:i'))
            ->assertSee('Contrôle post-opératoire')
            ->assertSee('Présentez-vous 15 minutes avant');
    }

    public function test_lagenda_offre_le_lien_dimpression(): void
    {
        $rdv = $this->rendezVous();

        $this->get(route('agenda.index', [
            'jour' => $rdv->debut->toDateString(),
            'prestataire_id' => $this->admin->id,
        ]))
            ->assertOk()
            ->assertSee('Convocation')
            ->assertSee(route('agenda.convocation', $rdv), false);
    }

    public function test_une_indisponibilite_ne_simprime_pas(): void
    {
        $bloque = RendezVous::create([
            'establishment_id' => $this->etab->id,
            'prestataire_id' => $this->admin->id,
            'cree_par' => $this->admin->id,
            'debut' => now()->addWeek(),
            'duree_minutes' => 60,
            'statut' => 'bloque',
            'motif' => 'Réunion de service',
        ]);

        // Un créneau bloqué n'est pas un rendez-vous : il n'y a personne à convoquer.
        $this->get(route('agenda.convocation', $bloque))->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════════
    // L'imagerie parle sa langue
    // ═══════════════════════════════════════════════════════════

    protected function examen(string $domaine): ExamenLaboratoire
    {
        $patient = $this->patient('BOSEKOTA', 'PAT-IM-'.random_int(1000, 9999));

        $examen = ExamenLaboratoire::create([
            'numero_bon' => ($domaine === 'imagerie' ? 'IMG' : 'LAB').'-2026-'.random_int(1000, 9999),
            'patient_id' => $patient->id,
            'prescripteur_id' => $this->admin->id,
            'laborantin_id' => $this->admin->id,
            'date_prescription' => now(),
            'date_resultat' => now(),
            'statut' => 'valide',
            'domaine' => $domaine,
            'conclusion' => 'Pas de lésion visible.',
        ]);

        $type = TypeExamen::where('domaine', $domaine)->firstOrFail();

        ResultatExamen::create([
            'examen_id' => $examen->id,
            'type_examen_id' => $type->id,
            'parametre' => $type->libelle,
            'valeur_brute' => 'Sans particularité',
            'valeurs_reference' => [],
        ]);

        return $examen->fresh();
    }

    public function test_le_rapport_dimagerie_ne_parle_plus_de_laborantins(): void
    {
        $this->examen('imagerie');

        $reponse = $this->get(route('labo.rapport', ['domaine' => 'imagerie', 'date' => now()->toDateString()]))
            ->assertOk();

        // Un radiologue ne fait pas d'analyses et n'est pas un laborantin.
        $reponse->assertSee('REGISTRE JOURNALIER DES EXAMENS D')
            ->assertSee('Radiologue')
            ->assertDontSee('Laborantin')
            ->assertDontSee('REGISTRE JOURNALIER DES ANALYSES');
    }

    public function test_le_rapport_du_laboratoire_garde_son_vocabulaire(): void
    {
        $this->examen('labo');

        $this->get(route('labo.rapport', ['domaine' => 'labo', 'date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Laborantin')
            ->assertSee(mb_strtoupper('Registre journalier des analyses'), false);
    }

    public function test_le_rapport_ramene_au_plateau_dont_il_vient(): void
    {
        $this->examen('imagerie');

        // Il n'y avait aucune flèche de retour : on restait coincé sur le registre.
        $this->get(route('labo.rapport', ['domaine' => 'imagerie']))
            ->assertOk()
            ->assertSee(route('imagerie.index'), false);

        $this->get(route('labo.rapport', ['domaine' => 'labo']))
            ->assertOk()
            ->assertSee(route('labo.index'), false);
    }

    public function test_limagerie_na_ni_reference_ni_interpretation(): void
    {
        $examen = $this->examen('imagerie');

        // Une échographie n'a pas de valeur de référence : la colonne était
        // vide à chaque ligne.
        $this->get(route('labo.show', $examen))
            ->assertOk()
            ->assertSee('Examens réalisés')
            ->assertDontSee('Interprétation')
            ->assertDontSee('Référence');

        $this->get(route('labo.rapport', ['domaine' => 'imagerie', 'date' => now()->toDateString()]))
            ->assertOk()
            ->assertDontSee('Interp.');
    }

    public function test_le_laboratoire_garde_ses_colonnes(): void
    {
        $examen = $this->examen('labo');

        $this->get(route('labo.show', $examen))
            ->assertOk()
            ->assertSee('Référence')
            ->assertSee('Interprétation');
    }

    public function test_le_vocabulaire_de_chaque_plateau_est_tenu_en_un_seul_endroit(): void
    {
        $this->assertSame('Radiologue', Plateau::mot('imagerie', 'operateur'));
        $this->assertSame('Laborantin', Plateau::mot('labo', 'operateur'));
        $this->assertFalse(Plateau::aDesValeursDeReference('imagerie'));
        $this->assertTrue(Plateau::aDesValeursDeReference('labo'));
        // Un domaine inconnu retombe sur le laboratoire, jamais sur rien.
        $this->assertSame('Laborantin', Plateau::mot(null, 'operateur'));
    }

    // ═══════════════════════════════════════════════════════════
    // Les flèches de retour
    // ═══════════════════════════════════════════════════════════

    public function test_la_fiche_dexamen_ramene_a_son_plateau(): void
    {
        $this->get(route('labo.show', $this->examen('imagerie')))
            ->assertOk()->assertSee('← Imagerie');

        $this->get(route('labo.show', $this->examen('labo')))
            ->assertOk()->assertSee('← Laboratoire');
    }

    public function test_aucune_fleche_ne_dit_seulement_retour(): void
    {
        // « Retour » ne dit pas où l'on va : chaque flèche nomme sa destination.
        $vues = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $fichier) {
            if (! str_ends_with((string) $fichier, '.blade.php')) {
                continue;
            }

            if (preg_match('/←\s*Retour\s*</u', file_get_contents((string) $fichier))) {
                $vues[] = str_replace(resource_path('views').'/', '', (string) $fichier);
            }
        }

        $this->assertSame([], $vues, 'Flèches sans destination nommée : '.implode(', ', $vues));
    }

    public function test_lecran_de_prescription_ramene_au_sejour(): void
    {
        $visite = $this->visiteConsultee($this->patient('NSIMBA', 'PAT-P-0001'));

        $this->get(route('labo.create', ['visit_id' => $visite->id, 'domaine' => 'imagerie']))
            ->assertOk()
            ->assertSee('← Le séjour')
            ->assertSee(route('visites.show', $visite), false);
    }

    public function test_le_releve_de_temps_ramene_dou_lon_vient(): void
    {
        $this->get(route('parcours.moi'))->assertOk()->assertSee('← Accueil');

        $autre = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'MAKAYA', 'prenom' => 'Jean', 'matricule' => 'MED-777',
            'password' => 'motdepasse123', 'is_active' => true,
        ]);
        $autre->assignRole('medecin');

        $this->get(route('parcours.profil', $autre))
            ->assertOk()
            ->assertSee('← Comptes du personnel');
    }
}
