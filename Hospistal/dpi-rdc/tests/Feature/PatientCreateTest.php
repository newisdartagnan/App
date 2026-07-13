<?php

namespace Tests\Feature;

use App\Livewire\Patients\PatientCreate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PatientCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_be_created_via_livewire(): void
    {
        $this->seed();

        $user = User::where('is_active', true)->firstOrFail();
        $this->actingAs($user);

        Livewire::test(PatientCreate::class)
            ->set('nom', 'MUTOMBO')
            ->set('prenom', 'Patrick')
            ->set('sexe', 'M')
            ->set('type_prise_en_charge', 'prive')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('patients', [
            'nom' => 'MUTOMBO',
            'prenom' => 'Patrick',
        ]);
    }
}
