<?php

namespace Tests\Feature\Caisse;

use App\Models\Facture;
use App\Models\JetonActivation;
use App\Models\User;
use Database\Seeders\DemonstrationLocaleSeeder;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParcoursCaisse;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** Comptes de caisse (CPT-05 généralisé), accès à l'espace caisse, factures et avoirs en PDF (PAY-05, PAY-08). */
class ComptesAccesEtFacturesTest extends TestCase
{
    use ParcoursCaisse, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        $this->voyagerAuLundi();
    }

    private function creerCompte(array $options): int
    {
        return Artisan::call('personnel:creer-compte', $options + [
            '--email' => 'Caisse.Gombe@Exemple.cd', '--telephone' => '0815557777', '--nom' => 'Kanku', '--prenom' => 'Sarah',
        ]);
    }

    // --- Comptes ----------------------------------------------------------------

    public function test_un_administrateur_cree_un_agent_de_caisse_avec_un_lien_d_activation(): void
    {
        $this->assertSame(0, $this->creerCompte(['--role' => 'caisse']));

        $agent = User::where('email', 'caisse.gombe@exemple.cd')->sole();
        $this->assertTrue($agent->hasRole('caisse'));
        $this->assertTrue($agent->estDuPersonnel());
        $this->assertNull($agent->password, 'Aucun mot de passe : l\'agent le choisit par son lien.');
        $this->assertSame('+243815557777', $agent->telephone);
        $this->assertMatchesRegularExpression('#/personnel/activation/[A-Za-z0-9]{64}\?expires=\d+&signature=#', Artisan::output());
        $this->assertSame(1, JetonActivation::where('user_id', $agent->id)->count());
    }

    public function test_un_role_inconnu_ou_patient_est_refuse(): void
    {
        foreach (['patient', 'tresorier', ''] as $role) {
            $this->assertNotSame(0, $this->creerCompte(['--role' => $role]), $role);
        }
        $this->assertNull(User::where('email', 'caisse.gombe@exemple.cd')->first());
    }

    public function test_un_medecin_passe_par_la_meme_commande_avec_specialite_et_ordre(): void
    {
        $this->assertNotSame(0, $this->creerCompte(['--role' => 'medecin']), 'Sans spécialité ni Ordre.');

        $this->assertSame(0, $this->creerCompte(['--role' => 'medecin', '--specialite' => 'pediatrie', '--ordre' => 'CNOM-9090']));
        $medecin = User::where('email', 'caisse.gombe@exemple.cd')->sole()->medecin;
        $this->assertSame('CNOM-9090', $medecin->numero_ordre);
        $this->assertNull($medecin->ordre_verifie_le);
    }

    public function test_la_demonstration_locale_cree_deux_agents_de_caisse(): void
    {
        $this->seed(DemonstrationLocaleSeeder::class);
        $this->seed(DemonstrationLocaleSeeder::class);

        $agents = User::role('caisse')->get();
        $this->assertCount(2, $agents, 'Deux agents, pour les quatre yeux ; le seeder se rejoue sans doublon.');
        $this->assertTrue($agents->every(fn (User $u) => $u->password === null && str_ends_with($u->email, '@doc-and-i.local')));
    }

    // --- Accès à l'espace caisse ---------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function routesDeLaCaisse(): array
    {
        $id = '00000000-0000-0000-0000-000000000000';

        return [
            'accueil' => ['get', route_caisse('accueil')],
            'taux' => ['get', route_caisse('taux')],
            'saisie du taux' => ['post', route_caisse('taux')],
            'remboursements' => ['get', route_caisse('remboursements')],
            'validation' => ['post', route_caisse("remboursements/{$id}/validation")],
            'refus' => ['post', route_caisse("remboursements/{$id}/refus")],
            'geste' => ['post', route_caisse('remboursements/geste')],
            'journal' => ['get', route_caisse('journal')],
            'reversements' => ['get', route_caisse('reversements')],
            'préparation' => ['post', route_caisse('reversements')],
            'paiement' => ['post', route_caisse("reversements/{$id}/paiement")],
            'rapprochement' => ['get', route_caisse('rapprochement')],
            'traitement' => ['post', route_caisse("rapprochement/{$id}/traitement")],
            'factures' => ['get', route_caisse('factures')],
            'pdf' => ['get', route_caisse("factures/{$id}/pdf")],
        ];
    }

    #[DataProvider('routesDeLaCaisse')]
    public function test_un_visiteur_est_renvoye_vers_la_connexion_du_personnel(string $methode, string $adresse): void
    {
        $this->{$methode}($adresse)->assertRedirect(route('personnel.connexion'));
    }

    #[DataProvider('routesDeLaCaisse')]
    public function test_un_patient_est_refuse(string $methode, string $adresse): void
    {
        $this->actingAs(User::factory()->create())->{$methode}($adresse)->assertForbidden();
    }

    #[DataProvider('routesDeLaCaisse')]
    public function test_un_medecin_est_refuse(string $methode, string $adresse): void
    {
        $this->commePersonnel($this->medecinActive()->user)->{$methode}($adresse)->assertForbidden();
    }

    #[DataProvider('routesDeLaCaisse')]
    public function test_un_agent_sans_second_facteur_dans_la_session_est_refuse(string $methode, string $adresse): void
    {
        $this->actingAs($this->personnelActive('caisse'))->{$methode}($adresse)->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_les_ecrans_de_la_caisse_s_ouvrent_pour_un_agent(): void
    {
        $agent = $this->personnelActive('caisse');

        foreach (['accueil' => 'Accueil', 'taux' => 'Taux', 'remboursements' => 'Remboursements', 'journal' => 'Journal',
            'reversements' => 'Reversements', 'rapprochement' => 'Rapprochement', 'factures' => 'Factures'] as $route => $ecran) {
            $this->commePersonnel($agent)->get(route('personnel.caisse.'.$route))
                ->assertOk()->assertInertia(fn ($page) => $page->component('Personnel/Caisse/'.$ecran));
        }
    }

    // --- Factures et avoirs -------------------------------------------------------

    public function test_une_facture_se_retrouve_par_son_numero_avec_ses_avoirs(): void
    {
        $paiement = $this->paiementEncaisse();
        $this->remboursementReussi($paiement, '4.00');
        $agent = $this->personnelActive('caisse');

        $this->commePersonnel($agent)->get(route('personnel.caisse.factures', ['numero' => ' fa-2026-000001 ']))
            ->assertInertia(fn ($page) => $page->has('pieces', 2)
                ->where('pieces.0.numero', 'FA-2026-000001')->where('pieces.0.montant', '10,00 USD')
                ->where('pieces.1.numero', 'AV-2026-000001')->where('pieces.1.type', 'Avoir')->where('pieces.1.montant', '4,00 USD'));

        $this->commePersonnel($agent)->get(route('personnel.caisse.factures', ['numero' => 'FA-2026-999999']))
            ->assertInertia(fn ($page) => $page->has('pieces', 0));
    }

    public function test_le_pdf_d_une_facture_est_un_vrai_pdf_sans_ressource_externe(): void
    {
        $paiement = $this->paiementEncaisse();
        $facture = $paiement->facture;

        $reponse = $this->commePersonnel($this->personnelActive('caisse'))->get(route('personnel.caisse.factures.pdf', $facture->id));

        $reponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
        $this->assertStringContainsString('FA-2026-000001.pdf', (string) $reponse->headers->get('content-disposition'));

        // Le gabarit ne charge rien d'ailleurs : ni script, ni police, ni image distante.
        $html = view('pdf.piece', [
            'piece' => ['type' => 'Facture', 'numero' => 'FA-1', 'patient' => 'X', 'libelle' => 'Y', 'montant' => '1,00 USD', 'moyen' => 'M-Pesa'],
            'emise' => '07/12/2026', 'medecin' => null, 'origine' => null, 'embleme' => 'data:image/png;base64,AAAA',
        ])->render();
        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*["\']?\s*(https?:)?//#i', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('@import', $html);
    }

    public function test_le_pdf_d_un_avoir_cite_sa_facture(): void
    {
        $paiement = $this->paiementEncaisse();
        $avoir = Facture::where('remboursement_id', $this->remboursementReussi($paiement, '10.00')->id)->sole();

        $this->commePersonnel($this->personnelActive('caisse'))->get(route('personnel.caisse.factures.pdf', $avoir->id))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_ni_un_patient_ni_un_medecin_ne_telechargent_une_facture_de_la_caisse(): void
    {
        $facture = $this->paiementEncaisse()->facture;

        $this->actingAs($facture->patient)->get(route('personnel.caisse.factures.pdf', $facture->id))->assertForbidden();
        $this->commePersonnel($this->medecinActive()->user)->get(route('personnel.caisse.factures.pdf', $facture->id))->assertForbidden();
    }
}

/** Adresse d'une page de la caisse, sans application démarrée (fournisseur de données). */
function route_caisse(string $chemin): string
{
    return 'http://localhost/personnel/caisse/'.ltrim($chemin === 'accueil' ? '' : $chemin, '/');
}
