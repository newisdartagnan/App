<?php

namespace Tests\Feature\Laboratoire;

use App\Models\EntreeJournalAdministration;
use App\Models\Examen;
use App\Models\JetonLaboratoire;
use App\Models\Partenaire;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-04, PRE-06 : catalogue des examens et jetons d'API, côté administration, journalisés. */
class AdministrationLaboratoireTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->admin = $this->personnelActive('admin_fonctionnel');
    }

    private function commeAdmin(): static
    {
        return $this->commePersonnel($this->admin);
    }

    public function test_l_administrateur_cree_modifie_et_desactive_un_examen_journalise(): void
    {
        $this->commeAdmin()->post(route('personnel.admin.examens.creer'), ['code' => 'gly', 'libelle' => 'Glycémie à jeun', 'categorie' => 'biologie', 'prix' => '5,00', 'devise' => 'USD', 'preparation' => 'À jeun depuis 8 heures'])
            ->assertSessionHasNoErrors();
        $examen = Examen::sole();
        $this->assertSame(['GLY', '5.00', true], [$examen->code, (string) $examen->prix, $examen->actif]);

        $this->commeAdmin()->put(route('personnel.admin.examens.modifier', $examen->id), ['code' => 'GLY', 'libelle' => 'Glycémie à jeun', 'categorie' => 'biologie', 'prix' => '', 'devise' => 'USD'])->assertSessionHasNoErrors();
        $this->assertNull($examen->fresh()->prix, 'Prix facultatif : retiré, l\'examen n\'est plus prescriptible.');
        $this->commeAdmin()->post(route('personnel.admin.examens.etat', $examen->id), ['actif' => false])->assertSessionHasNoErrors();
        $this->assertFalse($examen->fresh()->actif);

        $this->assertSame(['examen.creer', 'examen.modifier', 'examen.desactiver'], EntreeJournalAdministration::orderBy('cree_le')->orderBy('id')->pluck('action')->all());
        $this->commeAdmin()->get(route('personnel.admin.examens'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Personnel/Admin/Examens')->has('examens', 1)->where('examens.0.prix', null));
    }

    public function test_les_saisies_invalides_sont_refusees(): void
    {
        Examen::create(['code' => 'GLY', 'libelle' => 'Glycémie', 'categorie' => 'biologie']);

        foreach ([
            ['code' => 'GLY', 'libelle' => 'Doublon', 'categorie' => 'biologie'],
            ['code' => 'NFS', 'libelle' => 'NFS', 'categorie' => 'chimie'],
            ['code' => 'NFS', 'libelle' => 'NFS', 'categorie' => 'biologie', 'prix' => '0'],
            ['code' => 'NFS', 'libelle' => 'NFS', 'categorie' => 'biologie', 'prix' => '-3'],
            ['code' => 'NFS', 'libelle' => 'NFS', 'categorie' => 'biologie', 'prix' => '3', 'devise' => 'EUR'],
            ['code' => 'N F S', 'libelle' => 'NFS', 'categorie' => 'biologie'],
            ['code' => 'NFS', 'libelle' => '', 'categorie' => 'biologie'],
        ] as $invalide) {
            $this->commeAdmin()->post(route('personnel.admin.examens.creer'), $invalide)->assertSessionHasErrors();
        }

        $this->assertSame(1, Examen::count());
    }

    public function test_seul_l_administrateur_gere_le_catalogue_et_les_jetons(): void
    {
        $labo = Partenaire::create(['type' => 'laboratoire', 'nom' => 'Labo']);
        foreach (['medecin' => $this->medecinActive()->user, 'caisse' => $this->personnelActive('caisse'), 'laboratoire' => $this->personnelActive('laboratoire'), 'coordinateur' => $this->personnelActive('coordinateur')] as $qui) {
            $this->commePersonnel($qui)->post(route('personnel.admin.examens.creer'), ['code' => 'X', 'libelle' => 'X', 'categorie' => 'biologie'])->assertForbidden();
            $this->commePersonnel($qui)->post(route('personnel.admin.jetons-laboratoire.emettre'), ['partenaire_id' => $labo->id, 'libelle' => 'x'])->assertForbidden();
        }

        $this->assertSame(0, Examen::count());
        $this->assertSame(0, JetonLaboratoire::count());
    }

    public function test_un_jeton_est_affiche_une_seule_fois_et_jamais_journalise_en_clair(): void
    {
        $labo = Partenaire::create(['type' => 'laboratoire', 'nom' => 'Eyano Centre Biologique (test)']);

        $reponse = $this->commeAdmin()->post(route('personnel.admin.jetons-laboratoire.emettre'), ['partenaire_id' => $labo->id, 'libelle' => 'Logiciel du laboratoire'])->assertSessionHasNoErrors();
        $clair = $reponse->getSession()->get('jeton_laboratoire_clair');
        $this->assertStringStartsWith('mhlab_', $clair);
        $jeton = JetonLaboratoire::sole();
        $this->assertSame(hash('sha256', $clair), $jeton->empreinte);

        $this->commeAdmin()->withSession(['jeton_laboratoire_clair' => $clair])->get(route('personnel.admin.jetons-laboratoire'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('jetonClair', $clair)->where('jetons.0.libelle', 'Logiciel du laboratoire'));
        $suivante = $this->commeAdmin()->get(route('personnel.admin.jetons-laboratoire'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('jetonClair', null));
        $this->assertStringNotContainsString($clair, $suivante->getContent());
        $this->assertStringNotContainsString($clair, EntreeJournalAdministration::all()->toJson());
        $this->assertStringContainsString('no-store', $suivante->headers->get('Cache-Control'));

        $this->commeAdmin()->post(route('personnel.admin.jetons-laboratoire.revoquer', $jeton->id))->assertSessionHasNoErrors();
        $this->assertNotNull($jeton->fresh()->revoque_le);
        $this->assertSame(['jeton_laboratoire.emettre', 'jeton_laboratoire.revoquer'], EntreeJournalAdministration::orderBy('cree_le')->orderBy('id')->pluck('action')->all());

        // Pas de jeton pour une pharmacie ni pour un laboratoire désactivé.
        $pharmacie = Partenaire::create(['type' => 'pharmacie', 'nom' => 'Pharmacie']);
        $this->commeAdmin()->post(route('personnel.admin.jetons-laboratoire.emettre'), ['partenaire_id' => $pharmacie->id, 'libelle' => 'x'])->assertSessionHasErrors('partenaire_id');
    }
}
