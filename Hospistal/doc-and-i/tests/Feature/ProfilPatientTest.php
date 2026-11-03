<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CPT-02 : le profil du patient, nécessaire avant toute consultation. */
class ProfilPatientTest extends TestCase
{
    use RefreshDatabase;

    private function profilValide(array $surcharge = []): array
    {
        return array_merge([
            'nom' => 'kabongo',
            'postnom' => 'Mutombo',
            'prenom' => 'Grâce',
            'sexe' => 'F',
            'date_naissance' => '1990-04-12',
            'commune' => 'Lemba',
            'langue' => 'ln',
            'contact_urgence_nom' => 'Joseph Kabongo',
            'contact_urgence_telephone' => '099 123 45 67',
        ], $surcharge);
    }

    public function test_un_patient_sans_profil_est_conduit_au_formulaire(): void
    {
        $this->actingAs(User::factory()->sansProfil()->create());

        $this->get(route('espace'))->assertRedirect(route('profil.modifier'));
    }

    public function test_le_profil_s_enregistre_et_normalise_les_saisies(): void
    {
        $patient = User::factory()->sansProfil()->create();

        $this->actingAs($patient)
            ->put(route('profil.enregistrer'), $this->profilValide())
            ->assertRedirect(route('espace'));

        $patient->refresh();
        $this->assertSame('KABONGO', $patient->nom);
        $this->assertSame('F', $patient->sexe);
        $this->assertSame('1990-04-12', $patient->date_naissance->toDateString());
        $this->assertSame('ln', $patient->langue);
        $this->assertSame('+243991234567', $patient->contact_urgence_telephone);
        $this->assertTrue($patient->profilComplet());
    }

    public function test_les_champs_indispensables_sont_exiges(): void
    {
        $patient = User::factory()->sansProfil()->create();

        $this->actingAs($patient)
            ->put(route('profil.enregistrer'), ['commune' => 'Gombe'])
            ->assertSessionHasErrors(['nom', 'prenom', 'sexe', 'date_naissance']);

        $this->assertFalse($patient->refresh()->profilComplet());
    }

    public function test_une_date_de_naissance_future_est_refusee(): void
    {
        $this->actingAs(User::factory()->sansProfil()->create())
            ->put(route('profil.enregistrer'), $this->profilValide(['date_naissance' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('date_naissance');
    }

    public function test_un_sexe_ou_une_langue_inventes_sont_refuses(): void
    {
        $this->actingAs(User::factory()->sansProfil()->create())
            ->put(route('profil.enregistrer'), $this->profilValide(['sexe' => 'X', 'langue' => 'en']))
            ->assertSessionHasErrors(['sexe', 'langue']);
    }

    public function test_un_contact_d_urgence_au_numero_invalide_est_refuse(): void
    {
        $this->actingAs(User::factory()->sansProfil()->create())
            ->put(route('profil.enregistrer'), $this->profilValide(['contact_urgence_telephone' => '12']))
            ->assertSessionHasErrors('contact_urgence_telephone');
    }

    public function test_le_formulaire_ne_change_pas_le_numero_du_compte(): void
    {
        $patient = User::factory()->create(['telephone' => '+243812345678']);

        $this->actingAs($patient)
            ->put(route('profil.enregistrer'), $this->profilValide(['telephone' => '+243899999999']));

        $this->assertSame('+243812345678', $patient->refresh()->telephone);
    }

    public function test_le_profil_est_ferme_aux_visiteurs(): void
    {
        $this->get(route('profil.modifier'))->assertRedirect(route('connexion'));
    }
}
