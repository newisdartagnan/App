<?php

namespace Tests\Feature\Caisse;

use App\Enums\MoyenPaiement;
use App\Models\TauxChange;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ParcoursCaisse;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PAY-01 : taux du jour. PAY-09 : journal de caisse, totaux par devise. */
class TauxEtJournalTest extends TestCase
{
    use ParcoursCaisse, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
        $this->agent = $this->personnelActive('caisse');
    }

    private function saisir(string $taux): \Illuminate\Testing\TestResponse
    {
        return $this->commePersonnel($this->agent)->post(route('personnel.caisse.taux.enregistrer'), ['taux' => $taux]);
    }

    // --- PAY-01 ------------------------------------------------------------------

    public function test_l_agent_saisit_le_taux_du_jour_en_heure_de_kinshasa(): void
    {
        $this->saisir('2850,50')->assertRedirect(route('personnel.caisse.taux'))->assertSessionHasNoErrors();

        $taux = TauxChange::sole();
        $this->assertSame(['2026-12-07', '2850.5000', $this->agent->id], [$taux->jour->toDateString(), (string) $taux->cdf_par_usd, $taux->saisi_par]);
    }

    public function test_un_seul_taux_par_jour_corrigeable_tant_qu_il_n_a_pas_servi(): void
    {
        $this->saisir('2850');
        $this->saisir('2860')->assertSessionHasNoErrors();

        $this->assertSame(1, TauxChange::count());
        $this->assertSame('2860.0000', (string) TauxChange::sole()->cdf_par_usd);

        $this->travel(1)->days();
        $this->saisir('2870');
        $this->assertSame(2, TauxChange::count(), 'Un nouveau jour, un nouveau taux.');
    }

    public function test_un_taux_qui_a_servi_a_un_paiement_ne_se_modifie_plus(): void
    {
        $this->saisir('2850');
        $taux = TauxChange::sole();
        $paiement = $this->paiementEncaisse(prix: '28500.00', devise: 'CDF');
        DB::table('paiements')->where('id', $paiement->id)->update(['taux_change_id' => $taux->id]);

        $this->saisir('2900')->assertSessionHasErrors('taux');
        $this->assertSame('2850.0000', (string) $taux->fresh()->cdf_par_usd);

        $this->commePersonnel($this->agent)->get(route('personnel.caisse.taux'))
            ->assertInertia(fn ($page) => $page->component('Personnel/Caisse/Taux')->where('aujourdhui.modifiable', false));

        // La base aussi, quel que soit le code.
        $this->expectException(QueryException::class);
        DB::table('taux_change')->where('id', $taux->id)->update(['cdf_par_usd' => '1.0000']);
    }

    public function test_un_taux_illisible_ou_nul_est_refuse(): void
    {
        foreach (['abc', '0', '-2850', '2850.12345', ''] as $saisie) {
            $this->saisir($saisie)->assertSessionHasErrors('taux');
        }
        $this->assertSame(0, TauxChange::count());
    }

    // --- PAY-09 : journal ---------------------------------------------------------

    public function test_les_totaux_sont_par_devise_et_n_additionnent_jamais_dollars_et_francs(): void
    {
        $medecin = $this->medecin();
        $this->paiementEncaisse($medecin, '10.00');
        $usd = $this->paiementEncaisse($medecin, '20.00');
        $this->paiementEncaisse($medecin, '28500.00', 'CDF', MoyenPaiement::OrangeMoney);
        $this->remboursementReussi($usd, '4.00');

        $this->commePersonnel($this->agent)->get(route('personnel.caisse.journal'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Caisse/Journal')
                ->has('journal.totaux', 2)
                ->where('journal.totaux.0.devise', 'CDF')
                ->where('journal.totaux.0.net', "28\u{202F}500 CDF")
                ->where('journal.totaux.1.devise', 'USD')
                ->where('journal.totaux.1.encaisse', '30,00 USD')
                ->where('journal.totaux.1.rembourse', '4,00 USD')
                ->where('journal.totaux.1.net', '26,00 USD')
                ->has('journal.lignes', 4));
    }

    public function test_le_journal_se_filtre_par_jour_par_moyen_et_par_medecin(): void
    {
        $dupont = $this->medecin();
        $autre = $this->medecin();
        $this->paiementEncaisse($dupont, '10.00');
        $this->paiementEncaisse($autre, '15.00', moyen: MoyenPaiement::Carte);

        $page = fn (array $filtres) => $this->commePersonnel($this->agent)->get(route('personnel.caisse.journal', $filtres));

        $page(['medecin' => $dupont->id])->assertInertia(fn ($p) => $p->has('journal.lignes', 1)->where('journal.totaux.0.net', '10,00 USD'));
        $page(['moyen' => 'carte'])->assertInertia(fn ($p) => $p->has('journal.lignes', 1)->where('journal.totaux.0.net', '15,00 USD'));
        $page(['du' => '2026-12-08', 'au' => '2026-12-08'])->assertInertia(fn ($p) => $p->has('journal.lignes', 0)->has('journal.totaux', 0));
        $page([])->assertInertia(fn ($p) => $p->has('journal.parMedecin', 2)->has('journal.parMoyen', 2));
        $page(['moyen' => 'cheque'])->assertSessionHasErrors('moyen');
    }

    public function test_le_journal_ne_montre_ni_telephone_ni_donnee_de_sante(): void
    {
        $paiement = $this->paiementEncaisse();

        $json = json_encode($this->commePersonnel($this->agent)->get(route('personnel.caisse.journal'))->viewData('page'));

        $this->assertStringNotContainsString((string) $paiement->telephone_payeur, $json);
        $this->assertStringNotContainsString('Toux', $json);
    }
}
