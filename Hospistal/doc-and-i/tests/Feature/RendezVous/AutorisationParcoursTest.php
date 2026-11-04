<?php

namespace Tests\Feature\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\PieceJointeDemande;
use App\Models\RendezVous;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/**
 * Relation de soin : chaque route du parcours refuse l'accès aux demandes,
 * pièces jointes et rendez-vous d'un autre patient, au personnel et aux
 * visiteurs non connectés.
 */
class AutorisationParcoursTest extends TestCase
{
    use ParcoursRendezVous, RefreshDatabase;

    private User $proprietaire;

    private DemandeConsultation $demande;

    private DemandeConsultation $demandeUrgence;

    private RendezVous $rendezVous;

    private PieceJointeDemande $piece;

    private Medecin $medecin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->voyagerAuLundi('07:00');

        $this->proprietaire = $this->patient();
        $this->medecin = $this->medecin([[1, '09:00', '12:00'], [2, '09:00', '12:00']]);
        $this->demande = $this->demande($this->proprietaire);
        $this->demandeUrgence = $this->demande($this->proprietaire, 'orientee_urgence');
        $this->rendezVous = $this->rendezVousExistant($this->medecin, $this->proprietaire, $this->lundi('10:00')->addDay());
        Storage::disk('local')->put('pieces-jointes/x/secret', '%PDF-1.4');
        $this->piece = $this->demande->piecesJointes()->create([
            'chemin' => 'pieces-jointes/x/secret', 'nom_original' => 'a.pdf', 'type_mime' => 'application/pdf', 'taille_octets' => 8,
        ]);
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> [méthode, adresse, données] */
    private function routesProtegees(): array
    {
        $type = $this->typeConsultation($this->medecin);

        return [
            ['GET', route('rdv.demande.urgence', $this->demandeUrgence), []],
            ['GET', route('rdv.demande.choix', $this->demande), []],
            ['GET', route('rdv.demande.creneaux', [$this->demande, 'type' => $type->id, 'medecin' => $this->medecin->id]), []],
            ['POST', route('rdv.demande.reserver', $this->demande), [
                'medecin_id' => $this->medecin->id, 'type_consultation_id' => $type->id, 'debut' => $this->lundi('11:00')->toIso8601String(),
            ]],
            ['GET', route('rdv.piece-jointe', $this->piece), []],
            ['GET', route('rdv.voir', $this->rendezVous), []],
            ['POST', route('rdv.annuler', $this->rendezVous), ['motif' => 'intrus']],
            ['GET', route('rdv.report', $this->rendezVous), []],
            ['POST', route('rdv.reporter', $this->rendezVous), ['debut' => $this->lundi('11:00')->addDay()->toIso8601String()]],
        ];
    }

    public function test_chaque_route_a_objet_du_parcours_est_couverte_par_ce_test(): void
    {
        $couvertes = ['rdv.demande.urgence', 'rdv.demande.choix', 'rdv.demande.creneaux', 'rdv.demande.reserver',
            'rdv.piece-jointe', 'rdv.voir', 'rdv.annuler', 'rdv.report', 'rdv.reporter'];
        $sansObjet = ['rdv.demande.creer', 'rdv.demande.enregistrer', 'rdv.index'];

        $toutes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($nom) => str_starts_with($nom, 'rdv.'))->values();

        $this->assertEqualsCanonicalizing(array_merge($couvertes, $sansObjet), $toutes->all(), 'Nouvelle route rdv.* : l\'ajouter à ce test.');
    }

    public function test_un_autre_patient_recoit_404_partout_et_rien_ne_change(): void
    {
        $this->actingAs($this->patient());

        foreach ($this->routesProtegees() as [$methode, $adresse, $donnees]) {
            $this->call($methode, $adresse, $donnees)->assertNotFound();
        }

        $this->assertSame(StatutRendezVous::Confirme, $this->rendezVous->fresh()->statut);
        $this->assertSame('2026-12-08 10:00', $this->rendezVous->fresh()->debut->format('Y-m-d H:i'));
        $this->assertSame(1, RendezVous::count());
        $this->assertSame('ouverte', $this->demande->fresh()->statut);
    }

    public function test_un_autre_patient_ne_voit_pas_les_rendez_vous_ni_demandes_du_proprietaire_dans_sa_liste(): void
    {
        $this->actingAs($this->patient())
            ->get(route('rdv.index'))
            ->assertInertia(fn ($page) => $page->has('aVenir', 0)->has('passes', 0)->has('demandesOuvertes', 0));
    }

    public function test_le_personnel_n_entre_pas_dans_le_parcours_patient(): void
    {
        $this->actingAs($this->medecin->user);

        foreach ($this->routesProtegees() as [$methode, $adresse, $donnees]) {
            $this->call($methode, $adresse, $donnees)->assertForbidden();
        }
        $this->get(route('rdv.index'))->assertForbidden();
        $this->get(route('rdv.demande.creer'))->assertForbidden();
    }

    public function test_un_visiteur_non_connecte_est_renvoye_a_la_connexion(): void
    {
        foreach ($this->routesProtegees() as [$methode, $adresse, $donnees]) {
            $this->call($methode, $adresse, $donnees)->assertRedirect(route('connexion'));
        }
        $this->post(route('rdv.demande.enregistrer'), [])->assertRedirect(route('connexion'));
    }

    public function test_sans_consentement_le_parcours_reste_ferme(): void
    {
        $this->proprietaire->consentements()->update(['retire_le' => now()]);
        $this->actingAs($this->proprietaire);

        $this->get(route('rdv.demande.creer'))->assertRedirect(route('consentement'));
        $this->get(route('rdv.voir', $this->rendezVous))->assertRedirect(route('consentement'));
    }

    public function test_le_proprietaire_accede_a_ses_routes(): void
    {
        $this->actingAs($this->proprietaire);

        $this->get(route('rdv.voir', $this->rendezVous))->assertOk();
        $this->get(route('rdv.report', $this->rendezVous))->assertOk();
        $this->get(route('rdv.piece-jointe', $this->piece))->assertOk();
        $this->get(route('rdv.demande.choix', $this->demande))->assertOk();
        $this->get(route('rdv.demande.urgence', $this->demandeUrgence))->assertOk();
    }
}
