<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le service worker, remis en service — et tenu.
 *
 * Il avait été débranché parce qu'il gardait les pages en cache et les
 * rendait telles quelles, jetons CSRF compris : on se reconnectait, la
 * session repartait à zéro, et le formulaire servi par le cache portait
 * encore le jeton d'avant. Refusé, 419.
 *
 * Ces tests gardent les deux règles qui empêchent cela de revenir : aucune
 * page de l'application n'entre dans le cache, et rien de ce qui y entre ne
 * porte le nom d'un patient.
 */
class ServiceWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function sw(): string
    {
        return file_get_contents(public_path('sw.js'));
    }

    // ═══════════════════════════════════════════════════════════
    // La règle qui a coûté la première tentative
    // ═══════════════════════════════════════════════════════════

    public function test_le_service_worker_ne_met_aucune_page_en_reserve(): void
    {
        $sw = $this->sw();

        // Seuls ces chemins-là entrent dans le cache. Tout le reste — donc
        // toute page portant un jeton — passe par le réseau.
        preg_match('/function estUnFichierFige[^}]+}/', $sw, $trouve);
        $this->assertNotEmpty($trouve, 'La liste des chemins cachables a disparu.');

        $this->assertStringContainsString("'/build/'", $trouve[0]);
        $this->assertStringContainsString("'/icons/'", $trouve[0]);
        $this->assertStringContainsString("'/manifest.json'", $trouve[0]);

        // Les chemins de l'application ne doivent apparaître nulle part.
        foreach (['/dashboard', '/patients', '/consultations', '/caisse', '/labo'] as $ecran) {
            $this->assertStringNotContainsString($ecran, $sw,
                "Le service worker met « {$ecran} » en cache : le jeton servi ne sera plus celui de la session.");
        }
    }

    public function test_une_navigation_part_toujours_sur_le_reseau(): void
    {
        $sw = $this->sw();

        // Le réseau d'abord, la page de coupure seulement s'il ne répond
        // pas : c'est ce qui garantit un jeton frais tant qu'il y a du
        // réseau.
        $this->assertMatchesRegularExpression(
            '/mode === .navigate.\s*\)\s*\{\s*event\.respondWith\(reseauPuisPageDeCoupure/',
            $sw
        );
        $this->assertStringContainsString('return await fetch(requete);', $sw);
    }

    public function test_un_envoi_de_formulaire_ne_traverse_jamais_le_cache(): void
    {
        $this->assertMatchesRegularExpression(
            '/requete\.method !== .GET.\s*\)\s*\{\s*return;/',
            $this->sw(),
            'Le service worker doit se retirer de tout ce qui n\'est pas une lecture.'
        );
    }

    public function test_la_reserve_ne_contient_rien_de_nominatif(): void
    {
        preg_match('/const SOCLE = \[(.*?)\];/s', $this->sw(), $trouve);

        $this->assertNotEmpty($trouve, 'La réserve du service worker a disparu.');

        // Un poste d'hôpital passe de main en main : ce qui reste sur le
        // disque du navigateur est lisible par l'équipe suivante.
        foreach (explode(',', $trouve[1]) as $entree) {
            $url = trim($entree, " \n\t'\"");

            if ($url === '') {
                continue;
            }

            $this->assertContains($url,
                ['/hors-ligne', '/manifest.json', '/icons/icon-192.png', '/icons/icon-512.png'],
                "« {$url} » est gardé hors ligne sans être anonyme.");
        }
    }

    public function test_le_menage_du_changement_dequipe_ne_jette_pas_la_page_de_coupure(): void
    {
        $sw = $this->sw();

        // Première version : le ménage effaçait tout, y compris ce qu'il
        // venait de mettre en réserve — l'écran de connexion étant justement
        // celui qui l'installe. Résultat, plus rien à servir en cas de
        // coupure. Le ménage ne vise que ce qui n'est pas anonyme.
        $this->assertStringContainsString('async function faireLeMenage()', $sw);
        $this->assertStringContainsString('await garnirLeSocle();', $sw);

        preg_match('/async function faireLeMenage\(\).*?\n}/s', $sw, $trouve);
        $this->assertStringContainsString('!socle.has(requete.url)', $trouve[0]);
        $this->assertStringContainsString('!estUnFichierFige(chemin)', $trouve[0]);
    }

    public function test_la_reserve_se_reconstitue_apres_un_menage(): void
    {
        // Sans cela, un cache vidé une fois le resterait jusqu'à la
        // prochaine version du service worker.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(.activate.*?garnirLeSocle\(\)/s',
            $this->sw()
        );
    }

    // ═══════════════════════════════════════════════════════════
    // La page de coupure
    // ═══════════════════════════════════════════════════════════

    public function test_la_page_de_coupure_repond_sans_session(): void
    {
        // Hors connexion il n'y a plus de session à vérifier : la page doit
        // s'afficher quand même, sinon le service worker n'a rien à servir.
        $this->get(route('hors-ligne'))
            ->assertOk()
            ->assertSee('Pas de connexion')
            ->assertSee('les dossiers sont sur le serveur');
    }

    public function test_la_page_de_coupure_ne_porte_aucun_formulaire(): void
    {
        $page = $this->get(route('hors-ligne'))->getContent();

        // Un formulaire sur une page mise en cache, c'est le jeton périmé
        // qui revient par la fenêtre.
        $this->assertStringNotContainsString('<form', $page);
        $this->assertStringNotContainsString('_token', $page);
        $this->assertStringNotContainsString('csrf', $page);
    }

    public function test_la_page_de_coupure_ne_depend_daucun_fichier_exterieur(): void
    {
        $page = $this->get(route('hors-ligne'))->getContent();

        // Hors connexion, un fichier manquant donnerait une page blanche.
        $this->assertStringNotContainsString('<link rel="stylesheet"', $page);
        $this->assertStringNotContainsString('<script', $page);
        $this->assertStringContainsString('<style>', $page);
    }

    public function test_la_page_de_coupure_dit_quoi_faire(): void
    {
        // Une coupure réseau dans un hôpital se règle sur place : autant
        // dire par où commencer plutôt que d'afficher un dinosaure.
        $this->get(route('hors-ligne'))
            ->assertSee('le câble réseau')
            ->assertSee('onduleur')
            ->assertSee('Notez sur papier');
    }

    // ═══════════════════════════════════════════════════════════
    // Le coupe-circuit
    // ═══════════════════════════════════════════════════════════

    public function test_le_service_worker_sannonce_actif_sur_les_ecrans(): void
    {
        config(['dpi.service_worker' => true]);

        $this->actingAs(User::where('email', 'admin@dpi-rdc.local')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('name="sw-actif" content="1"', false);
    }

    public function test_le_coupe_circuit_se_tire_depuis_le_env(): void
    {
        config(['dpi.service_worker' => false]);

        // À false, la page dit au service worker déjà installé de partir :
        // il a fallu le faire une fois en production, cela doit rester
        // possible sans redéployer.
        $this->actingAs(User::where('email', 'admin@dpi-rdc.local')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('name="sw-actif" content="0"', false);
    }

    public function test_le_coupe_circuit_vaut_aussi_pour_lecran_de_connexion(): void
    {
        // C'est la seule page qu'un poste bloqué peut encore atteindre :
        // si le coupe-circuit ne s'y appliquait pas, un service worker en
        // panne n'aurait plus aucun moyen de se faire désinstaller.
        config(['dpi.service_worker' => false]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="sw-actif" content="0"', false);
    }

    public function test_lecran_de_connexion_se_signale_pour_vider_le_cache(): void
    {
        // Revenir à la connexion, c'est que le poste change de mains.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-ecran="connexion"', false);
    }

    public function test_le_coupe_circuit_desinstalle_et_vide(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('unregister()', $js);
        $this->assertStringContainsString('caches.delete', $js);
        $this->assertStringContainsString('vider-le-cache', $js);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que la coupure fait à la saisie
    // ═══════════════════════════════════════════════════════════

    public function test_un_formulaire_hors_ligne_est_retenu_avant_de_partir_dans_le_vide(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        // Sans cela, la saisie part dans le vide et personne ne le dit.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(.submit.,.*?navigator\.onLine.*?preventDefault/s',
            $js
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que le manifeste promet
    // ═══════════════════════════════════════════════════════════

    public function test_le_manifeste_et_ses_icones_existent(): void
    {
        $manifeste = json_decode(file_get_contents(public_path('manifest.json')), true);

        $this->assertSame('/dashboard', $manifeste['start_url']);

        foreach ($manifeste['icons'] as $icone) {
            $this->assertFileExists(public_path(ltrim($icone['src'], '/')),
                "L'icône {$icone['src']} est annoncée sans exister : la mise en réserve échouerait.");
        }
    }

    public function test_les_fichiers_annonces_dans_la_reserve_existent_vraiment(): void
    {
        // Une entrée qui manque fait échouer l'installation du service
        // worker sans rien dire.
        $this->assertFileExists(public_path('manifest.json'));
        $this->assertFileExists(public_path('sw.js'));
        $this->get(route('hors-ligne'))->assertOk();
    }
}
