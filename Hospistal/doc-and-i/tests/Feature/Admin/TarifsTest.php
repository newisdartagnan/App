<?php

namespace Tests\Feature\Admin;

use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\EntreeJournalAdministration;
use App\Models\Medecin;
use App\Models\Paiement;
use App\Models\Specialite;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Services\RendezVous\CreneauxService;
use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** ADM-01 : spécialités et tarifs ; offre retirée au patient, rendez-vous existants intacts (RG-13). */
class TarifsTest extends TestCase
{
    use ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $admin;

    private Medecin $medecin;

    private Specialite $specialite;

    private TypeConsultation $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('07:00');
        $this->admin = $this->personnelActive('admin_fonctionnel');
        $this->medecin = $this->medecin([[1, '09:00', '12:00']]);
        $this->specialite = $this->medecin->specialite;
        $this->type = $this->typeConsultation($this->medecin, '15.00', 20);
    }

    private function commeAdmin(): static
    {
        return $this->commePersonnel($this->admin);
    }

    private function creerType(array $surcharge = []): TestResponse
    {
        return $this->commeAdmin()->from('/personnel/admin/tarifs')->post("/personnel/admin/tarifs/specialites/{$this->specialite->id}/types", array_merge([
            'nom' => 'Téléconsultation longue', 'duree_minutes' => 30, 'prix' => '25', 'devise' => 'USD', 'actif' => true,
        ], $surcharge));
    }

    private function modifierType(array $surcharge = []): TestResponse
    {
        return $this->commeAdmin()->from('/personnel/admin/tarifs')->put("/personnel/admin/tarifs/types/{$this->type->id}", array_merge([
            'nom' => $this->type->nom, 'duree_minutes' => 20, 'prix' => '15.00', 'devise' => 'USD', 'actif' => true,
        ], $surcharge));
    }

    private function journal(string $action): ?EntreeJournalAdministration
    {
        return EntreeJournalAdministration::where('action', $action)->sole();
    }

    public function test_l_ecran_liste_specialites_types_prix_et_rendez_vous_a_venir(): void
    {
        $this->typeConsultation($this->medecin, null, 15);
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('10:00'), 20, StatutRendezVous::Confirme, type: $this->type);

        $this->commeAdmin()->get('/personnel/admin/tarifs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Personnel/Admin/Tarifs')
                ->where('durees', ['min' => 10, 'max' => 120, 'pas' => 5])
                ->where('specialites', function ($specialites) {
                    $s = collect($specialites)->firstWhere('id', $this->specialite->id);
                    $this->assertTrue($s['active']);
                    $this->assertSame(1, $s['medecins']);
                    $types = collect($s['types'])->keyBy('id');
                    $this->assertSame('15,00 USD', $types[$this->type->id]['prix_affiche']);
                    $this->assertSame(1, $types[$this->type->id]['rendez_vous_a_venir']);
                    $this->assertNull(collect($s['types'])->firstWhere('prix', null)['prix_affiche']);

                    return true;
                }));
    }

    // --- Spécialités ---------------------------------------------------------

    public function test_desactiver_une_specialite_la_retire_au_patient_et_va_au_journal(): void
    {
        $this->commeAdmin()->post("/personnel/admin/tarifs/specialites/{$this->specialite->id}/etat", ['active' => false])->assertRedirect();

        $this->assertFalse($this->specialite->fresh()->active);
        $entree = $this->journal('specialite.desactiver');
        $this->assertSame($this->admin->id, $entree->auteur_id);
        $this->assertSame($this->specialite->id, $entree->cible_id);

        // Parcours patient : ni la spécialité, ni ses médecins, ni ses créneaux.
        $patient = $this->patient();
        $demande = $this->demande($patient);
        $this->actingAs($patient)->get(route('rdv.demande.choix', $demande))
            ->assertInertia(fn ($page) => $page
                ->where('specialites', fn ($s) => collect($s)->where('id', $this->specialite->id)->isEmpty())
                ->where('medecins', fn ($m) => collect($m)->where('id', $this->medecin->id)->isEmpty()));
        foreach ([$this->medecin->id, 'premier'] as $medecin) {
            $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => $medecin]))
                ->assertRedirect(route('rdv.demande.choix', $demande))->assertSessionHasErrors('choix');
        }
        $this->post(route('rdv.demande.reserver', $demande), [
            'medecin_id' => $this->medecin->id, 'type_consultation_id' => $this->type->id, 'debut' => $this->lundi('10:00')->toIso8601String(),
        ])->assertSessionHasErrors('creneau');
    }

    public function test_un_second_envoi_identique_ne_change_rien_et_n_ecrit_qu_une_ligne(): void
    {
        $adresse = "/personnel/admin/tarifs/specialites/{$this->specialite->id}/etat";
        $this->commeAdmin()->post($adresse, ['active' => false]);
        $this->commeAdmin()->post($adresse, ['active' => false])->assertSessionHas('succes', 'Aucun changement.');

        $this->assertSame(1, EntreeJournalAdministration::count());

        $this->commeAdmin()->post($adresse, ['active' => true]);
        $this->assertTrue($this->specialite->fresh()->active);
        $this->assertNotNull($this->journal('specialite.activer'));
    }

    public function test_l_etat_d_une_specialite_doit_etre_explicite(): void
    {
        $this->commeAdmin()->post("/personnel/admin/tarifs/specialites/{$this->specialite->id}/etat", [])->assertSessionHasErrors('active');
        $this->assertTrue($this->specialite->fresh()->active);
        $this->assertSame(0, EntreeJournalAdministration::count());
    }

    // --- Types de consultation -----------------------------------------------

    public function test_creer_un_type_au_prix_normalise_et_journalise(): void
    {
        $this->creerType(['prix' => '25,5'])->assertSessionHasNoErrors();

        $type = TypeConsultation::where('nom', 'Téléconsultation longue')->sole();
        $this->assertSame('25.50', $type->prix);
        $this->assertSame(30, $type->duree_minutes);
        $this->assertSame($this->specialite->id, $type->specialite_id);
        $this->assertEquals(['specialite' => $this->specialite->nom, 'nom' => 'Téléconsultation longue', 'duree_minutes' => 30,
            'nouveau_prix' => '25.50', 'devise' => 'USD', 'actif' => true], $this->journal('type_consultation.creer')->details);
    }

    public function test_un_prix_vide_cree_un_type_non_propose(): void
    {
        $this->creerType(['prix' => ''])->assertSessionHasNoErrors();

        $type = TypeConsultation::where('nom', 'Téléconsultation longue')->sole();
        $this->assertNull($type->prix);
        $this->expectException(ReservationRefusee::class);
        app(CreneauxService::class)->verifierOffre($this->medecin, $type);
    }

    public function test_double_envoi_de_creation_un_seul_type_et_une_seule_ligne(): void
    {
        $this->creerType()->assertSessionHasNoErrors();
        $this->creerType(['nom' => 'TÉLÉCONSULTATION LONGUE'])->assertSessionHasErrors(['nom' => 'Cette spécialité a déjà un type de consultation de ce nom.']);

        $this->assertSame(1, TypeConsultation::whereRaw('lower(nom) = ?', ['téléconsultation longue'])->count());
        $this->assertSame(1, EntreeJournalAdministration::count());
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function saisiesInvalides(): array
    {
        return [
            'durée hors grille' => [['duree_minutes' => 22], 'duree_minutes'],
            'durée trop courte' => [['duree_minutes' => 5], 'duree_minutes'],
            'durée trop longue' => [['duree_minutes' => 125], 'duree_minutes'],
            'durée illisible' => [['duree_minutes' => 'vingt'], 'duree_minutes'],
            'prix nul' => [['prix' => '0'], 'prix'],
            'prix négatif' => [['prix' => '-5'], 'prix'],
            'prix illisible' => [['prix' => 'dix dollars'], 'prix'],
            'trois décimales' => [['prix' => '10.505'], 'prix'],
            'francs avec centimes' => [['prix' => '15000.50', 'devise' => 'CDF'], 'prix'],
            'devise inconnue' => [['devise' => 'EUR'], 'devise'],
            'nom vide' => [['nom' => '  '], 'nom'],
            'actif absent' => [['actif' => null], 'actif'],
        ];
    }

    #[DataProvider('saisiesInvalides')]
    public function test_les_saisies_invalides_sont_refusees_sans_rien_ecrire(array $surcharge, string $champ): void
    {
        $this->creerType($surcharge)->assertSessionHasErrors($champ);
        $this->modifierType($surcharge)->assertSessionHasErrors($champ);

        $this->assertSame(1, TypeConsultation::count());
        $this->assertSame('15.00', $this->type->fresh()->prix);
        $this->assertSame(0, EntreeJournalAdministration::count());
    }

    public function test_en_francs_un_prix_entier_est_accepte(): void
    {
        $this->creerType(['prix' => '42 000', 'devise' => 'CDF'])->assertSessionHasNoErrors();

        $this->assertSame('42000.00', TypeConsultation::where('nom', 'Téléconsultation longue')->sole()->prix);
    }

    public function test_changer_le_prix_va_au_journal_avec_l_ancien_et_le_nouveau(): void
    {
        $this->modifierType(['prix' => '20'])->assertSessionHasNoErrors();

        $details = $this->journal('type_consultation.modifier')->details;
        $this->assertSame('15.00', $details['ancien_prix']);
        $this->assertSame('20.00', $details['nouveau_prix']);
        $this->assertSame('USD', $details['ancienne_devise']);
        $this->assertSame('USD', $details['nouvelle_devise']);
    }

    public function test_rg13_un_changement_de_prix_ne_touche_pas_les_rendez_vous_deja_reserves(): void
    {
        $patient = $this->patient();
        $rdv = $this->rendezVousExistant($this->medecin, $patient, $this->lundi('10:00'), 20, StatutRendezVous::EnAttentePaiement, type: $this->type);

        $this->modifierType(['prix' => '40', 'devise' => 'USD'])->assertSessionHasNoErrors();

        $this->assertSame('15.00', $rdv->fresh()->prix, 'Prix figé à la réservation.');
        // Le patient paie le prix réservé, pas le nouveau.
        $this->actingAs($patient)->post(route('paiement.initier', $rdv), [
            'moyen' => 'mpesa', 'devise' => 'USD', 'telephone' => '0812345678', 'cle' => (string) Str::uuid(), 'montant' => '15.00',
        ])->assertRedirect();
        $this->assertSame('15.00', Paiement::sole()->montant);
        // Les nouvelles réservations prennent le nouveau prix.
        $nouveau = app(ReservationService::class)->reserver($this->demande(User::factory()->create()), $this->medecin, $this->type->fresh(), $this->lundi('10:20'));
        $this->assertSame('40.00', $nouveau->prix);
    }

    public function test_desactiver_un_type_ne_casse_pas_les_rendez_vous_existants(): void
    {
        $patient = $this->patient();
        $enAttente = $this->rendezVousExistant($this->medecin, $patient, $this->lundi('10:00'), 20, StatutRendezVous::EnAttentePaiement, type: $this->type);
        $confirme = $this->rendezVousExistant($this->medecin, $patient, $this->lundi('11:00')->addWeek(), 20, StatutRendezVous::Confirme, type: $this->type);

        $this->modifierType(['actif' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->type->fresh()->actif);

        $this->actingAs($patient)->get(route('rdv.voir', $confirme))->assertOk();
        $this->actingAs($patient)->get(route('rdv.voir', $enAttente))->assertOk()->assertInertia(fn ($page) => $page->where('actions.payer', true));
        $this->post(route('paiement.initier', $enAttente), [
            'moyen' => 'mpesa', 'devise' => 'USD', 'telephone' => '0812345678', 'cle' => (string) Str::uuid(), 'montant' => '15.00',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(StatutPaiement::EnAttente, Paiement::sole()->statut);
        // Report d'un rendez-vous existant : toujours possible.
        $this->post(route('rdv.reporter', $confirme), ['debut' => $this->lundi('11:20')->addWeek()->toIso8601String()])->assertSessionHasNoErrors();

        // Mais le type n'est plus proposé à une nouvelle demande.
        $demande = $this->demande($patient);
        $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => $this->medecin->id]))
            ->assertSessionHasErrors('choix');
    }

    public function test_une_modification_sans_changement_n_ecrit_rien(): void
    {
        $this->modifierType()->assertSessionHas('succes', 'Aucun changement.');

        $this->assertSame(0, EntreeJournalAdministration::count());
    }

    public function test_le_nom_d_un_autre_type_de_la_specialite_est_refuse(): void
    {
        $autre = $this->typeConsultation($this->medecin, '10.00', 15);

        $this->modifierType(['nom' => $autre->nom])->assertSessionHasErrors('nom');
        $this->assertNotSame($autre->nom, $this->type->fresh()->nom);
    }

    // --- Accès ---------------------------------------------------------------

    public function test_les_autres_roles_n_ont_acces_a_aucun_ecran_ni_aucune_action(): void
    {
        $intrus = [
            $this->medecinActive()->user,
            $this->personnelActive('caisse'),
            $this->personnelActive('support'),
        ];
        $actions = [
            ['GET', '/personnel/admin/tarifs', []],
            ['POST', "/personnel/admin/tarifs/specialites/{$this->specialite->id}/etat", ['active' => false]],
            ['POST', "/personnel/admin/tarifs/specialites/{$this->specialite->id}/types", ['nom' => 'X', 'duree_minutes' => 20, 'prix' => '1', 'devise' => 'USD', 'actif' => true]],
            ['PUT', "/personnel/admin/tarifs/types/{$this->type->id}", ['nom' => 'X', 'duree_minutes' => 20, 'prix' => '1', 'devise' => 'USD', 'actif' => true]],
        ];

        foreach ($actions as [$methode, $adresse, $donnees]) {
            foreach ($intrus as $user) {
                $this->commePersonnel($user)->call($methode, $adresse, $donnees)->assertForbidden();
            }
            $this->actingAs($this->patient())->call($methode, $adresse, $donnees)->assertForbidden();
            auth()->logout();
            $this->call($methode, $adresse, $donnees)->assertRedirect('/personnel/connexion');
        }

        $this->assertTrue($this->specialite->fresh()->active);
        $this->assertSame('15.00', $this->type->fresh()->prix);
        $this->assertSame(1, TypeConsultation::count());
        $this->assertSame(0, EntreeJournalAdministration::count());
    }
}
