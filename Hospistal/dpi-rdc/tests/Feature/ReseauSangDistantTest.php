<?php

namespace Tests\Feature;

use App\Models\BulletinStockSang;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\Parametre;
use App\Models\PocheSang;
use App\Models\User;
use App\Services\BanqueSangService;
use App\Services\ReseauSangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le réseau des banques de sang, entre hôpitaux réellement distants.
 *
 * L'écran « Réseau » lisait le stock des autres établissements dans la base
 * locale : cela ne dit la vérité que si tous les hôpitaux partagent une seule
 * base. Or chaque hôpital tourne chez lui, avec la sienne. Entre deux villes,
 * l'écran était vide — ou pire, plausible et faux.
 *
 * Ces tests gardent les deux choses qui comptent : ce qui voyage (des
 * nombres, jamais des noms) et l'âge de ce qu'on lit (à trois heures du
 * matin, on envoie une ambulance sur cette ligne).
 */
class ReseauSangDistantTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->etab->update(['central_sync_token' => 'jeton-local-secret', 'telephone' => '0810000001']);
        $this->actingAs($this->admin);

        config(['dpi.central_api_url' => 'https://central.example']);
    }

    protected function poche(string $groupe, string $produit = 'sang_total', ?string $etab = null): PocheSang
    {
        return PocheSang::create([
            'establishment_id' => $etab ?? $this->etab->id,
            'numero' => 'POC-'.random_int(100000, 999999),
            'groupe_sanguin' => $groupe,
            'type_produit' => $produit,
            'volume_ml' => 450,
            'date_prelevement' => now()->subDays(3),
            'date_peremption' => now()->addDays(20),
            'statut' => 'disponible',
            'depistage_vih' => false, 'depistage_hepatite_b' => false,
            'depistage_hepatite_c' => false, 'depistage_syphilis' => false,
            'depistage_paludisme' => false, 'date_depistage' => now()->subDays(2),
        ]);
    }

    protected function bulletinDistant(array $remplacements = []): BulletinStockSang
    {
        return BulletinStockSang::create(array_merge([
            'etablissement_code' => 'HGR_KIKWIT',
            'nom' => 'HGR de Kikwit',
            'ville' => 'Kikwit',
            'province' => 'Kwilu',
            'telephone' => '0999888777',
            'stock' => ['sang_total' => ['O-' => 3, 'A+' => 2]],
            'donneurs' => ['O-' => 6, 'B+' => 1],
            'publie_le' => now()->subMinutes(10),
            'recu_le' => now()->subMinutes(9),
        ], $remplacements));
    }

    // ═══════════════════════════════════════════════════════════
    // Ce qui quitte l'hôpital
    // ═══════════════════════════════════════════════════════════

    public function test_le_bulletin_ne_contient_que_des_nombres(): void
    {
        $this->poche('O-');
        $this->poche('O-');
        $this->poche('A+', 'plaquettes');

        DonneurSang::create([
            'establishment_id' => $this->etab->id,
            'code' => 'DON-SECRET-01',
            'nom' => 'MULUMBA', 'prenom' => 'Joseph', 'sexe' => 'M',
            'groupe_sanguin' => 'O-', 'type_donneur' => 'benevole',
            'telephone' => '0812223344', 'est_eligible' => true,
        ]);

        $bulletin = app(ReseauSangService::class)->bulletinLocal($this->etab);

        $this->assertSame(2, $bulletin['stock']['sang_total']['O-']);
        $this->assertSame(1, $bulletin['stock']['plaquettes']['A+']);
        $this->assertSame(1, $bulletin['donneurs']['O-']);

        // Le réseau sert à savoir où appeler, pas à recopier le fichier des
        // donneurs de la maison d'à côté.
        $serialise = json_encode($bulletin);
        foreach (['MULUMBA', 'Joseph', 'DON-SECRET-01', '0812223344'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialise,
                "« {$secret} » quitte l'hôpital : le bulletin ne doit porter que des décomptes.");
        }
    }

    public function test_le_bulletin_ne_porte_aucun_numero_de_poche(): void
    {
        $poche = $this->poche('B+');

        $this->assertStringNotContainsString(
            $poche->numero,
            json_encode(app(ReseauSangService::class)->bulletinLocal($this->etab))
        );
    }

    public function test_une_poche_non_delivrable_nest_pas_annoncee(): void
    {
        // Annoncer une poche qu'on ne peut pas délivrer, c'est faire venir
        // une ambulance pour rien.
        $this->poche('O-')->update(['depistage_vih' => true]);
        $this->poche('A+')->update(['date_peremption' => now()->subDay()]);
        $this->poche('B+')->update(['statut' => 'reservee']);

        $bulletin = app(ReseauSangService::class)->bulletinLocal($this->etab);

        $this->assertSame([], $bulletin['stock']);
    }

    // ═══════════════════════════════════════════════════════════
    // L'aller-retour
    // ═══════════════════════════════════════════════════════════

    public function test_publier_rapporte_le_stock_des_autres(): void
    {
        $this->poche('O-');

        Http::fake(['central.example/*' => Http::response([
            'recu' => true,
            'bulletins' => [[
                'etablissement_code' => 'HGR_KIKWIT',
                'nom' => 'HGR de Kikwit', 'ville' => 'Kikwit', 'telephone' => '0999888777',
                'stock' => ['sang_total' => ['O-' => 4]],
                'donneurs' => ['O-' => 2],
                'publie_le' => now()->subMinutes(5)->toIso8601String(),
            ]],
        ])]);

        $resultat = app(ReseauSangService::class)->echanger($this->etab);

        $this->assertTrue($resultat['publie']);
        $this->assertSame(1, $resultat['recus']);

        $recu = BulletinStockSang::where('etablissement_code', 'HGR_KIKWIT')->firstOrFail();
        $this->assertSame(4, $recu->nombrePour(['O-'], 'sang_total'));

        // Un seul aller-retour : sur une liaison qui coupe, chaque appel
        // économisé compte.
        Http::assertSentCount(1);
        Http::assertSent(fn ($requete) => $requete['bulletin']['stock']['sang_total']['O-'] === 1
            && $requete->hasHeader('Authorization', 'Bearer jeton-local-secret'));
    }

    public function test_une_maison_retiree_du_reseau_recoit_sans_publier(): void
    {
        $this->poche('O-');

        Parametre::create([
            'establishment_id' => $this->etab->id,
            'cle' => BanqueSangService::CLE_PARTAGE,
            'valeur' => false,
        ]);

        Http::fake(['central.example/*' => Http::response(['bulletins' => []])]);

        $resultat = app(ReseauSangService::class)->echanger($this->etab);

        // Se retirer, ce n'est pas se priver.
        $this->assertFalse($resultat['publie']);
        Http::assertSent(fn ($requete) => $requete['bulletin'] === null);
    }

    public function test_une_liaison_coupee_ne_casse_rien(): void
    {
        $this->bulletinDistant();

        Http::fake(fn () => throw new ConnectionException('injoignable'));

        $resultat = app(ReseauSangService::class)->echanger($this->etab);

        $this->assertFalse($resultat['publie']);
        $this->assertStringContainsString('ne répond pas', $resultat['message']);

        // Ce qu'on avait reçu avant la coupure reste consultable.
        $this->assertDatabaseCount('bulletins_stock_sang', 1);
    }

    public function test_un_jeton_refuse_se_dit_clairement(): void
    {
        Http::fake(['central.example/*' => Http::response(['message' => 'non'], 401)]);

        $this->assertStringContainsString(
            'Jeton de réseau refusé',
            app(ReseauSangService::class)->echanger($this->etab)['message']
        );
    }

    public function test_sans_point_de_rendez_vous_il_ny_a_pas_de_reseau(): void
    {
        config(['dpi.central_api_url' => null]);

        $this->assertFalse(app(ReseauSangService::class)->configure());
        $this->assertStringContainsString(
            'Aucun point de rendez-vous',
            app(ReseauSangService::class)->echanger($this->etab)['message']
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Ce qu'on accepte de croire
    // ═══════════════════════════════════════════════════════════

    public function test_un_bulletin_plus_vieux_necrase_pas_le_plus_recent(): void
    {
        $this->bulletinDistant(['publie_le' => now()->subMinutes(5), 'stock' => ['sang_total' => ['O-' => 9]]]);

        // Les liaisons se doublent : un bulletin peut arriver après un plus
        // récent. Il ne doit pas prendre sa place.
        app(ReseauSangService::class)->enregistrer([[
            'etablissement_code' => 'HGR_KIKWIT',
            'nom' => 'HGR de Kikwit',
            'stock' => ['sang_total' => ['O-' => 1]],
            'publie_le' => now()->subHours(3)->toIso8601String(),
        ]]);

        $this->assertSame(9, BulletinStockSang::firstOrFail()->nombrePour(['O-'], 'sang_total'));
    }

    public function test_une_horloge_en_avance_ne_rend_pas_un_bulletin_eternellement_frais(): void
    {
        app(ReseauSangService::class)->enregistrer([[
            'etablissement_code' => 'HGR_MAL_REGLE',
            'nom' => 'HGR mal réglé',
            'stock' => ['sang_total' => ['O-' => 2]],
            'publie_le' => now()->addDays(3)->toIso8601String(),
        ]]);

        $this->assertFalse(BulletinStockSang::firstOrFail()->publie_le->isFuture());
    }

    public function test_le_reseau_ne_fait_pas_entrer_nimporte_quoi(): void
    {
        app(ReseauSangService::class)->enregistrer([[
            'etablissement_code' => 'HGR_BAVARD',
            'nom' => 'HGR bavard',
            'stock' => [
                'sang_total' => ['O-' => '4', 'ZZ' => 99, 'A+' => -7],
                'produit_invente' => ['O-' => 12],
            ],
            'donneurs' => ['O-' => 'beaucoup', 'XX' => 3],
            'publie_le' => now()->toIso8601String(),
        ]]);

        $recu = BulletinStockSang::firstOrFail();

        // Ce qui arrive du réseau est une déclaration, pas une vérité.
        $this->assertSame(['sang_total' => ['O-' => 4]], $recu->stock);
        $this->assertSame([], $recu->donneurs);
    }

    public function test_un_bulletin_trop_vieux_ne_saffiche_plus(): void
    {
        // Mieux vaut ne rien montrer qu'un stock d'hier.
        $this->bulletinDistant(['publie_le' => now()->subHours(BulletinStockSang::PERIME_HEURES + 1)]);

        $this->assertCount(0, app(ReseauSangService::class)->maisonsDistantes(null, 'sang_total'));
    }

    public function test_le_menage_efface_les_bulletins_perimes(): void
    {
        $this->bulletinDistant(['publie_le' => now()->subHours(50)]);
        $this->bulletinDistant(['etablissement_code' => 'HGR_FRAIS', 'publie_le' => now()->subMinutes(20)]);

        $this->assertSame(1, app(ReseauSangService::class)->purger());
        $this->assertDatabaseCount('bulletins_stock_sang', 1);
    }

    // ═══════════════════════════════════════════════════════════
    // L'âge, qui décide de tout
    // ═══════════════════════════════════════════════════════════

    public function test_lage_du_bulletin_se_lit_en_toutes_lettres(): void
    {
        $this->assertSame('à l\'instant', $this->bulletinDistant(['publie_le' => now()])->libelleAge());

        BulletinStockSang::truncate();
        $this->assertSame('il y a 25 min', $this->bulletinDistant(['publie_le' => now()->subMinutes(25)])->libelleAge());

        BulletinStockSang::truncate();
        $this->assertSame('il y a 4 h', $this->bulletinDistant(['publie_le' => now()->subHours(4)])->libelleAge());
    }

    public function test_un_stock_annonce_il_y_a_six_heures_nest_pas_frais(): void
    {
        $vieux = $this->bulletinDistant(['publie_le' => now()->subHours(6)]);
        $this->assertFalse($vieux->estFrais());

        BulletinStockSang::truncate();
        $this->assertTrue($this->bulletinDistant(['publie_le' => now()->subMinutes(20)])->estFrais());
    }

    // ═══════════════════════════════════════════════════════════
    // Le point de rendez-vous
    // ═══════════════════════════════════════════════════════════

    public function test_le_point_de_rendez_vous_range_et_renvoie_les_autres(): void
    {
        $this->bulletinDistant(['etablissement_code' => 'HGR_AUTRE', 'nom' => 'HGR autre']);

        $reponse = $this->withToken('jeton-local-secret')
            ->postJson(route('api.banque-sang.bulletins'), [
                'etablissement_code' => $this->etab->code,
                'bulletin' => [
                    'etablissement_code' => $this->etab->code,
                    'nom' => $this->etab->name,
                    'stock' => ['sang_total' => ['B+' => 5]],
                    'publie_le' => now()->toIso8601String(),
                ],
            ]);

        $reponse->assertOk()->assertJsonPath('recu', true);

        // On repart avec les autres, jamais avec le sien.
        $codes = collect($reponse->json('bulletins'))->pluck('etablissement_code');
        $this->assertTrue($codes->contains('HGR_AUTRE'));
        $this->assertFalse($codes->contains($this->etab->code));

        $this->assertDatabaseHas('bulletins_stock_sang', ['etablissement_code' => $this->etab->code]);
    }

    public function test_sans_jeton_on_nentre_pas(): void
    {
        $this->postJson(route('api.banque-sang.bulletins'), [
            'etablissement_code' => $this->etab->code,
        ])->assertStatus(401);
    }

    public function test_on_ne_publie_pas_sous_le_nom_dun_autre(): void
    {
        $voisin = Establishment::create([
            'code' => 'HGR_VOISIN', 'name' => 'HGR voisin', 'type' => 'hopital_general',
            'is_active' => true, 'central_sync_token' => 'jeton-du-voisin',
        ]);

        // Sans cela, n'importe qui annoncerait n'importe quoi au nom de
        // l'hôpital d'à côté — et on enverrait une ambulance pour rien.
        $this->withToken('jeton-local-secret')
            ->postJson(route('api.banque-sang.bulletins'), [
                'etablissement_code' => $voisin->code,
                'bulletin' => ['stock' => ['sang_total' => ['O-' => 99]]],
            ])
            ->assertStatus(401);

        $this->assertDatabaseMissing('bulletins_stock_sang', ['etablissement_code' => $voisin->code]);
    }

    public function test_le_code_du_bulletin_est_celui_du_porteur_du_jeton(): void
    {
        $this->withToken('jeton-local-secret')
            ->postJson(route('api.banque-sang.bulletins'), [
                'etablissement_code' => $this->etab->code,
                'bulletin' => [
                    'etablissement_code' => 'HGR_USURPE',
                    'nom' => 'Prétendu',
                    'stock' => ['sang_total' => ['O-' => 99]],
                    'publie_le' => now()->toIso8601String(),
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('bulletins_stock_sang', ['etablissement_code' => 'HGR_USURPE']);
        $this->assertDatabaseHas('bulletins_stock_sang', ['etablissement_code' => $this->etab->code]);
    }

    // ═══════════════════════════════════════════════════════════
    // L'écran
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_montre_les_hopitaux_distants_avec_leur_age(): void
    {
        $this->bulletinDistant(['publie_le' => now()->subHours(4)]);

        $this->get(route('banque-sang.reseau', ['groupe' => 'O+', 'produit' => 'sang_total']))
            ->assertOk()
            ->assertSee('HGR de Kikwit')
            ->assertSee('0999888777')
            ->assertSee('annoncé')
            ->assertSee('il y a 4 h')
            ->assertSee('Annonce ancienne');
    }

    public function test_un_stock_distant_frais_ne_porte_pas_davertissement(): void
    {
        $this->bulletinDistant(['publie_le' => now()->subMinutes(15)]);

        $this->get(route('banque-sang.reseau'))
            ->assertOk()
            ->assertSee('HGR de Kikwit')
            ->assertDontSee('Annonce ancienne');
    }

    public function test_lecran_compte_les_poches_compatibles_du_distant(): void
    {
        // Un receveur O+ accepte O− et O+ : les 3 poches O− de Kikwit
        // comptent, pas les 2 A+.
        $this->bulletinDistant();

        $this->get(route('banque-sang.reseau', ['groupe' => 'O+']))
            ->assertOk()
            ->assertSee('HGR de Kikwit');

        $maisons = app(ReseauSangService::class)->maisonsDistantes('O+', 'sang_total');
        $this->assertSame(3, $maisons->first()['compatibles']);
        $this->assertSame(5, $maisons->first()['total']);
    }

    public function test_un_hopital_qui_tient_le_point_de_rendez_vous_ne_se_voit_pas_deux_fois(): void
    {
        // Le serveur qui héberge le rendez-vous garde les bulletins de tout
        // le monde, le sien compris. Sans précaution, il s'afficherait dans
        // la liste des hôpitaux distants — deux stocks pour une seule maison.
        config(['dpi.establishment_code' => $this->etab->code]);

        $this->bulletinDistant([
            'etablissement_code' => $this->etab->code,
            'nom' => $this->etab->name,
        ]);

        $this->assertCount(0, app(ReseauSangService::class)->maisonsDistantes(null, 'sang_total'));
    }

    public function test_le_bulletin_fait_foi_sur_la_fiche_locale_dun_hopital_distant(): void
    {
        // Le serveur qui tient le point de rendez-vous doit connaître les
        // autres hôpitaux pour vérifier leur jeton. Ces fiches n'ont aucune
        // poche chez lui : les afficher en direct annoncerait « 0 poche »
        // pour une banque qui en a quinze.
        $kikwit = Establishment::create([
            'code' => 'HGR_KIKWIT', 'name' => 'HGR de Kikwit', 'type' => 'hopital_general',
            'is_active' => true, 'central_sync_token' => 'jeton-kikwit',
        ]);

        $this->bulletinDistant();

        $affichees = $this->get(route('banque-sang.reseau'))->assertOk()->viewData('maisons');
        $kikwits = $affichees->where('nom', 'HGR de Kikwit');

        $this->assertCount(1, $kikwits, 'Kikwit apparaît deux fois : la fiche vide et le bulletin.');
        $this->assertTrue($kikwits->first()['distant']);
        $this->assertSame(5, $kikwits->first()['total']);
        $this->assertNotNull($kikwit->fresh());
    }

    public function test_lecran_dit_quand_le_reseau_distant_nest_pas_configure(): void
    {
        config(['dpi.central_api_url' => null]);

        $this->get(route('banque-sang.reseau'))
            ->assertOk()
            ->assertSee('Réseau distant non configuré')
            ->assertSee('CENTRAL_API_URL');
    }

    public function test_le_bouton_rafraichir_echange_pour_de_bon(): void
    {
        $this->poche('AB+');

        Http::fake(['central.example/*' => Http::response(['bulletins' => [[
            'etablissement_code' => 'HGR_KIKWIT', 'nom' => 'HGR de Kikwit',
            'stock' => ['sang_total' => ['O-' => 2]],
            'publie_le' => now()->toIso8601String(),
        ]]])]);

        $this->post(route('banque-sang.reseau.rafraichir'))->assertRedirect();

        $this->assertDatabaseHas('bulletins_stock_sang', ['etablissement_code' => 'HGR_KIKWIT']);
        Http::assertSentCount(1);
    }

    public function test_le_reseau_reste_a_ceux_qui_y_travaillent(): void
    {
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)->get(route('banque-sang.reseau'))->assertForbidden();
        $this->actingAs($caissier)->post(route('banque-sang.reseau.rafraichir'))->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════
    // La tâche planifiée
    // ═══════════════════════════════════════════════════════════

    public function test_la_tache_echange_pour_chaque_etablissement(): void
    {
        Http::fake(['central.example/*' => Http::response(['bulletins' => [[
            'etablissement_code' => 'HGR_KIKWIT', 'nom' => 'HGR de Kikwit',
            'stock' => ['sang_total' => ['O-' => 1]],
            'publie_le' => now()->toIso8601String(),
        ]]])]);

        $this->artisan('dpi:sang-reseau')
            ->expectsOutputToContain('Stock publié')
            ->assertSuccessful();

        $this->assertDatabaseHas('bulletins_stock_sang', ['etablissement_code' => 'HGR_KIKWIT']);
    }

    public function test_la_tache_ne_sonne_pas_lalarme_sans_reseau_configure(): void
    {
        config(['dpi.central_api_url' => null]);

        // Une installation isolée n'est pas une installation en panne.
        $this->artisan('dpi:sang-reseau')->assertSuccessful();
    }
}
