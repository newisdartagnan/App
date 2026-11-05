<?php

namespace Tests\Feature\Caisse;

use App\Models\Medecin;
use App\Models\Reversement;
use App\Models\User;
use App\Services\Caisse\ReversementService;
use App\Services\Paiement\PaiementRefuse;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ParcoursCaisse;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PAY-09 : reversements aux médecins, préparés puis payés par deux agents différents. */
class ReversementsTest extends TestCase
{
    use ParcoursCaisse, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $agentA;

    private User $agentB;

    private Medecin $medecinPaye;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        config(['doc-and-i.paiement.commission_pourcent' => '10']);
        $this->voyagerAuLundi();
        $this->agentA = $this->personnelActive('caisse');
        $this->agentB = $this->personnelActive('caisse');

        // Lundi 7 décembre : 10 + 20 USD encaissés, 5 USD remboursés ; 28 500 CDF encaissés.
        $this->medecinPaye = $this->medecinActive();
        $this->paiementEncaisse($this->medecinPaye, '10.00');
        $this->remboursementReussi($this->paiementEncaisse($this->medecinPaye, '20.00'), '5.00');
        $this->paiementEncaisse($this->medecinPaye, '28500.00', 'CDF');
        // Un confrère : rien de ses encaissements ne compte pour le premier.
        $this->paiementEncaisse($this->medecin(), '50.00');

