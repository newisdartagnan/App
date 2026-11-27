<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\Consultation;
use App\Models\EmpreintePatient;
use App\Models\Establishment;
use App\Models\Lit;
use App\Models\Local;
use App\Models\MethodePaiement;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\RelationFamiliale;
use App\Models\Service;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La partie administrative du dossier (identité, carte, photo, empreintes,
 * relations, méthodes de paiement, vaccinations) et le catalogue de
 * l'établissement (examens, imagerie, services, locaux, lits, ambulances).
 */
class DossierAdministratifEtCatalogueTest extends TestCase
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

    protected function patient(string $nom = 'SELEMANI', string $prenom = 'Néhémie'): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-ADM-'.random_int(10000, 99999),
            'nom' => $nom, 'prenom' => $prenom, 'sexe' => 'M',
            'date_naissance' => now()->subYear()->subMonths(2)->toDateString(),
            'nationalite' => 'Congolaise', 'adresse' => 'Komba 5',
            'type_prise_en_charge' => 'prive',
        ]);
    }

    public function test_le_bloc_identite_et_ses_boutons(): void
    {
        $p = $this->patient();
        $this->assertSame($this->admin->id, $p->created_by);

        $this->get(route('patients.show', $p))->assertOk()
            ->assertSee('Identité')->assertSee('Informations générales du patient')
            ->assertSee('Komba 5')->assertSee('1 an')->assertSee('Enregistré par')
            ->assertSee($this->admin->nom_complet)
            ->assertSee('Modifier')->assertSee('Imprimer carte')->assertSee('Empreintes')->assertSee('Photo')
            ->assertSee('Relations familiales')->assertSee('Méthodes de paiement')->assertSee('Vaccinations');
    }

    public function test_modifier_l_identite(): void
    {
        $p = $this->patient();
        $this->get(route('patients.edit', $p))->assertOk();
        $this->put(route('patients.update', $p), [
            'nom' => 'selemani', 'prenom' => 'Néhémie', 'sexe' => 'M',
            'date_naissance' => '2023-12-15', 'nationalite' => 'Congolaise',
            'adresse' => 'Komba 7', 'situation_matrimoniale' => 'union_libre',
            'autres_details' => 'exemple',
        ])->assertRedirect(route('patients.show', $p));

        $p->refresh();
        $this->assertSame('SELEMANI', $p->nom);
        $this->assertSame('Komba 7', $p->adresse);
        $this->assertSame('union_libre', $p->situation_matrimoniale);
        $this->assertSame('exemple', $p->autres_details);
    }

    public function test_carte_patient_photo_et_empreintes(): void
    {
        Storage::fake('local');
        $p = $this->patient();

        $this->post(route('patients.photo.store', $p), ['photo' => UploadedFile::fake()->image('p.jpg', 600, 800)])
            ->assertSessionHas('success');
        $this->get(route('patients.photo', $p->fresh()))->assertOk();

        $carte = $this->get(route('patients.carte', $p));
        $carte->assertOk();
        $this->assertStringStartsWith('%PDF-', $carte->getContent());

        $this->get(route('patients.empreintes', $p))->assertOk()->assertSee('Index droit');
        $this->post(route('patients.empreintes.store', $p), [
            'doigt' => 'index_droit',
            'image' => UploadedFile::fake()->image('index.png', 200, 300),
        ])->assertSessionHas('success');
        $this->post(route('patients.empreintes.store', $p), ['doigt' => 'pouce_droit', 'gabarit' => 'Rk1SACAyMAAAAA'])
            ->assertSessionHas('success');
        $this->assertSame(2, $p->empreintes()->count());
        $this->post(route('patients.empreintes.store', $p), ['doigt' => 'majeur_droit'])->assertSessionHasErrors('image');

        $e = EmpreintePatient::where('doigt', 'index_droit')->first();
        $this->get(route('patients.empreintes.image', $e))->assertOk();
        $this->delete(route('patients.empreintes.destroy', $e))->assertSessionHas('success');
        $this->assertSame(1, $p->empreintes()->count());
    }

    public function test_relations_familiales_dans_les_deux_sens(): void
    {
        $parent = $this->patient('AHADI', 'Wilondja');
        $enfant = $this->patient('AHADI', 'Jeremie');

        $this->post(route('patients.relations.store', $enfant), ['proche_id' => $parent->id, 'lien' => 'parent'])
            ->assertSessionHas('success');
        $this->post(route('patients.relations.store', $parent), ['proche_id' => $enfant->id, 'lien' => 'enfant'])
            ->assertSessionHas('error');
        $this->post(route('patients.relations.store', $parent), ['proche_id' => $parent->id, 'lien' => 'conjoint'])
            ->assertSessionHasErrors('proche_id');

        $this->get(route('patients.show', $enfant))->assertSee('Wilondja')->assertSee('Parent (père / mère)');
        $this->get(route('patients.show', $parent))->assertSee('Jeremie')->assertSee('Enfant');

        $this->delete(route('patients.relations.destroy', RelationFamiliale::first()))->assertSessionHas('success');
        $this->assertSame(0, RelationFamiliale::count());
    }

    public function test_methode_de_paiement_reutilisee_et_tiers_payant(): void
    {
        $a = $this->patient('MASAKA', 'Maria');
        $b = $this->patient('NSAMBU', 'Andreas');

        $this->post(route('patients.methodes.store', $a), ['libelle' => 'Abonné mutuelle Solidarité', 'identifiant' => 'MS-01'])
            ->assertSessionHas('success');
        $methode = MethodePaiement::where('libelle', 'Abonné mutuelle Solidarité')->firstOrFail();

        $this->post(route('patients.methodes.store', $b), ['methode_paiement_id' => $methode->id, 'identifiant' => 'MS-02', 'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31'])
            ->assertSessionHas('success');
        $this->assertSame(1, MethodePaiement::count());
        $this->assertSame(2, $methode->liens()->count());

        // Liée à une assurance : le tiers payant s'active.
        $assurance = Assurance::create(['nom' => 'SONAS', 'code' => 'SONAS-T', 'taux_couverture' => 80, 'est_actif' => true]);
        $this->post(route('methodes-paiement.store'), ['libelle' => 'Assuré SONAS', 'type' => 'assurance', 'assurance_id' => $assurance->id])
            ->assertSessionHas('success');
        $sonas = MethodePaiement::where('libelle', 'Assuré SONAS')->first();
        $this->post(route('patients.methodes.store', $a), ['methode_paiement_id' => $sonas->id, 'identifiant' => 'POL-77']);
        $this->assertTrue(PatientAssurance::where(['patient_id' => $a->id, 'assurance_id' => $assurance->id, 'est_actif' => true])->exists());
        $this->assertSame('assurance', $a->fresh()->type_prise_en_charge);

        $this->get(route('patients.show', $a))->assertSee('Abonné mutuelle Solidarité')->assertSee('MS-01');
        $this->get(route('methodes-paiement.index'))->assertOk()->assertSee('SONAS');
    }

    public function test_vaccination_autre_et_carte_pdf(): void
    {
        $p = $this->patient();
        $this->post(route('prevention.vaccination.store', $p), [
            'antigene' => 'bcg', 'strategie' => 'fixe', 'date_vaccination' => now()->subMonth()->toDateString(), 'retour' => 'dossier',
        ])->assertRedirect(route('patients.show', $p));
        $this->post(route('prevention.vaccination.store', $p), [
            'antigene' => 'autre', 'antigene_libre' => 'Antirabique', 'strategie' => 'fixe', 'retour' => 'dossier',
        ])->assertSessionHas('success');
        $this->post(route('prevention.vaccination.store', $p), ['antigene' => 'autre', 'strategie' => 'fixe'])
            ->assertSessionHasErrors('antigene_libre');

        $this->assertSame('Antirabique', Vaccination::where('antigene', 'autre')->first()->libelleVaccin());
        $this->get(route('patients.show', $p))->assertSee('Antirabique')->assertSee('BCG');
        $pdf = $this->get(route('patients.carte-vaccination', $p));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_nature_du_diagnostic(): void
    {
        $this->assertArrayHasKey('presomption', Consultation::NATURES_DIAGNOSTIC);
        $this->assertArrayHasKey('peroperatoire', Consultation::NATURES_DIAGNOSTIC);
    }

    public function test_catalogue_examen_avec_parametres_et_imagerie(): void
    {
        $this->get(route('catalogue.examens.index'))->assertOk();
        $this->get(route('catalogue.examens.create'))->assertOk()->assertSee('Paramètres et valeurs de référence');

        $this->post(route('catalogue.examens.store'), [
            'domaine' => 'labo', 'libelle' => 'Glycémie à jeun', 'categorie' => 'biochimie', 'prix' => 3000,
            'parametres' => [
                ['nom' => 'Glucose', 'unite' => 'g/L', 'homme' => ['min' => 0.7, 'max' => 1.1], 'femme' => ['min' => 0.7, 'max' => 1.1]],
                ['nom' => ''],
            ],
        ])->assertRedirect(route('catalogue.examens.index'));
        $type = TypeExamen::where('libelle', 'Glycémie à jeun')->firstOrFail();
        $this->assertSame('labo', $type->domaine);
        $this->assertCount(1, $type->valeurs_reference['parametres']);
        $this->assertEquals(1.1, $type->valeurs_reference['parametres'][0]['homme']['max']);

        $this->post(route('catalogue.examens.store'), ['domaine' => 'imagerie', 'libelle' => 'Échographie pelvienne', 'categorie' => 'imagerie', 'modalite' => 'echographie', 'prix' => 20])
            ->assertRedirect(route('catalogue.imagerie.index'));
        $img = TypeExamen::where('libelle', 'Échographie pelvienne')->firstOrFail();
        $this->assertSame('imagerie', $img->domaine);
        $this->assertStringStartsWith('IMG-', $img->code);

        $this->post(route('catalogue.examens.basculer', $type))->assertSessionHas('success');
        $this->assertFalse($type->fresh()->est_actif);
    }

    public function test_services_locaux_et_lits(): void
    {
        $this->post(route('catalogue.services.store'), ['nom' => 'Soins intensifs', 'type' => 'medecine', 'capacite_lits' => 6])
            ->assertRedirect(route('catalogue.services.index'));
        $service = Service::where('nom', 'Soins intensifs')->firstOrFail();

        $this->post(route('catalogue.locaux.store'), ['service_id' => $service->id, 'nom' => 'Salle A', 'type' => 'salle'])
            ->assertRedirect(route('catalogue.locaux.index'));
        $local = Local::where('nom', 'Salle A')->firstOrFail();
        $this->post(route('catalogue.locaux.store'), ['service_id' => $service->id, 'nom' => 'Salle A', 'type' => 'salle'])
            ->assertSessionHasErrors('nom');

        $this->post(route('catalogue.lits.store'), ['service_id' => $service->id, 'local_id' => $local->id, 'numero' => 'SI-01'])
            ->assertRedirect(route('catalogue.lits.index'));
        $lit = Lit::where('numero', 'SI-01')->firstOrFail();
        $this->assertSame($local->id, $lit->local_id);

        $autre = Service::where('id', '!=', $service->id)->first();
        $this->post(route('catalogue.lits.store'), ['service_id' => $autre->id, 'local_id' => $local->id, 'numero' => 'X-99'])
            ->assertSessionHasErrors('local_id');

        $lit->update(['statut' => 'occupe']);
        $this->post(route('catalogue.lits.basculer', $lit))->assertSessionHas('error');

        $this->get(route('catalogue.services.index'))->assertOk()->assertSee('Soins intensifs');
        $this->get(route('catalogue.lits.index'))->assertOk()->assertSee('SI-01')->assertSee('Salle A');
        $this->get(route('dashboard'))->assertSee('Locaux de services')->assertSee(route('catalogue.locaux.create'), false);
    }

    public function test_ambulance_sortie_et_retour(): void
    {
        $this->post(route('ambulances.store'), ['libelle' => 'Ambulance 1', 'immatriculation' => '1234AB05'])->assertSessionHas('success');
        $amb = \App\Models\Ambulance::first();

        $this->post(route('ambulances.sorties.store'), [
            'ambulance_id' => $amb->id, 'motif' => 'Évacuation', 'depart_at' => now()->subHours(2)->format('Y-m-d H:i'), 'km_depart' => 1000,
        ])->assertSessionHas('success');
        $sortie = \App\Models\SortieAmbulance::first();
        $this->get(route('ambulances.index'))->assertOk()->assertSee('En mission');

        $this->post(route('ambulances.sorties.retour', $sortie), ['arrivee_at' => now()->format('Y-m-d H:i'), 'km_arrivee' => 990])
            ->assertSessionHasErrors('km_arrivee');
        $this->post(route('ambulances.sorties.retour', $sortie), ['arrivee_at' => now()->format('Y-m-d H:i'), 'km_arrivee' => 1042.5])
            ->assertSessionHas('success');
        $sortie->refresh();
        $this->assertEquals(42.5, $sortie->distanceKm());
        $this->assertSame(120, $sortie->dureeMinutes());

        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier)->get(route('ambulances.index'))->assertOk();
        $infirmier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $infirmier->assignRole('infirmier');
        $this->actingAs($infirmier)->get(route('ambulances.index'))->assertForbidden();
    }
}
