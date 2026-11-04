<?php

namespace Tests\Feature\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** « Mes rendez-vous » : liste, détail, annulation et report par le patient (RDV-07). */
class MesRendezVousTest extends TestCase
{
    use ParcoursRendezVous, RefreshDatabase;

    private User $patient;

    private Medecin $medecin;

    private TypeConsultation $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('07:00');
        $this->patient = $this->patient();
        $this->medecin = $this->medecin([[1, '09:00', '12:00'], [2, '09:00', '12:00']]);
        $this->type = $this->typeConsultation($this->medecin, '15.00', 20);
        $this->actingAs($this->patient);
    }

    private function reserve(string $heure = '10:00'): RendezVous
    {
        $this->post(route('rdv.demande.reserver', $this->demande($this->patient)), [
            'medecin_id' => $this->medecin->id,
            'type_consultation_id' => $this->type->id,
            'debut' => $this->lundi($heure)->toIso8601String(),
        ]);

        return RendezVous::latest()->firstOrFail();
    }

    /** Rendez-vous confirmé, par défaut le lendemain (mardi). */
    private function confirme(string $heure = '10:00', int $jours = 1): RendezVous
    {
        return $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi($heure)->addDays($jours), 20, StatutRendezVous::Confirme, null, $this->type);
    }

    public function test_le_detail_d_une_attente_annonce_le_paiement_au_prochain_lot_et_le_temps_restant(): void
    {
        $rdv = $this->reserve('10:00');
        $this->travel(4)->minutes();

        $this->get(route('rdv.voir', $rdv))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RendezVous/Detail')
                ->where('rendezVous.en_attente', true)
                ->where('rendezVous.secondes_restantes', 11 * 60)
                ->where('rendezVous.prix', '15,00 USD')
                ->where('rendezVous.quand', 'Aujourd\'hui, lundi 7 décembre à 10:00')
                ->where('actions.annuler', true)
                ->where('actions.reporter', false));

        $this->assertStringContainsString(
            'le paiement en ligne arrive dans le prochain lot',
            file_get_contents(resource_path('js/Pages/RendezVous/Detail.vue'))
        );
    }

    public function test_une_attente_echue_apparait_expiree_sans_attendre_la_tache_planifiee(): void
    {
        $rdv = $this->reserve('10:00');
        $this->travel(16)->minutes();

        $this->get(route('rdv.voir', $rdv))
            ->assertInertia(fn ($page) => $page
                ->where('rendezVous.statut', 'expire')
                ->where('actions.annuler', false)
                ->where('demande.peut_reserver', true));
    }

    public function test_la_liste_separe_l_a_venir_du_passe_et_montre_les_demandes_sans_rendez_vous(): void
    {
        $avenir = $this->confirme('10:00');
        $annule = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('11:00'), 20, StatutRendezVous::AnnuleParPatient, null, $this->type);
        $ouverte = $this->demande($this->patient);
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('11:20'));

        $this->get(route('rdv.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RendezVous/MesRendezVous')
                ->has('aVenir', 1)
                ->where('aVenir.0.id', $avenir->id)
                ->has('passes', 1)
                ->where('passes.0.id', $annule->id)
                ->has('demandesOuvertes', 1)
                ->where('demandesOuvertes.0.id', $ouverte->id));
    }

    public function test_le_detail_annonce_le_remboursement_d_une_annulation(): void
    {
        $this->get(route('rdv.voir', $this->confirme('10:00')))
            ->assertInertia(fn ($page) => $page->where('actions.taux_si_annulation', 100));

        $rdv = $this->confirme('11:00', 0);

        $this->get(route('rdv.voir', $rdv))
            ->assertInertia(fn ($page) => $page
                ->where('actions.taux_si_annulation', 50)
                ->where('actions.reporter', true));
    }

    public function test_annuler_un_rendez_vous_confirme(): void
    {
        $rdv = $this->confirme('11:00', 0);

        $this->post(route('rdv.annuler', $rdv), ['motif' => 'Je vais mieux'])
            ->assertRedirect(route('rdv.voir', $rdv))
            ->assertSessionHas('succes', 'Votre rendez-vous est annulé. 50 % du prix vous sera remboursé.');

        $rdv->refresh();
        $this->assertSame(StatutRendezVous::AnnuleParPatient, $rdv->statut);
        $this->assertSame(50, $rdv->taux_remboursement);
        $this->assertSame('Je vais mieux', $rdv->motif_annulation);
    }

    public function test_annuler_deux_fois_donne_un_message_et_ne_change_rien(): void
    {
        $rdv = $this->reserve('10:00');
        $this->post(route('rdv.annuler', $rdv))->assertSessionHas('succes', 'Votre réservation est annulée.');
        $annuleLe = $rdv->fresh()->annule_le;

        $this->from(route('rdv.voir', $rdv))->post(route('rdv.annuler', $rdv))
            ->assertRedirect(route('rdv.voir', $rdv))
            ->assertSessionHasErrors(['annulation' => 'Ce rendez-vous est déjà annulé ou terminé.']);
        $this->assertEquals($annuleLe, $rdv->fresh()->annule_le);
    }

    public function test_reporter_un_rendez_vous_confirme(): void
    {
        $rdv = $this->confirme('10:00');

        $this->get(route('rdv.report', $rdv))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RendezVous/Report')
                ->where('tardif', false)
                ->where('jours', function ($jours) {
                    $heures = collect($jours)->flatMap(fn ($j) => collect($j['creneaux'])->pluck('debut'));
                    $this->assertNotContains('2026-12-08T10:00:00+01:00', $heures->all(), 'L\'heure actuelle n\'est pas proposée.');
                    $this->assertContains('2026-12-08T10:20:00+01:00', $heures->all());

                    return true;
                }));

        $this->post(route('rdv.reporter', $rdv), ['debut' => $this->lundi('11:00')->addDay()->toIso8601String()])
            ->assertRedirect(route('rdv.voir', $rdv))
            ->assertSessionHas('succes');

        $this->assertSame('2026-12-08 11:00', $rdv->fresh()->debut->format('Y-m-d H:i'));
        $this->assertSame(1, $rdv->fresh()->reports);
    }

    public function test_reporter_vers_un_creneau_pris_ou_arbitraire_est_refuse(): void
    {
        $rdv = $this->confirme('10:00');
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('11:00')->addDay());

        foreach (['11:00', '11:05', '18:00'] as $heure) {
            $this->post(route('rdv.reporter', $rdv), ['debut' => $this->lundi($heure)->addDay()->toIso8601String()])
                ->assertSessionHasErrors('creneau');
        }
        $this->post(route('rdv.reporter', $rdv), [])->assertSessionHasErrors('debut');

        $this->assertSame('2026-12-08 10:00', $rdv->fresh()->debut->format('Y-m-d H:i'));
    }

    public function test_une_attente_de_paiement_ne_se_reporte_pas(): void
    {
        $rdv = $this->reserve('10:00');

        $this->get(route('rdv.report', $rdv))->assertRedirect(route('rdv.voir', $rdv))->assertSessionHasErrors('creneau');
        $this->post(route('rdv.reporter', $rdv), ['debut' => $this->lundi('11:00')->toIso8601String()])->assertSessionHasErrors('creneau');
    }
}
