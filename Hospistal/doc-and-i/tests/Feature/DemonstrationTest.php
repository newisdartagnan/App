<?php

namespace Tests\Feature;

use App\Console\Commands\PreparerDemonstration;
use App\Enums\StatutBonExamen;
use App\Models\AuthentificationPersonnel;
use App\Models\BonExamen;
use App\Models\Ordonnance;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\Dossier\OrdonnanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Démonstration prête à l'emploi : réservée au poste local, complète et rejouable. */
class DemonstrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_demonstration_est_refusee_hors_du_poste_local(): void
    {
        $this->artisan('demo:preparer')->assertFailed();
        $this->artisan('demo:code')->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_la_demonstration_locale_prepare_des_comptes_actives_et_un_parcours_complet_rejouable(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        try {
            $this->artisan('demo:preparer')->assertSuccessful();
            $this->artisan('demo:preparer')->assertSuccessful();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $medecin = User::where('email', 'medecin.demo@doc-and-i.local')->sole();
        $this->assertTrue(Hash::check(PreparerDemonstration::MOT_DE_PASSE, $medecin->password));
        $this->assertTrue(AuthentificationPersonnel::pour($medecin)->estActive());
        $this->assertSame(2, User::role('admin_fonctionnel')->count(), 'Deux administrateurs : quatre yeux sur l\'Ordre.');

        $this->assertSame(1, Ordonnance::count(), 'Rejouée sans doublon.');
        $this->assertTrue(app(OrdonnanceService::class)->estAuthentique(Ordonnance::with('lignes')->first()));
        $this->assertSame(1, Remboursement::where('statut', 'a_valider')->count());
        $this->assertSame(3, User::role('patient')->count());

        // Laboratoire : un bon payé avec résultats à valider, un bon à payer ; comptes du portail activés.
        $this->assertEqualsCanonicalizing(
            [StatutBonExamen::ResultatsDeposes, StatutBonExamen::Prescrit],
            BonExamen::pluck('statut')->all(),
        );
        foreach (['labo.demo@doc-and-i.local', 'coordination.demo@doc-and-i.local'] as $email) {
            $this->assertTrue(AuthentificationPersonnel::pour(User::where('email', $email)->sole())->estActive());
        }
    }
}
