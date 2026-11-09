<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\Patient;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Une installation remontée avec une autre APP_KEY.
 *
 * Les coordonnées du patient sont chiffrées : avec une clé neuve, elles ne
 * se lisent plus et chaque fiche tombe en erreur. La commande le constate,
 * dit quoi faire, et ne vide les champs illisibles que sur demande.
 */
class ControleChiffrementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function patient(): Patient
    {
        return Patient::create([
            'establishment_id' => Establishment::firstOrFail()->id,
            'dossier_number' => 'PAT-2026-0'.random_int(10000, 99999),
            'nom' => 'MUJINGA', 'prenom' => 'Rose', 'sexe' => 'F',
            'date_naissance' => '1990-04-12',
            'type_prise_en_charge' => 'prive',
            'telephone' => '+243 810 000 111',
            'adresse' => 'Avenue Kasa-Vubu 12, Kinshasa',
        ]);
    }

    /** Remplace la clé de l'application, comme un .env neuf. */
    protected function changerDeCle(): void
    {
        $cle = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));

        config(['app.key' => $cle]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    public function test_avec_la_meme_cle_tout_se_lit(): void
    {
        $this->patient();

        $this->artisan('dpi:controler-chiffrement')
            ->expectsOutputToContain('Toutes les données chiffrées se lisent')
            ->assertSuccessful();
    }

    public function test_une_cle_changee_est_signalee_sans_rien_toucher(): void
    {
        $patient = $this->patient();
        $this->changerDeCle();

        $this->artisan('dpi:controler-chiffrement')
            ->expectsOutputToContain('champ(s) chiffré(s) illisible(s)')
            ->expectsOutputToContain('remettre l\'ancienne clé')
            ->assertFailed();

        $this->assertNotNull(\DB::table('patients')->where('id', $patient->id)->value('telephone'));
    }

    public function test_effacer_vide_les_seuls_champs_illisibles(): void
    {
        $ancien = $this->patient();
        $this->changerDeCle();

        // Un patient saisi après coup, avec la nouvelle clé, garde ses données.
        $nouveau = $this->patient();

        $this->artisan('dpi:controler-chiffrement', ['--effacer' => true, '--force' => true])
            ->expectsOutputToContain('champ(s) vidé(s)')
            ->assertSuccessful();

        $ancien = Patient::findOrFail($ancien->id);
        $this->assertNull($ancien->telephone);
        $this->assertNull($ancien->adresse);
        $this->assertSame('MUJINGA', $ancien->nom);

        $this->assertSame('+243 810 000 111', Patient::findOrFail($nouveau->id)->telephone);

        $this->artisan('dpi:controler-chiffrement')->assertSuccessful();
    }

    public function test_sans_confirmation_rien_nest_efface(): void
    {
        $patient = $this->patient();
        $this->changerDeCle();

        $this->artisan('dpi:controler-chiffrement', ['--effacer' => true])
            ->expectsConfirmation('Vider ces champs ? Ils ne pourront plus être relus, même avec l\'ancienne clé.', 'no')
            ->assertFailed();

        $this->assertNotNull(\DB::table('patients')->where('id', $patient->id)->value('adresse'));
    }

    public function test_le_journal_reste_ecrivable_par_tous(): void
    {
        // Créé par une commande lancée en root, le journal doit rester
        // ouvert à php-fpm (www-data) : sinon chaque erreur devient une page
        // « Permission denied ».
        $chemin = storage_path('logs/test-permission.log');
        @unlink($chemin);

        config(['logging.channels.essai' => array_merge(config('logging.channels.single'), ['path' => $chemin])]);
        Log::channel('essai')->info('essai');

        $this->assertSame('0666', substr(sprintf('%o', fileperms($chemin)), -4));
        @unlink($chemin);
    }
}