        $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00', 'Africa/Kinshasa'));
    }

    private function preparer(User $agent, string $debut = '2026-12-01', string $fin = '2026-12-07', ?Medecin $medecin = null): TestResponse
    {
        return $this->commePersonnel($agent)->post(route('personnel.caisse.reversements.preparer'), [
            'medecin' => ($medecin ?? $this->medecinPaye)->id, 'debut' => $debut, 'fin' => $fin,
        ]);
    }

    private function payer(User $agent, Reversement $r, string $reference = 'VIR-2026-0042'): TestResponse
    {
        return $this->commePersonnel($agent)->post(route('personnel.caisse.reversements.payer', $r->id), ['reference' => $reference]);
    }

    public function test_encaissements_moins_remboursements_par_devise_moins_la_commission(): void
    {
        $this->preparer($this->agentA)->assertSessionHasNoErrors();

        $usd = Reversement::where('devise', 'USD')->sole();
        $cdf = Reversement::where('devise', 'CDF')->sole();
        $this->assertSame(['25.00', '2.50', '22.50', 'prepare', $this->agentA->id],
            [(string) $usd->montant_brut, (string) $usd->commission, (string) $usd->montant_net, $usd->statut, $usd->prepare_par]);
        $this->assertSame(['28500.00', '2850.00', '25650.00'], [(string) $cdf->montant_brut, (string) $cdf->commission, (string) $cdf->montant_net]);
    }

    public function test_la_commission_est_arrondie_au_centime_au_profit_du_medecin(): void
    {
        config(['doc-and-i.paiement.commission_pourcent' => '12,5']);
        $this->preparer($this->agentA);

        // 25,00 × 12,5 % = 3,125 → 3,12.
        $this->assertSame(['3.12', '21.88'], [(string) Reversement::where('devise', 'USD')->sole()->commission, (string) Reversement::where('devise', 'USD')->sole()->montant_net]);
    }

    public function test_sans_commission_configuree_aucun_reversement(): void
    {
        foreach ([null, '', 'beaucoup', '150'] as $valeur) {
            config(['doc-and-i.paiement.commission_pourcent' => $valeur]);
            $this->preparer($this->agentA)->assertSessionHasErrors('medecin');
        }
        $this->assertSame(0, Reversement::count());

        $this->commePersonnel($this->agentA)->get(route('personnel.caisse.reversements'))
            ->assertInertia(fn ($page) => $page->component('Personnel/Caisse/Reversements')->where('commissionPourcent', null));
    }

    public function test_une_meme_periode_n_est_pas_reversee_deux_fois(): void
    {
        $this->preparer($this->agentA)->assertSessionHasNoErrors();

        $this->preparer($this->agentB)->assertSessionHasErrors('medecin');
        $this->preparer($this->agentB, '2026-12-05', '2026-12-09')->assertSessionHasErrors('medecin');
        $this->assertSame(2, Reversement::count());

        // La base aussi.
        $this->expectException(QueryException::class);
        DB::table('reversements')->insert(Reversement::where('devise', 'USD')->sole()->only(['medecin_id', 'periode_debut', 'periode_fin', 'devise', 'montant_brut', 'commission', 'montant_net', 'statut', 'prepare_par']) + ['id' => (string) Str::uuid()]);
    }

    public function test_seule_une_periode_close_se_reverse(): void
    {
        $this->preparer($this->agentA, '2026-12-01', '2026-12-10')->assertSessionHasErrors('medecin');
        $this->preparer($this->agentA, '2026-12-07', '2026-12-01')->assertSessionHasErrors('medecin');
        $this->assertSame(0, Reversement::count());
    }

    public function test_rien_a_reverser_sans_encaissement_sur_la_periode(): void
    {
        $this->preparer($this->agentA, '2026-11-01', '2026-11-30')->assertSessionHasErrors('medecin');
        $this->assertSame(0, Reversement::count());
    }

    public function test_un_autre_agent_paie_avec_la_reference_du_virement(): void
    {
        $this->preparer($this->agentA);
        $r = Reversement::where('devise', 'USD')->sole();

        $this->payer($this->agentB, $r)->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(['paye', $this->agentB->id, 'VIR-2026-0042'], [$r->statut, $r->paye_par, $r->reference_virement]);
        $this->assertNotNull($r->paye_le);
    }

    public function test_celui_qui_a_prepare_ne_paie_pas(): void
    {
        $this->preparer($this->agentA);
        $r = Reversement::where('devise', 'USD')->sole();

        $this->payer($this->agentA, $r)->assertSessionHasErrors('reference');
        $this->assertSame('prepare', $r->fresh()->statut);

        $this->commePersonnel($this->agentA)->get(route('personnel.caisse.reversements'))
            ->assertInertia(fn ($page) => $page->where('reversements.0.peutPayer', false)->missing('reversements.0.preparePar'));

        $this->expectException(QueryException::class);
        DB::table('reversements')->where('id', $r->id)->update(['paye_par' => $this->agentA->id]);
    }

    public function test_un_reversement_ne_se_paie_pas_deux_fois(): void
    {
        $this->preparer($this->agentA);
        $r = Reversement::where('devise', 'USD')->sole();

        $this->payer($this->agentB, $r, 'VIR-1');
        $this->payer($this->personnelActive('caisse'), $r, 'VIR-2')->assertSessionHasErrors('reference');

        $this->assertSame([$this->agentB->id, 'VIR-1'], [$r->fresh()->paye_par, $r->fresh()->reference_virement]);
    }

    public function test_la_reference_du_virement_est_obligatoire(): void
    {
        $this->preparer($this->agentA);
        $r = Reversement::where('devise', 'USD')->sole();

        $this->payer($this->agentB, $r, '  ')->assertSessionHasErrors('reference');
        $this->assertSame('prepare', $r->fresh()->statut);

        $this->expectException(PaiementRefuse::class);
        app(ReversementService::class)->payer($r, $this->agentB, '');
    }

    public function test_le_medecin_lit_ses_reversements_et_jamais_ceux_d_un_confrere(): void
    {
        $confrere = $this->medecinActive();
        $this->paiementEncaissePour($confrere);
        $this->preparer($this->agentA);
        $this->preparer($this->agentA, '2026-12-08', '2026-12-09', $confrere);

        $this->commePersonnel($this->medecinPaye->user)->get(route('personnel.medecin.reversements'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Medecin/Reversements')
                ->has('reversements', 2)
                ->where('reversements.0.devise', 'CDF')
                ->where('reversements.1.net', '22,50 USD')
                ->missing('reversements.0.preparePar'));

        $this->commePersonnel($confrere->user)->get(route('personnel.medecin.reversements'))
            ->assertInertia(fn ($page) => $page->has('reversements', 1)->where('reversements.0.net', '45,00 USD'));
    }

    public function test_ni_un_patient_ni_un_medecin_ne_preparent_ou_paient(): void
    {
        $this->preparer($this->agentA);
        $r = Reversement::where('devise', 'USD')->sole();

        $this->commePersonnel($this->medecinPaye->user)->post(route('personnel.caisse.reversements.payer', $r->id), ['reference' => 'X'])->assertForbidden();
        $this->commePersonnel($this->medecinPaye->user)->post(route('personnel.caisse.reversements.preparer'), ['medecin' => $this->medecinPaye->id, 'debut' => '2026-12-08', 'fin' => '2026-12-09'])->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('personnel.caisse.reversements.payer', $r->id), ['reference' => 'X'])->assertForbidden();
        // Un agent de caisse n'entre pas dans l'espace des médecins.
        $this->commePersonnel($this->agentA)->get(route('personnel.medecin.reversements'))->assertForbidden();

        $this->assertSame('prepare', $r->fresh()->statut);
    }

    /** Encaissement de 50 USD pour un médecin, le 8 décembre (après le 7 déjà reversé). */
    private function paiementEncaissePour(Medecin $medecin): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-08 08:00', 'Africa/Kinshasa'));
        $this->paiementEncaisse($medecin, '50.00');
        $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00', 'Africa/Kinshasa'));
    }
}
