<?php

namespace Tests\Feature\Dossier;

use App\Models\Ordonnance;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-03 : page publique de vérification d'une ordonnance (QR code du pharmacien). */
class VerificationOrdonnanceTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private Ordonnance $ordonnance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->app->instance(EnvoyeurSms::class, new EnvoyeurSmsFactice);
        $this->preparerConsultation('09:05');
        $this->patientRdv->forceFill(['prenom' => 'Marie', 'nom' => 'Kabila'])->save();
        $this->medecinRdv->forceFill(['numero_ordre' => 'CNOM-12345'])->save();

        $this->commeMedecinRdv()->postJson(route('personnel.medecin.consultation.ordonnances.emettre', $this->rdv->id), [
            'cle' => (string) Str::uuid(), 'consignes' => 'Boire beaucoup.', 'lignes' => [OrdonnanceTest::ligne()],
        ])->assertCreated();
        $this->ordonnance = Ordonnance::sole();
        // Le pharmacien n'est pas connecté.
        auth()->logout();
        $this->flushSession();
    }

    private function verifier(string $code): TestResponse
    {
        return $this->get('/verification-ordonnance/'.$code);
    }

    /** Hors de l'application : déclencheur contourné le temps d'une requête. */
    private function alterer(string $table, string $declencheur, array $valeurs): void
    {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$declencheur}");
        DB::table($table)->update($valeurs);
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$declencheur}");
    }

    public function test_une_ordonnance_intacte_est_authentique_et_le_patient_n_apparait_que_par_ses_initiales(): void
    {
        $reponse = $this->verifier($this->ordonnance->code_verification)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dossier/VerificationOrdonnance')
                ->where('verification.statut', 'authentique')
                ->where('verification.numero', 'OR-2026-000001')
                ->where('verification.date', '07/12/2026')
                ->where('verification.numeroOrdre', 'CNOM-12345')
                ->where('verification.patient', 'M. K.')
                ->where('verification.consignes', 'Boire beaucoup.')
                ->where('verification.lignes.0.medicament', 'Paracétamol')
                ->where('verification.lignes.0.posologie', '1 comprimé 3 fois par jour'));

        $contenu = $reponse->getContent();
        foreach (['Marie', 'Kabila', 'KABILA', $this->patientRdv->telephone, $this->patientRdv->id, $this->ordonnance->empreinte] as $absent) {
            $this->assertStringNotContainsString((string) $absent, $contenu);
        }
    }

    public function test_la_page_est_publique_jamais_en_cache_et_ne_fuit_pas_le_code(): void
    {
        $this->assertGuest();
        $reponse = $this->verifier($this->ordonnance->code_verification)->assertOk();

        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        // Le code est dans l'adresse : vers un autre site, seule l'origine part (politique globale).
        $this->assertSame('strict-origin-when-cross-origin', $reponse->headers->get('Referrer-Policy'));
        $this->assertSame('noindex, nofollow', $reponse->headers->get('X-Robots-Tag'));
    }

    public function test_une_ligne_modifiee_en_base_rend_l_ordonnance_alteree(): void
    {
        $this->alterer('lignes_ordonnance', 'lignes_ordonnance_non_modifiables', ['dosage' => '1000 mg']);

        $this->verifier($this->ordonnance->code_verification)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('verification.statut', 'alteree')->where('verification.lignes.0.dosage', '1000 mg'));
    }

    public function test_des_consignes_modifiees_en_base_rendent_l_ordonnance_alteree(): void
    {
        $this->alterer('ordonnances', 'ordonnances_non_modifiables', ['consignes' => 'Doubler les doses.']);

        $this->verifier($this->ordonnance->code_verification)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('verification.statut', 'alteree'));
    }

    public function test_une_identite_figee_modifiee_en_base_rend_l_ordonnance_alteree(): void
    {
        foreach ([['patient_nom' => 'Autre PERSONNE'], ['medecin_nom' => 'Dr Usurpateur FAUX'], ['medecin_numero_ordre' => 'CNOM-99999'], ['patient_naissance' => '2001-01-01']] as $valeurs) {
            $avant = DB::table('ordonnances')->first();
            $this->alterer('ordonnances', 'ordonnances_non_modifiables', $valeurs);

            $this->verifier($this->ordonnance->code_verification)->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('verification.statut', 'alteree'));
            $this->alterer('ordonnances', 'ordonnances_non_modifiables', array_intersect_key((array) $avant, $valeurs));
        }

        $this->verifier($this->ordonnance->code_verification)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('verification.statut', 'authentique'));
    }

    public function test_une_empreinte_modifiee_en_base_rend_l_ordonnance_alteree(): void
    {
        $this->alterer('ordonnances', 'ordonnances_non_modifiables', ['empreinte' => str_repeat('0', 64)]);

        $this->verifier($this->ordonnance->code_verification)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('verification.statut', 'alteree'));
    }

    public function test_un_code_inconnu_recoit_une_reponse_neutre(): void
    {
        $reponses = [];
        foreach ([Str::random(32), Str::random(32), 'abc', strtolower($this->ordonnance->code_verification) === $this->ordonnance->code_verification ? Str::random(31) : strtolower($this->ordonnance->code_verification)] as $code) {
            $reponse = $this->verifier($code)->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Dossier/VerificationOrdonnance')->where('verification', null));
            $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
            $reponses[] = $reponse->viewData('page')['props'];
        }

        // Même réponse, qu'un code ait existé ou non : rien à apprendre en essayant.
        $this->assertCount(1, array_unique(array_map('serialize', $reponses)));
        $this->verifier(str_repeat('a', 65))->assertNotFound();
        $this->verifier('../../etc')->assertNotFound();
    }

    public function test_le_debit_est_limite(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->verifier(Str::random(32))->assertOk();
        }

        $this->verifier($this->ordonnance->code_verification)->assertStatus(429);
    }
}
