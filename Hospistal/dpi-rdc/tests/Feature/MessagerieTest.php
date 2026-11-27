<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Chat : conversations, envoi, lecture, voyant des non-lus, suppression. */
class MessagerieTest extends TestCase
{
    use RefreshDatabase;

    protected User $a;

    protected User $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $etab = Establishment::firstOrFail();
        $this->a = User::factory()->create(['establishment_id' => $etab->id, 'nom' => 'MBALA', 'prenom' => 'Ange']);
        $this->a->assignRole('infirmier');
        $this->b = User::factory()->create(['establishment_id' => $etab->id, 'nom' => 'KASONGO', 'prenom' => 'Pierre']);
        $this->b->assignRole('medecin');
    }

    public function test_envoyer_lire_et_voyant(): void
    {
        $this->actingAs($this->a)->get(route('messagerie.index'))->assertOk()->assertSee('Nouveau message')->assertSee('KASONGO');

        $this->actingAs($this->a)->postJson(route('messagerie.envoyer', $this->b), ['contenu' => 'Le patient du lit 4 a 40°C'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->actingAs($this->b)->getJson(route('messagerie.non-lus'))->assertJson(['non_lus' => 1]);
        $this->actingAs($this->b)->get(route('dashboard'))->assertSee('chat-voyant', false);
        $this->actingAs($this->b)->get(route('messagerie.index'))->assertSee('MBALA')->assertSee('Le patient du lit 4');

        // Ouvrir la conversation marque lu.
        $this->actingAs($this->b)->get(route('messagerie.conversation', $this->a))->assertOk()->assertSee('40°C');
        $this->actingAs($this->b)->getJson(route('messagerie.non-lus'))->assertJson(['non_lus' => 0]);

        $fil = $this->actingAs($this->a)->getJson(route('messagerie.fil', $this->b))->assertOk()->json();
        $this->assertStringContainsString('✓✓', $fil['html']);
    }

    public function test_suppression_par_auteur_et_par_destinataire(): void
    {
        $m1 = Message::create(['expediteur_id' => $this->a->id, 'destinataire_id' => $this->b->id, 'contenu' => 'secret A']);
        $m2 = Message::create(['expediteur_id' => $this->b->id, 'destinataire_id' => $this->a->id, 'contenu' => 'réponse B']);

        $this->actingAs($this->a)->deleteJson(route('messagerie.supprimer', $m1))->assertOk();
        $this->assertTrue($m1->fresh()->supprime);
        $this->actingAs($this->b)->get(route('messagerie.conversation', $this->a))->assertDontSee('secret A')->assertSee('Message supprimé');

        $this->actingAs($this->a)->deleteJson(route('messagerie.supprimer', $m2))->assertOk();
        $this->actingAs($this->a)->get(route('messagerie.conversation', $this->b))->assertDontSee('réponse B');
        $this->actingAs($this->b)->get(route('messagerie.conversation', $this->a))->assertSee('réponse B');

        $intrus = User::factory()->create(['establishment_id' => $this->a->establishment_id]);
        $this->actingAs($intrus)->deleteJson(route('messagerie.supprimer', $m2))->assertForbidden();
    }

    public function test_on_ne_s_ecrit_pas_a_soi_meme(): void
    {
        $this->actingAs($this->a)->postJson(route('messagerie.envoyer', $this->a), ['contenu' => 'moi'])->assertStatus(422);
    }
}
