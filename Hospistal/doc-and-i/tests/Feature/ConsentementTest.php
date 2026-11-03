<?php

namespace Tests\Feature;

use App\Models\Consentement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CPT-04 : consentement explicite, horodaté et révocable. */
class ConsentementTest extends TestCase
{
    use RefreshDatabase;

    private function accepter(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('consentement.accepter'), [
            'teleconsultation' => '1',
            'donnees' => '1',
        ]);
    }

    public function test_sans_consentement_l_espace_reste_ferme(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('espace'))->assertRedirect(route('consentement'));
    }

    public function test_l_ecran_presente_la_version_en_vigueur(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('consentement'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Consentement/Accepter')
                ->where('version', config('doc-and-i.consentement.version')));
    }

    public function test_le_consentement_est_horodate_avec_sa_version_et_son_origine(): void
    {
        $patient = User::factory()->create();

        $this->actingAs($patient)->accepter()->assertRedirect(route('espace'));

        $consentement = Consentement::sole();
        $this->assertSame($patient->id, $consentement->user_id);
        $this->assertSame(config('doc-and-i.consentement.version'), $consentement->version);
        $this->assertNotNull($consentement->accepte_le);
        $this->assertSame('127.0.0.1', $consentement->ip);
        $this->get(route('espace'))->assertOk();
    }

    public function test_les_deux_cases_doivent_etre_cochees(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('consentement.accepter'), ['teleconsultation' => '1'])
            ->assertSessionHasErrors('donnees');

        $this->assertSame(0, Consentement::count());
    }

    public function test_le_consentement_se_retire_et_l_espace_se_referme(): void
    {
        $patient = User::factory()->create();
        $this->actingAs($patient)->accepter();

        $this->post(route('consentement.retirer'))->assertRedirect(route('consentement'));

        $this->assertNotNull(Consentement::sole()->retire_le);
        $this->get(route('espace'))->assertRedirect(route('consentement'));
    }

    public function test_un_retrait_garde_l_historique_et_une_nouvelle_acceptation_ajoute_une_ligne(): void
    {
        $this->actingAs(User::factory()->create());
        $this->accepter();
        $this->post(route('consentement.retirer'));
        $this->accepter();

        $this->assertSame(2, Consentement::count());
        $this->assertSame(1, Consentement::whereNull('retire_le')->count());
    }

    public function test_une_nouvelle_version_des_conditions_exige_un_nouvel_accord(): void
    {
        $this->actingAs(User::factory()->create());
        $this->accepter();

        config(['doc-and-i.consentement.version' => '2027-01']);

        $this->get(route('espace'))->assertRedirect(route('consentement'));
    }

    public function test_accepter_deux_fois_ne_cree_pas_deux_consentements_actifs(): void
    {
        $this->actingAs(User::factory()->create());
        $this->accepter();
        $this->accepter();

        $this->assertSame(1, Consentement::count());
    }
}
