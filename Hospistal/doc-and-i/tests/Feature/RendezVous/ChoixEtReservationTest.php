<?php

namespace Tests\Feature\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\Specialite;
use App\Models\TypeConsultation;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** RDV-03 (choix), RDV-04 (créneaux) et RDV-05 (réservation) par les écrans du patient. */
class ChoixEtReservationTest extends TestCase
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
        $this->medecin = $this->medecin([[1, '09:00', '10:00']]);
        $this->medecin->user->forceFill(['prenom' => 'Pauline', 'nom' => 'MUKENDI'])->save();
        $this->type = $this->typeConsultation($this->medecin, '15.00', 20);
        $this->actingAs($this->patient);
    }

    private function reserver($demande, string $heure = '09:00', array $surcharge = []): TestResponse
    {
        return $this->from(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => $this->medecin->id]))
            ->post(route('rdv.demande.reserver', $demande), array_merge([
                'medecin_id' => $this->medecin->id,
                'type_consultation_id' => $this->type->id,
                'debut' => $this->lundi($heure)->toIso8601String(),
            ], $surcharge));
    }

    public function test_le_choix_ne_propose_que_les_medecins_reservables_et_signale_les_types_sans_prix(): void
    {
        $sansPrix = $this->typeConsultation($this->medecin, null);
        $this->typeConsultation($this->medecin, '5.00', 20, 'USD', false);
        $nonVerifie = Medecin::factory()->nonVerifie()->create();
        $inactif = Medecin::factory()->create(['actif' => false]);
        $demande = $this->demande($this->patient);

        $this->get(route('rdv.demande.choix', $demande))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RendezVous/Choix')
                ->has('medecins', 1)
                ->where('medecins.0.id', $this->medecin->id)
                ->where('medecins.0.nom', 'Dr Pauline MUKENDI')
                ->where('specialites', function ($specialites) use ($sansPrix) {
                    $types = collect($specialites)->firstWhere('id', $this->medecin->specialite_id)['types'];
                    $this->assertCount(2, $types, 'Le type inactif n\'est pas proposé.');
                    $this->assertFalse(collect($types)->firstWhere('id', $sansPrix->id)['reservable']);
                    $this->assertNull(collect($types)->firstWhere('id', $sansPrix->id)['prix']);
                    $this->assertSame('15,00 USD', collect($types)->firstWhere('id', $this->type->id)['prix']);

                    return true;
                }));

        $this->assertNotNull($nonVerifie);
        $this->assertNotNull($inactif);
    }

    public function test_les_creneaux_d_un_medecin_sont_groupes_par_jour_en_heure_de_kinshasa(): void
    {
        $demande = $this->demande($this->patient);

        $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => $this->medecin->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RendezVous/Creneaux')
                ->where('medecin.id', $this->medecin->id)
                ->where('type.prix', '15,00 USD')
                ->has('jours', 2)
                ->where('jours.0.date', '2026-12-07')
                ->where('jours.0.libelle', 'Aujourd\'hui, lundi 7 décembre')
                ->where('jours.0.creneaux.0.heure', '09:00')
                ->where('jours.0.creneaux.0.debut', '2026-12-07T09:00:00+01:00')
                ->where('jours.0.creneaux.0.medecin_id', $this->medecin->id)
                ->has('jours.0.creneaux', 3)
                ->where('attenteEnCours', null));
    }

    public function test_premier_medecin_disponible_propose_les_creneaux_de_toute_la_specialite(): void
    {
        $autre = $this->medecin([[1, '08:00', '08:20']], Specialite::find($this->medecin->specialite_id));
        $demande = $this->demande($this->patient);

        $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => 'premier']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('medecin', null)
                ->where('jours.0.creneaux.0.heure', '08:00')
                ->where('jours.0.creneaux.0.medecin_id', $autre->id)
                ->where('jours.0.creneaux.1.medecin_id', $this->medecin->id));
    }

    public function test_un_type_sans_prix_est_refuse_en_le_disant_simplement(): void
    {
        $sansPrix = $this->typeConsultation($this->medecin, null);
        $demande = $this->demande($this->patient);

        foreach ([$this->medecin->id, 'premier'] as $medecin) {
            $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $sansPrix->id, 'medecin' => $medecin]))
                ->assertRedirect(route('rdv.demande.choix', $demande))
                ->assertSessionHasErrors(['choix' => 'Le prix de cette consultation n\'est pas encore fixé : elle ne peut pas encore être réservée.']);
        }
    }

    public function test_un_medecin_non_verifie_ou_inconnu_n_a_pas_de_creneaux(): void
    {
        $nonVerifie = $this->medecin([[1, '09:00', '10:00']], null, []);
        $nonVerifie->forceFill(['ordre_verifie_le' => null])->save();
        $demande = $this->demande($this->patient);

        foreach ([$nonVerifie->id, '0b8e3a3c-1111-4b4b-9c9c-123456789abc', 'n-importe-quoi'] as $medecin) {
            $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => $medecin]))
                ->assertRedirect(route('rdv.demande.choix', $demande))
                ->assertSessionHasErrors('choix');
        }
    }

    public function test_reserver_bloque_le_creneau_et_mene_au_rendez_vous(): void
    {
        $demande = $this->demande($this->patient);

        $reponse = $this->reserver($demande, '09:20');

        $rdv = RendezVous::sole();
        $reponse->assertRedirect(route('rdv.voir', $rdv));
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $rdv->statut);
        $this->assertSame($this->patient->id, $rdv->patient_id);
        $this->assertSame('15.00', $rdv->prix);
        $this->assertSame('reservee', $demande->fresh()->statut);
    }

    public function test_le_prix_envoye_par_le_navigateur_est_ignore(): void
    {
        $this->reserver($this->demande($this->patient), '09:00', ['prix' => '0.01', 'devise' => 'CDF', 'statut' => 'confirme', 'patient_id' => User::factory()->create()->id]);

        $rdv = RendezVous::sole();
        $this->assertSame('15.00', $rdv->prix);
        $this->assertSame('USD', $rdv->devise);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $rdv->statut);
        $this->assertSame($this->patient->id, $rdv->patient_id);
    }

    public function test_double_envoi_du_formulaire_ne_cree_qu_un_rendez_vous(): void
    {
        $demande = $this->demande($this->patient);

        $this->reserver($demande, '09:00')->assertRedirect();
        $this->reserver($demande, '09:00')->assertSessionHasErrors('creneau');

        $this->assertSame(1, RendezVous::count());
    }

    public function test_un_creneau_pris_entre_temps_donne_un_message_clair(): void
    {
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('09:00'), 20, StatutRendezVous::Confirme, null, $this->type);
        $demande = $this->demande($this->patient);

        $this->reserver($demande, '09:00')
            ->assertRedirect(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => $this->medecin->id]))
            ->assertSessionHasErrors(['creneau' => 'Ce créneau n\'est plus disponible. Choisissez-en un autre.']);
        $this->assertSame('ouverte', $demande->fresh()->statut);
    }

    public function test_un_patient_qui_a_deja_un_creneau_en_attente_est_prevenu(): void
    {
        $this->reserver($this->demande($this->patient), '09:00');
        $demande = $this->demande($this->patient);

        $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $this->type->id, 'medecin' => $this->medecin->id]))
            ->assertInertia(fn ($page) => $page->where('attenteEnCours', RendezVous::sole()->id));
        $this->reserver($demande, '09:20')
            ->assertSessionHasErrors(['creneau' => 'Vous avez déjà un créneau en attente de paiement. Payez-le ou annulez-le avant d\'en choisir un autre.']);
        $this->assertSame(1, RendezVous::count());
    }

    public function test_une_heure_arbitraire_ou_des_donnees_invalides_sont_refusees(): void
    {
        $demande = $this->demande($this->patient);

        $this->reserver($demande, '09:05')->assertSessionHasErrors('creneau');
        $this->reserver($demande, '09:00', ['debut' => 'demain'])->assertSessionHasErrors('debut');
        $this->reserver($demande, '09:00', ['medecin_id' => 'abc'])->assertSessionHasErrors('medecin_id');
        $this->reserver($demande, '09:00', ['type_consultation_id' => '0b8e3a3c-1111-4b4b-9c9c-123456789abc'])->assertSessionHasErrors('creneau');

        $this->assertSame(0, RendezVous::count());
    }

    public function test_une_demande_deja_reservee_renvoie_vers_son_rendez_vous(): void
    {
        $demande = $this->demande($this->patient);
        $this->reserver($demande, '09:00');

        $this->get(route('rdv.demande.choix', $demande))->assertRedirect(route('rdv.voir', RendezVous::sole()));
    }

    public function test_le_parcours_commence_depuis_l_espace_patient(): void
    {
        $this->get(route('espace'))->assertOk();
        $this->get(route('rdv.demande.creer'))->assertOk();
    }
}
