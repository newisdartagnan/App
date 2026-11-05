<?php

namespace Tests\Feature\Paiement;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\PaiementService;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FournisseurPaiementTemoin;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** PAY-02, PAY-03, RG-02 : le patient demande le débit de son rendez-vous en attente. */
class InitiationPaiementTest extends TestCase
{
    use ParcoursPaiement, ParcoursRendezVous, RefreshDatabase;

    private User $patient;

    private Medecin $medecin;

    private RendezVous $rdv;

    private FournisseurPaiementTemoin $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('07:00');
        $this->fournisseur = new FournisseurPaiementTemoin($this->simulation());
        $this->app->instance(FournisseurPaiement::class, $this->fournisseur);

        $this->patient = $this->patient();
        $this->medecin = $this->medecin();
        $this->rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:00'), 20, StatutRendezVous::EnAttentePaiement,
            type: $this->typeConsultation($this->medecin, '10.00'));
    }

    private function initier(array $surcharge = []): TestResponse
    {
        return $this->actingAs($this->patient)
            ->from(route('paiement.payer', $this->rdv))
            ->post(route('paiement.initier', $this->rdv), array_merge([
                'moyen' => 'mpesa',
                'devise' => 'USD',
                'telephone' => '081 234 56 78',
                'cle' => (string) Str::uuid(),
            ], $surcharge));
    }

    private function refus(callable $action, string $extrait): void
    {
        try {
            $action();
            $this->fail('Refus attendu : '.$extrait);
        } catch (PaiementRefuse $e) {
            $this->assertStringContainsString($extrait, $e->getMessage());
        }
    }

    public function test_la_demande_de_debit_cree_une_tentative_en_attente_au_prix_fige(): void
    {
        $this->rdv->typeConsultation->update(['prix' => '99.00']);

        $reponse = $this->initier();

        $paiement = Paiement::sole();
        $reponse->assertRedirect(route('paiement.suivi', $paiement));
        $this->assertSame(StatutPaiement::EnAttente, $paiement->statut);
        $this->assertSame('10.00', $paiement->montant, 'RG-13 : prix figé du rendez-vous.');
        $this->assertSame('USD', $paiement->devise);
        $this->assertSame('+243812345678', $paiement->telephone_payeur, 'Numéro normalisé.');
        $this->assertSame(MoyenPaiement::MPesa, $paiement->moyen);
        $this->assertSame('simulation', $paiement->fournisseur);
        $this->assertNotNull($paiement->reference_fournisseur);
        $this->assertSame(1, $this->fournisseur->initiations);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $this->rdv->fresh()->statut, 'Le navigateur ne confirme rien.');
    }

    public function test_un_double_envoi_avec_la_meme_cle_ne_debite_qu_une_fois(): void
    {
        $cle = (string) Str::uuid();

        $this->initier(['cle' => $cle]);
        $this->initier(['cle' => $cle])->assertRedirect(route('paiement.suivi', Paiement::sole()));

        $this->assertSame(1, Paiement::count());
        $this->assertSame(1, $this->fournisseur->initiations);
    }

    public function test_une_tentative_en_cours_est_rendue_au_lieu_d_en_lancer_une_seconde(): void
    {
        $this->initier();
        $this->initier(['moyen' => 'orange_money'])->assertRedirect(route('paiement.suivi', Paiement::sole()));

        $this->assertSame(1, Paiement::count());
        $this->assertSame(1, $this->fournisseur->initiations);
    }

    public function test_une_tentative_restee_initiee_est_reprise_aupres_du_prestataire(): void
    {
        // Coupure entre notre enregistrement et la réponse du prestataire.
        $paiement = $this->paiementExistant($this->rdv, StatutPaiement::Initie);
        $paiement->forceFill(['reference_fournisseur' => null])->save();

        $tentative = app(PaiementService::class)->initier($this->rdv, $this->patient, MoyenPaiement::MPesa, 'USD', '0812345678', $paiement->cle_idempotence);

        $this->assertSame($paiement->id, $tentative->paiement->id);
        $this->assertSame(StatutPaiement::EnAttente, $tentative->paiement->statut);
        $this->assertSame(1, $this->fournisseur->initiations);
    }

    public function test_un_refus_du_prestataire_fait_echouer_la_tentative_et_permet_de_reessayer(): void
    {
        $this->fournisseur->erreur = 'Opérateur indisponible, réessayez dans quelques minutes.';

        $this->initier();
        $paiement = Paiement::sole();
        $this->assertSame(StatutPaiement::Echoue, $paiement->statut);
        $this->assertSame('Opérateur indisponible, réessayez dans quelques minutes.', $paiement->motif_echec);
        $this->assertNotNull($paiement->echoue_le);

        $this->fournisseur->erreur = null;
        $this->initier();
        $this->assertSame(2, Paiement::count());
        $this->assertSame(1, Paiement::where('statut', 'en_attente')->count());
    }

    public function test_le_mobile_money_exige_un_numero_congolais_valide(): void
    {
        foreach ([null, '', '12345', '+33612345678'] as $numero) {
            $this->initier(['telephone' => $numero])->assertSessionHasErrors('paiement');
        }

        $this->assertSame(0, Paiement::count());
        $this->assertSame(0, $this->fournisseur->initiations);
    }

    public function test_la_carte_ne_demande_pas_de_numero_et_renvoie_vers_la_page_3d_secure(): void
    {
        $this->fournisseur->urlRedirection = 'https://paiement.exemple.cd/3ds/abc';

        $this->initier(['moyen' => 'carte', 'telephone' => null])
            ->assertStatus(302)
            ->assertRedirect('https://paiement.exemple.cd/3ds/abc');

        $this->assertNull(Paiement::sole()->telephone_payeur);
    }

    public function test_en_francs_le_montant_est_converti_au_taux_du_jour(): void
    {
        $this->tauxDuJour('2850.0000');

        $this->initier(['devise' => 'CDF']);

        $paiement = Paiement::sole();
        $this->assertSame('28500.00', $paiement->montant);
        $this->assertSame('CDF', $paiement->devise);
        $this->assertSame('10.00', $paiement->montant_reference);
        $this->assertNotNull($paiement->taux_change_id);
    }

    public function test_sans_taux_du_jour_le_paiement_en_francs_est_refuse(): void
    {
        $this->initier(['devise' => 'CDF'])->assertSessionHasErrors('paiement');

        $this->assertSame(0, Paiement::count());
    }

    public function test_un_creneau_echu_ou_un_rendez_vous_qui_n_attend_pas_de_paiement_ne_se_paie_pas(): void
    {
        $service = app(PaiementService::class);
        $payer = fn (RendezVous $rdv) => $service->initier($rdv, $this->patient, MoyenPaiement::MPesa, 'USD', '0812345678', (string) Str::uuid());

        $this->travel(16)->minutes();
        $this->refus(fn () => $payer($this->rdv), 'délai pour payer');

        foreach ([StatutRendezVous::Confirme, StatutRendezVous::AnnuleParPatient, StatutRendezVous::Expire] as $i => $statut) {
            $rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('10:00')->addMinutes(30 * $i), 20, $statut);
            $this->refus(fn () => $payer($rdv), 'n\'attend pas de paiement');
        }

        $this->assertSame(0, Paiement::count());
    }

    public function test_un_rendez_vous_deja_paye_ne_se_paie_pas_deux_fois(): void
    {
        $this->paiementExistant($this->rdv);

        $this->initier()->assertSessionHasErrors(['paiement' => 'Ce rendez-vous est déjà payé.']);
        $this->assertSame(1, Paiement::count());
    }

    public function test_le_nombre_de_tentatives_par_rendez_vous_est_borne(): void
    {
        config(['doc-and-i.paiement.tentatives_par_rendez_vous' => 2]);
        $this->paiementExistant($this->rdv, StatutPaiement::Echoue);
        $this->paiementExistant($this->rdv, StatutPaiement::Expire);

        $this->initier()->assertSessionHasErrors('paiement');
        $this->assertSame(2, Paiement::count());
        $this->assertSame(0, $this->fournisseur->initiations);
    }

    public function test_on_ne_paie_pas_le_rendez_vous_d_un_autre_patient(): void
    {
        $intrus = $this->patient();

        $this->actingAs($intrus)->post(route('paiement.initier', $this->rdv), [
            'moyen' => 'mpesa', 'devise' => 'USD', 'telephone' => '0812345678', 'cle' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->actingAs($intrus)->get(route('paiement.payer', $this->rdv))->assertNotFound();

        $this->refus(fn () => app(PaiementService::class)->initier($this->rdv, $intrus, MoyenPaiement::MPesa, 'USD', '0812345678', (string) Str::uuid()), 'pas le vôtre');
        $this->assertSame(0, Paiement::count());
    }

    public function test_la_cle_d_un_autre_paiement_n_est_pas_reutilisable(): void
    {
        $autre = $this->patient();
        $autreRdv = $this->rendezVousExistant($this->medecin, $autre, $this->lundi('11:00'), 20, StatutRendezVous::EnAttentePaiement);
        $sienne = $this->paiementExistant($autreRdv, StatutPaiement::EnAttente);

        $this->initier(['cle' => $sienne->cle_idempotence])->assertSessionHasErrors('paiement');
        $this->assertSame(1, Paiement::count());
    }

    public function test_les_donnees_du_formulaire_sont_controlees(): void
    {
        $this->initier(['moyen' => 'bitcoin'])->assertSessionHasErrors('moyen');
        $this->initier(['devise' => 'EUR'])->assertSessionHasErrors('devise');
        $this->initier(['cle' => ''])->assertSessionHasErrors('cle');
        $this->initier(['cle' => 'court'])->assertSessionHasErrors('paiement');
        $this->initier(['statut' => 'reussi', 'montant' => '0.01'])->assertRedirect();

        $this->assertSame('10.00', Paiement::sole()->montant, 'Le montant envoyé par le navigateur est ignoré.');
        $this->assertSame(StatutPaiement::EnAttente, Paiement::sole()->statut);
    }

    public function test_la_page_de_paiement_propose_les_francs_seulement_avec_un_taux_du_jour(): void
    {
        $this->actingAs($this->patient)->get(route('paiement.payer', $this->rdv))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Paiement/Payer')
                ->has('devises', 1)
                ->where('devises.0.montant', '10,00 USD')
                ->has('moyens', 5)
                ->where('telephone', '0'.substr($this->patient->telephone, 4))
                ->where('secondesRestantes', 15 * 60)
                ->where('cle', fn ($cle) => Str::isUuid($cle)));

        $this->tauxDuJour('2850.0000');
        $this->get(route('paiement.payer', $this->rdv))
            ->assertInertia(fn ($page) => $page->has('devises', 2)->where('devises.1.code', 'CDF')
                ->where('devises.1.montant', "28\u{202F}500 CDF"));
    }

    public function test_la_page_de_paiement_renvoie_vers_le_suivi_d_une_tentative_en_cours_ou_vers_le_rendez_vous(): void
    {
        $paiement = $this->paiementExistant($this->rdv, StatutPaiement::EnAttente);
        $this->actingAs($this->patient)->get(route('paiement.payer', $this->rdv))->assertRedirect(route('paiement.suivi', $paiement));

        $confirme = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('11:00'));
        $this->get(route('paiement.payer', $confirme))->assertRedirect(route('rdv.voir', $confirme))->assertSessionHasErrors('paiement');
    }

    public function test_une_tentative_sans_reponse_expire_apres_le_delai_et_la_commande_est_planifiee(): void
    {
        $this->initier();
        $paiement = Paiement::sole();

        $this->travel(9)->minutes();
        $this->artisan('paiement:expirer-tentatives')->assertSuccessful();
        $this->assertSame(StatutPaiement::EnAttente, $paiement->fresh()->statut);

        $this->travel(2)->minutes();
        $this->artisan('paiement:expirer-tentatives')->assertSuccessful();
        $this->assertSame(StatutPaiement::Expire, $paiement->fresh()->statut);

        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'paiement:expirer-tentatives'));
        $this->assertNotNull($evenement);
        $this->assertSame('* * * * *', $evenement->expression);
    }

    public function test_le_personnel_ne_paie_pas_depuis_l_espace_patient(): void
    {
        $this->actingAs($this->medecin->user)->get(route('paiement.payer', $this->rdv))->assertForbidden();
        $this->actingAs($this->medecin->user)->post(route('paiement.initier', $this->rdv), [])->assertForbidden();
    }
}
