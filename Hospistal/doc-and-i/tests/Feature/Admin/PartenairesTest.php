<?php

namespace Tests\Feature\Admin;

use App\Models\EntreeJournalAdministration;
use App\Models\Partenaire;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** ADM-01 : laboratoires et pharmacies partenaires, jamais supprimés, chaque écriture au journal. */
class PartenairesTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->admin = $this->personnelActive('admin_fonctionnel');
    }

    private function creer(array $surcharge = []): TestResponse
    {
        return $this->commePersonnel($this->admin)->from('/personnel/admin/partenaires')->post('/personnel/admin/partenaires', array_merge([
            'type' => 'laboratoire', 'nom' => 'Laboratoire du Fleuve', 'commune' => 'Gombe',
            'adresse' => '12, avenue du Commerce', 'telephone' => '081 234 56 78', 'email' => 'Contact@Labo-Fleuve.cd',
        ], $surcharge));
    }

    private function partenaire(string $type = 'pharmacie', string $nom = 'Pharmacie Lemba', bool $actif = true): Partenaire
    {
        return Partenaire::create(['type' => $type, 'nom' => $nom, 'actif' => $actif]);
    }

    public function test_creer_un_partenaire_numero_normalise_et_journal(): void
    {
        $this->creer()->assertRedirect('/personnel/admin/partenaires')->assertSessionHasNoErrors();

        $p = Partenaire::sole();
        $this->assertSame('+243812345678', $p->telephone);
        $this->assertSame('contact@labo-fleuve.cd', $p->email);
        $this->assertTrue($p->actif);
        $entree = EntreeJournalAdministration::where('action', 'partenaire.creer')->sole();
        $this->assertSame($p->id, $entree->cible_id);
        $this->assertEquals(['type' => 'laboratoire', 'nom' => 'Laboratoire du Fleuve'], $entree->details);
    }

    public function test_double_envoi_un_seul_partenaire_et_une_seule_ligne(): void
    {
        $this->creer()->assertSessionHasNoErrors();
        $this->creer(['nom' => 'laboratoire du fleuve'])->assertSessionHasErrors(['nom' => 'Un partenaire de ce type porte déjà ce nom.']);

        $this->assertSame(1, Partenaire::count());
        $this->assertSame(1, EntreeJournalAdministration::count());
        // Même nom, autre type : permis.
        $this->creer(['type' => 'pharmacie'])->assertSessionHasNoErrors();
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function saisiesInvalides(): array
    {
        return [
            'type inconnu' => [['type' => 'clinique'], 'type'],
            'nom vide' => [['nom' => ''], 'nom'],
            'numéro étranger' => [['telephone' => '+33 6 12 34 56 78'], 'telephone'],
            'numéro illisible' => [['telephone' => '12345'], 'telephone'],
            'courriel invalide' => [['email' => 'pas-un-courriel'], 'email'],
        ];
    }

    #[DataProvider('saisiesInvalides')]
    public function test_les_saisies_invalides_sont_refusees(array $surcharge, string $champ): void
    {
        $this->creer($surcharge)->assertSessionHasErrors($champ);

        $this->assertSame(0, Partenaire::count());
        $this->assertSame(0, EntreeJournalAdministration::count());
    }

    public function test_modifier_un_partenaire_note_les_champs_modifies(): void
    {
        $p = $this->partenaire();

        $this->commePersonnel($this->admin)->put("/personnel/admin/partenaires/{$p->id}", [
            'type' => 'pharmacie', 'nom' => 'Pharmacie Lemba', 'commune' => 'Lemba', 'telephone' => '0991234567',
        ])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('Lemba', $p->commune);
        $this->assertSame('+243991234567', $p->telephone);
        $details = EntreeJournalAdministration::where('action', 'partenaire.modifier')->sole()->details;
        $this->assertSame('commune, telephone', $details['champs_modifies']);
    }

    public function test_une_modification_sans_changement_n_ecrit_rien(): void
    {
        $p = $this->partenaire();

        $this->commePersonnel($this->admin)->put("/personnel/admin/partenaires/{$p->id}", ['type' => 'pharmacie', 'nom' => 'Pharmacie Lemba'])
            ->assertSessionHas('succes', 'Aucun changement.');

        $this->assertSame(0, EntreeJournalAdministration::count());
    }

    public function test_desactiver_puis_reactiver_sans_jamais_supprimer(): void
    {
        $p = $this->partenaire();
        $adresse = "/personnel/admin/partenaires/{$p->id}/etat";

        $this->commePersonnel($this->admin)->post($adresse, ['actif' => false])->assertRedirect();
        $this->commePersonnel($this->admin)->post($adresse, ['actif' => false])->assertSessionHas('succes', 'Aucun changement.');
        $this->assertFalse($p->fresh()->actif);
        $this->assertSame(1, EntreeJournalAdministration::where('action', 'partenaire.desactiver')->count());

        $this->commePersonnel($this->admin)->post($adresse, ['actif' => true]);
        $this->assertTrue($p->fresh()->actif);
        $this->assertSame(1, EntreeJournalAdministration::where('action', 'partenaire.activer')->count());

        // Aucune route de suppression.
        $this->commePersonnel($this->admin)->delete("/personnel/admin/partenaires/{$p->id}")->assertStatus(405);
        $this->assertSame(1, Partenaire::count());
    }

    public function test_la_liste_se_filtre_par_type(): void
    {
        $this->partenaire('pharmacie', 'Pharmacie Lemba');
        $this->partenaire('laboratoire', 'Labo Gombe');
        $this->partenaire('laboratoire', 'Labo Ngaliema', false);

        $this->commePersonnel($this->admin)->get('/personnel/admin/partenaires?type=laboratoire')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Personnel/Admin/Partenaires')
                ->where('filtre', 'laboratoire')
                ->has('partenaires', 2)
                ->where('partenaires.0.nom', 'Labo Gombe', 'Les actifs d\'abord.')
                ->where('partenaires.1.actif', false));

        $this->commePersonnel($this->admin)->get('/personnel/admin/partenaires?type=autre')
            ->assertInertia(fn ($page) => $page->where('filtre', null)->has('partenaires', 3));
    }

    public function test_les_autres_roles_n_ont_acces_a_aucun_ecran_ni_aucune_action(): void
    {
        $p = $this->partenaire();
        $intrus = [$this->medecinActive()->user, $this->personnelActive('caisse'), $this->personnelActive('support')];
        $actions = [
            ['GET', '/personnel/admin/partenaires', []],
            ['POST', '/personnel/admin/partenaires', ['type' => 'pharmacie', 'nom' => 'Intrus']],
            ['PUT', "/personnel/admin/partenaires/{$p->id}", ['type' => 'pharmacie', 'nom' => 'Renommé']],
            ['POST', "/personnel/admin/partenaires/{$p->id}/etat", ['actif' => false]],
        ];

        foreach ($actions as [$methode, $adresse, $donnees]) {
            foreach ($intrus as $user) {
                $this->commePersonnel($user)->call($methode, $adresse, $donnees)->assertForbidden();
            }
            $this->actingAs(User::factory()->create())->call($methode, $adresse, $donnees)->assertForbidden();
            auth()->logout();
            $this->call($methode, $adresse, $donnees)->assertRedirect('/personnel/connexion');
        }

        $this->assertSame(1, Partenaire::count());
        $this->assertSame('Pharmacie Lemba', $p->fresh()->nom);
        $this->assertTrue($p->fresh()->actif);
        $this->assertSame(0, EntreeJournalAdministration::count());
    }
}
