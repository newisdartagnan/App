<?php

namespace Tests\Feature\Dossier;

use App\Models\Antecedent;
use App\Models\DemandeRectification;
use App\Models\EntreeAccesDossier;
use App\Models\PieceJointeDemande;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Journal des accès au dossier (revue de sécurité) : une ligne par lecture
 * d'un médecin, y compris pendant l'appel ; non modifiable ; visible du
 * patient, sans adresse IP. Et aucune donnée de santé dans les journaux
 * Laravel ni dans les adresses.
 */
class JournalAccesDossierTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation();
    }

    private function urlMedecin(): string
    {
        return "/personnel/medecin/patients/{$this->patientRdv->id}/dossier";
    }

    public function test_chaque_lecture_du_dossier_par_le_medecin_est_notee(): void
    {
        $this->commePersonnel($this->medecinRdv->user)->get($this->urlMedecin())->assertOk();
        $this->commePersonnel($this->medecinRdv->user)->get($this->urlMedecin())->assertOk();

        $entrees = EntreeAccesDossier::all();
        $this->assertCount(2, $entrees);
        $this->assertSame(
            [$this->medecinRdv->user_id, $this->patientRdv->id, 'dossier', '127.0.0.1'],
            [$entrees[0]->lecteur_id, $entrees[0]->patient_id, $entrees[0]->objet, $entrees[0]->ip],
        );
        $this->assertNotNull($entrees[0]->cree_le);
    }

    public function test_pendant_l_appel_le_dossier_et_les_pieces_sont_notes(): void
    {
        $this->entrer($this->patientRdv);
        Storage::disk('local')->put('pieces-jointes/'.$this->rdv->demande_id.'/f', 'analyse');
        $piece = new PieceJointeDemande(['chemin' => 'pieces-jointes/'.$this->rdv->demande_id.'/f', 'nom_original' => 'analyse.pdf', 'type_mime' => 'application/pdf', 'taille_octets' => 7]);
        $piece->demande_id = $this->rdv->demande_id;
        $piece->save();

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.dossier'))->assertOk();
        $this->commeMedecinRdv()->get($this->adresse('personnel.medecin.consultation.piece-jointe', ['piece' => $piece->id]))->assertOk();

        $this->assertEquals(
            [['dossier_appel', $this->rdv->id], ['piece_jointe_appel', $piece->id]],
            EntreeAccesDossier::orderBy('cree_le')->orderBy('objet')->get()->map(fn ($e) => [$e->objet, $e->objet_id])->all(),
        );
    }

    public function test_un_acces_refuse_ou_la_lecture_par_le_patient_ne_sont_pas_notes(): void
    {
        $this->commePersonnel($this->medecinActive()->user)->get($this->urlMedecin())->assertNotFound();
        $this->commePersonnel($this->personnelActive('caisse'))->get($this->urlMedecin())->assertForbidden();
        $this->actingAs($this->patientRdv)->get('/mon-dossier')->assertOk();
        $this->actingAs($this->patientRdv)->get('/mon-dossier/pdf')->assertOk();

        $this->assertSame(0, EntreeAccesDossier::count());
    }

    public function test_le_journal_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $this->commePersonnel($this->medecinRdv->user)->get($this->urlMedecin());
        $entree = EntreeAccesDossier::sole();

        foreach (["UPDATE acces_dossier SET ip = '10.0.0.1'", 'DELETE FROM acces_dossier', 'TRUNCATE acces_dossier'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException) {
            }
        }
        foreach ([fn () => $entree->forceFill(['ip' => '10.0.0.1'])->save(), fn () => $entree->delete()] as $operation) {
            try {
                $operation();
                $this->fail('Le modèle a accepté de modifier le journal.');
            } catch (LogicException) {
            }
        }
        $this->assertSame('127.0.0.1', $entree->fresh()->ip);
    }

    public function test_le_patient_voit_qui_a_consulte_son_dossier_sans_l_adresse_ip(): void
    {
        $this->medecinRdv->user->forceFill(['prenom' => 'Aimée', 'nom' => 'Kabila'])->save();
        $this->commePersonnel($this->medecinRdv->user)->get($this->urlMedecin());

        $reponse = $this->actingAs($this->patientRdv)->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->has('acces', 1)
            ->where('acces.0.qui', 'Dr Aimée KABILA')
            ->where('acces.0.quoi', 'Dossier médical')
            ->has('acces.0.quand')->missing('acces.0.ip'));
        $this->assertStringNotContainsString('127.0.0.1', $reponse->getContent());
    }

    public function test_aucune_donnee_de_sante_dans_les_journaux_laravel(): void
    {
        $messages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$messages) {
            $messages[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
        });

        $this->actingAs($this->patientRdv);
        $this->post('/mon-dossier/antecedents', ['categorie' => 'allergie', 'libelle' => 'Pénicilline-ZX']);
        $this->post('/mon-dossier/constantes', ['type' => 'poids', 'valeur' => '999']);
        $this->post('/mon-dossier/rectifications', ['demande' => 'Diabète-ZX à corriger dans mon dossier.']);
        $this->get('/mon-dossier/pdf');
        $this->commePersonnel($this->medecinRdv->user)->get($this->urlMedecin());
        $this->post($this->urlMedecin().'/antecedents', ['categorie' => 'medical', 'libelle' => 'Drépanocytose-ZX']);

        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression('/ZX|999/u', $message, 'Donnée de santé dans un journal.');
        }
        $this->assertSame(2, Antecedent::count(), 'Les saisies ont bien eu lieu.');
        $this->assertSame(1, DemandeRectification::count());
    }

    public function test_aucune_donnee_de_sante_dans_les_adresses(): void
    {
        // Les adresses du dossier ne portent que des identifiants UUID.
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'dossier') || str_contains((string) $r->getName(), '.dossier'));
        $this->assertGreaterThan(10, $routes->count());
        foreach ($routes as $route) {
            $this->assertEmpty(array_diff($route->parameterNames(), ['patient', 'antecedent', 'demande', 'rendezVous']), $route->uri());
            foreach ($route->parameterNames() as $parametre) {
                $this->assertArrayHasKey($parametre, $route->wheres, "{$route->uri()} : {$parametre} non contraint en UUID.");
            }
        }

        // Après une saisie, la redirection ne transporte rien de médical.
        $reponse = $this->actingAs($this->patientRdv)->post('/mon-dossier/antecedents', ['categorie' => 'allergie', 'libelle' => 'Arachide']);
        $this->assertStringNotContainsString('Arachide', (string) $reponse->headers->get('Location'));
        $this->assertStringNotContainsString('allergie', (string) $reponse->headers->get('Location'));
    }
}
