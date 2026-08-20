<?php

namespace Tests\Feature;

use App\Models\ActeClinique;
use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\Lit;
use App\Models\Medicament;
use App\Models\NotificationInterne;
use App\Models\Patient;
use App\Models\Service;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\LaboratoireService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifications inter-services, dossier de séjour, tarification au prorata
 * des sous-examens et registre journalier du laboratoire.
 */
class NotificationsServicesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Patient $patient;

    protected Visit $visit;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->user);

        $this->etab = Establishment::firstOrFail();
        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'TST-2026-000900',
            'nom' => 'MUKENDI',
            'postnom' => 'KABASELE',
            'prenom' => 'Jean',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(42)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
        $this->visit = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'consultation_externe',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => 'Fièvre',
        ]);
    }

    /** Panel multi-paramètres du catalogue, pour les tests de prorata. */
    protected function panel(): TypeExamen
    {
        return TypeExamen::where('est_actif', true)->get()
            ->first(fn ($t) => count($t->valeurs_reference['parametres'] ?? []) >= 3)
            ?? $this->fail('Aucun panel multi-paramètres dans le catalogue.');
    }

    public function test_panel_complet_facture_le_prix_entier(): void
    {
        $panel = $this->panel();

        $examen = app(LaboratoireService::class)
            ->prescrireExamens($this->visit, [$panel->id], 'labo');
        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        $this->assertEqualsWithDelta((float) $panel->prix, (float) $facture->total_ttc, 0.01);
    }

    public function test_panel_partiel_facture_au_prorata_des_sous_examens(): void
    {
        $panel = $this->panel();
        $parametres = $panel->valeurs_reference['parametres'];
        $total = count($parametres);
        $choisis = array_column(array_slice($parametres, 0, 2), 'nom');

        $examen = app(LaboratoireService::class)->prescrireExamens(
            $this->visit, [$panel->id], 'labo', false, null, [$panel->id => $choisis]
        );
        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        $attendu = round((float) $panel->prix * 2 / $total, 2);

        $this->assertCount(2, $examen->resultats);
        $this->assertEqualsWithDelta($attendu, (float) $facture->total_ttc, 0.01);
        $this->assertLessThan((float) $panel->prix, (float) $facture->total_ttc);
        $this->assertStringContainsString("2/{$total} sous-examens", $facture->lignes->first()->libelle);
    }

    public function test_prix_sous_examen_est_le_prix_du_panel_divise(): void
    {
        $panel = $this->panel();
        $nb = count($panel->valeurs_reference['parametres']);

        $this->assertEqualsWithDelta(
            round((float) $panel->prix / $nb, 2),
            $panel->prixSousExamen(),
            0.01
        );
    }

    public function test_la_prescription_notifie_le_laboratoire(): void
    {
        $type = TypeExamen::where('est_actif', true)->firstOrFail();

        $examen = app(LaboratoireService::class)
            ->prescrireExamens($this->visit, [$type->id], 'labo');

        $notif = NotificationInterne::where('reference_id', $examen->id)->firstOrFail();

        $this->assertSame('labo', $notif->service);
        $this->assertSame('prescription_recue', $notif->type);
        $this->assertSame('laborantin', $notif->groupe_destinataire);
        $this->assertSame($examen->numero_bon, $notif->code_reference);
        $this->assertStringContainsString('MUKENDI', $notif->message);
    }

    public function test_la_validation_notifie_le_medecin_prescripteur(): void
    {
        $type = TypeExamen::where('est_actif', true)->firstOrFail();
        $service = app(LaboratoireService::class);

        $examen = $service->prescrireExamens($this->visit, [$type->id], 'imagerie');
        $service->valider($examen, 'Compte-rendu sans particularité.');

        $notif = NotificationInterne::where('type', 'resultat_pret')->firstOrFail();

        $this->assertSame('imagerie', $notif->service);
        $this->assertSame($this->user->id, $notif->destinataire_id);
        $this->assertSame('haute', $notif->priorite);
    }

    public function test_la_page_notifications_liste_et_marque_comme_lu(): void
    {
        $type = TypeExamen::where('est_actif', true)->firstOrFail();
        $examen = app(LaboratoireService::class)
            ->prescrireExamens($this->visit, [$type->id], 'labo');

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertSee($examen->numero_bon);

        $notif = NotificationInterne::firstOrFail();
        $this->post(route('notifications.lue', $notif))->assertRedirect();
        $this->assertTrue($notif->fresh()->lu);

        $this->post(route('notifications.archiver', $notif))->assertRedirect();
        $this->assertTrue($notif->fresh()->archive);
        $this->get(route('notifications.index'))->assertOk()->assertDontSee($examen->numero_bon);
    }

    public function test_le_menu_surligne_imagerie_sur_un_bilan_imagerie(): void
    {
        $type = TypeExamen::where('code', 'like', 'IMG-%')->firstOrFail();
        $examen = app(LaboratoireService::class)
            ->prescrireExamens($this->visit, [$type->id], 'imagerie');

        $html = $this->get(route('labo.show', $examen))->assertOk()->getContent();

        // Laboratoire et Imagerie partagent le volet « Plateau technique » :
        // la rubrique courante porte est-actif, l'autre non.
        $imagerie = strpos($html, 'Imagerie</a>');
        $laboratoire = strpos($html, 'Laboratoire</a>');

        $this->assertNotFalse($imagerie);
        $this->assertNotFalse($laboratoire);
        $this->assertStringContainsString('est-actif', substr($html, $imagerie - 160, 160));
        $this->assertStringNotContainsString('est-actif', substr($html, $laboratoire - 160, 160));
    }

    public function test_le_rapport_journalier_montre_prescripteur_et_laborantin(): void
    {
        $type = TypeExamen::where('est_actif', true)->firstOrFail();
        $service = app(LaboratoireService::class);

        $examen = $service->prescrireExamens($this->visit, [$type->id], 'labo');
        app(FacturationService::class)->creerFactureExamen($examen);

        $resultats = $examen->resultats->mapWithKeys(
            fn ($r) => [$r->id => ['valeur_numerique' => '12', 'valeur_brute' => null]]
        )->all();
        $service->saisirResultats($examen, $resultats);
        $service->valider($examen->fresh(), 'RAS');

        $this->get(route('labo.rapport', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('REGISTRE JOURNALIER')
            ->assertSee('Dr Prescripteur')
            ->assertSee('Laborantin')
            ->assertSee('MUKENDI KABASELE JEAN')
            ->assertSee('Activité des laborantins');
    }

    public function test_le_tableau_de_service_montre_les_lits_et_le_dossier(): void
    {
        $service = Service::where('code', 'REA')->firstOrFail();
        $lit = Lit::where('service_id', $service->id)->firstOrFail();

        $sejour = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => $service->id,
            'lit_id' => $lit->id,
        ]);

        $this->get(route('services.index'))->assertOk()->assertSee('Réanimation');

        $this->get(route('services.show', $service))
            ->assertOk()
            ->assertSee($lit->numero)
            ->assertSee('MUKENDI KABASELE Jean')
            ->assertSee('Occupé');

        $this->get(route('services.dossier', [$service, $sejour]))
            ->assertOk()
            ->assertSee('Évolution')
            ->assertSee('Surveillance des constantes')
            ->assertSee('Produits &amp; prescriptions', false);
    }

    public function test_notes_evolution_et_constantes_du_sejour(): void
    {
        $service = Service::where('code', 'MED')->firstOrFail();
        $sejour = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'service_id' => $service->id,
        ]);

        $this->post(route('visites.evolution', $sejour), [
            'type' => 'transmission',
            'etat_general' => 'stationnaire',
            'note' => 'Patient calme, pansement refait ce matin.',
        ])->assertRedirect();

        $this->post(route('visites.signes-vitaux', $sejour), [
            'temperature' => 39.8,
            'tension_systolique' => 185,
            'tension_diastolique' => 95,
            'frequence_cardiaque' => 102,
            'saturation_o2' => 96,
        ])->assertRedirect();

        $sejour->refresh();
        $this->assertCount(1, $sejour->notesEvolution);
        $this->assertSame('transmission', $sejour->notesEvolution->first()->type);
        $this->assertCount(1, $sejour->signesVitaux);
        $this->assertNotEmpty($sejour->signesVitaux->first()->alertes());

        $this->get(route('services.dossier', [$service, $sejour]))
            ->assertOk()
            ->assertSee('pansement refait ce matin', false)
            ->assertSee('185/95');
    }

    public function test_un_sejour_termine_refuse_les_nouvelles_notes(): void
    {
        $sejour = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'hospitalisation',
            'statut' => 'termine',
            'date_entree' => now()->subDays(3),
            'date_sortie' => now(),
        ]);

        $this->post(route('visites.evolution', $sejour), ['note' => 'Note tardive'])
            ->assertSessionHas('error');

        $this->assertCount(0, $sejour->notesEvolution);
    }

    public function test_le_programme_operatoire_se_planifie_et_se_cloture(): void
    {
        $acte = ActeClinique::create([
            'visit_id' => $this->visit->id,
            'patient_id' => $this->patient->id,
            'prescripteur_id' => $this->user->id,
            'domaine' => 'chirurgie',
            'libelle' => 'Herniorraphie inguinale',
            'prix' => 150000,
            'statut' => 'planifie',
        ]);

        $this->get(route('bloc.index'))
            ->assertOk()
            ->assertSee('à programmer')
            ->assertSee('Herniorraphie inguinale');

        $this->post(route('bloc.planifier', $acte), [
            'date_prevue' => now()->addDay()->format('Y-m-d\TH:i'),
            'operateur_id' => $this->user->id,
            'duree_minutes' => 90,
            'indication' => 'Hernie inguinale droite',
            'consentement' => '1',
            'urgence' => '1',
        ])->assertRedirect();

        $acte->refresh();
        $this->assertNotNull($acte->date_prevue);
        $this->assertTrue($acte->consentement);
        $this->assertTrue($acte->urgence);
        $this->assertSame(90, $acte->duree_minutes);

        $this->get(route('bloc.index'))->assertOk()->assertSee('Hernie inguinale droite');

        $this->post(route('actes.realiser', $acte), [
            'compte_rendu' => 'Intervention sans complication, sortie de bloc à 11h.',
        ])->assertRedirect();

        $this->assertSame('realise', $acte->fresh()->statut);
        $this->get(route('bloc.index'))->assertOk()->assertSee('Registre des actes réalisés');
    }

    public function test_le_lit_libre_change_de_statut_mais_pas_le_lit_occupe(): void
    {
        $service = Service::where('code', 'CHIR')->firstOrFail();
        $lit = Lit::where('service_id', $service->id)->firstOrFail();

        $this->post(route('lits.statut', $lit), ['statut' => 'a_nettoyer'])->assertRedirect();
        $this->assertSame('a_nettoyer', $lit->fresh()->statut);

        $lit->update(['statut' => 'occupe']);
        $this->post(route('lits.statut', $lit), ['statut' => 'libre'])->assertSessionHas('error');
        $this->assertSame('occupe', $lit->fresh()->statut);
    }

    public function test_la_dispensation_notifie_le_medecin_prescripteur(): void
    {
        $consultation = Consultation::create([
            'visit_id' => $this->visit->id,
            'user_id' => $this->user->id,
            'date_consultation' => now(),
            'statut' => 'finalise',
        ]);

        $medicament = Medicament::with('stock')->whereHas('stock')->firstOrFail();

        $this->post(route('prescriptions.store', $consultation), [
            'lignes' => [[
                'medicament_id' => $medicament->id,
                'dose' => '1 cp',
                'frequence' => '3x/jour',
                'duree_jours' => 5,
                'quantite_totale' => 15,
            ]],
        ])->assertRedirect();

        $notif = NotificationInterne::where('service', 'pharmacie')->firstOrFail();
        $this->assertSame('prescription_recue', $notif->type);
        $this->assertSame('pharmacien', $notif->groupe_destinataire);
    }

    public function test_imagerie_notifie_les_manipulateurs_quand_ils_existent(): void
    {
        $type = TypeExamen::where('code', 'like', 'IMG-%')->firstOrFail();

        // Sans manipulateur affecté, l'imagerie revient au laboratoire
        $examen = app(LaboratoireService::class)
            ->prescrireExamens($this->visit, [$type->id], 'imagerie');
        $this->assertSame('laborantin', NotificationInterne::where('reference_id', $examen->id)
            ->firstOrFail()->groupe_destinataire);

        // Dès qu'un manipulateur porte le rôle, l'imagerie lui est adressée
        $manip = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'KALALA', 'prenom' => 'Grace',
            'email' => 'manip@dpi-rdc.local', 'matricule' => 'IMG-001',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $manip->assignRole('radiologue');

        $examen2 = app(LaboratoireService::class)
            ->prescrireExamens($this->visit, [$type->id], 'imagerie');
        $this->assertSame('radiologue', NotificationInterne::where('reference_id', $examen2->id)
            ->firstOrFail()->groupe_destinataire);

        // Le manipulateur voit bien la notification qui lui est destinée
        $this->assertSame(1, app(NotificationService::class)->nonLuesPour($manip));
    }

    public function test_le_registre_nomme_les_unites_d_analyse(): void
    {
        $type = TypeExamen::where('categorie', 'hematologie')->firstOrFail();

        $this->assertSame('Hématologie', $type->uniteAnalyse());

        app(LaboratoireService::class)->prescrireExamens($this->visit, [$type->id], 'labo');

        $this->get(route('labo.rapport', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Hématologie')
            ->assertDontSee('>hematologie<', false);
    }
}
