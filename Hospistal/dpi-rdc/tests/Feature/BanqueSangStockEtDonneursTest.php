<?php

namespace Tests\Feature;

use App\Models\DemandeSang;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BanqueSangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'amont de la banque de sang : le stock, le fichier des donneurs, le don,
 * le dépistage, la demande d'un service.
 *
 * La délivrance et l'hémovigilance sont couvertes par DialyseEtBanqueSangTest
 * et HemovigilanceEtReseauSangTest ; ici, tout ce qui précède : une poche ne
 * part jamais si elle n'a pas été prélevée sur un donneur apte, puis dépistée
 * négative.
 */
class BanqueSangStockEtDonneursTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected BanqueSangService $banque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->banque = app(BanqueSangService::class);
        $this->actingAs($this->admin);
    }

    protected function donneur(string $groupe = 'O-', array $attributs = []): DonneurSang
    {
        return DonneurSang::create([
            'establishment_id' => $this->etab->id,
            'code' => $this->banque->genererCodeDonneur($this->etab->id),
            'nom' => 'MBUYI', 'prenom' => 'Didier', 'sexe' => 'M',
            'groupe_sanguin' => $groupe,
            'telephone' => '0810000100',
            'type_donneur' => 'benevole',
            'est_eligible' => true,
            ...$attributs,
        ]);
    }

    /** Poche prélevée et dépistée négative : en rayon. */
    protected function pocheEnRayon(string $groupe = 'O-'): PocheSang
    {
        return $this->banque->enregistrerDepistage(
            $this->banque->enregistrerDon($this->donneur($groupe), ['type_produit' => 'sang_total']),
            []
        );
    }

    protected function patient(?string $groupe, string $dossier = 'PAT-2026-013000'): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => $dossier,
            'nom' => 'KASONGO', 'prenom' => 'Béatrice', 'sexe' => 'F',
            'date_naissance' => now()->subYears(34)->toDateString(),
            'type_prise_en_charge' => 'prive',
            'groupe_sanguin' => $groupe,
        ]);
    }

    protected function compte(string $role): User
    {
        $user = User::factory()->create(['establishment_id' => $this->etab->id]);
        $user->assignRole($role);

        return $user;
    }

    // ═══════════════════════════════════════════════════════════
    // Accès
    // ═══════════════════════════════════════════════════════════

    public function test_la_banque_est_fermee_aux_profils_hors_laboratoire_et_soins(): void
    {
        $this->actingAs($this->compte('caissier'));
        $donneur = $this->donneur();

        $this->get(route('banque-sang.index'))->assertForbidden();
        $this->get(route('banque-sang.donneurs'))->assertForbidden();
        $this->post(route('banque-sang.donneurs.store'), [
            'nom' => 'INTRUS', 'groupe_sanguin' => 'O-', 'type_donneur' => 'benevole',
        ])->assertForbidden();
        $this->post(route('banque-sang.don', $donneur), ['type_produit' => 'sang_total'])
            ->assertForbidden();

        $this->assertSame(1, DonneurSang::count());
        $this->assertSame(0, PocheSang::count());
    }

    public function test_le_laborantin_ouvre_la_banque(): void
    {
        $this->actingAs($this->compte('laborantin'));

        $this->get(route('banque-sang.index'))->assertOk();
        $this->get(route('banque-sang.donneurs'))->assertOk();
    }

    // ═══════════════════════════════════════════════════════════
    // Tableau du stock
    // ═══════════════════════════════════════════════════════════

    public function test_le_tableau_retire_les_poches_perimees_avant_de_les_montrer(): void
    {
        $bonne = $this->pocheEnRayon('O-');
        $perimee = $this->pocheEnRayon('O-');
        $perimee->update(['date_peremption' => now()->subDay()->toDateString()]);

        $reponse = $this->get(route('banque-sang.index'))->assertOk();

        $this->assertSame('perimee', $perimee->fresh()->statut);
        $this->assertEquals([$bonne->id], $reponse->viewData('poches')->pluck('id')->all());
        $this->assertSame(1, $reponse->viewData('stock')['delivrables']);
    }

    public function test_le_tableau_filtre_le_stock_par_groupe(): void
    {
        $oNegatif = $this->pocheEnRayon('O-');
        $this->pocheEnRayon('A+');

        $reponse = $this->get(route('banque-sang.index', ['groupe' => 'O-']))->assertOk();

        $this->assertEquals([$oNegatif->id], $reponse->viewData('poches')->pluck('id')->all());
        $this->assertSame('O-', $reponse->viewData('groupeRecherche'));
    }

    public function test_le_tableau_liste_les_demandes_ouvertes_urgentes_en_tete(): void
    {
        $patient = $this->patient('A+');
        $ordinaire = $this->banque->creerDemande($patient, ['type_produit' => 'sang_total']);
        $urgente = $this->banque->creerDemande($patient, ['type_produit' => 'sang_total', 'urgence' => true]);
        $servie = $this->banque->creerDemande($patient, ['type_produit' => 'sang_total']);
        $servie->update(['statut' => 'servie']);

        $reponse = $this->get(route('banque-sang.index'))->assertOk();

        $this->assertEquals(
            [$urgente->id, $ordinaire->id],
            $reponse->viewData('demandesOuvertes')->pluck('id')->all()
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Fichier des donneurs
    // ═══════════════════════════════════════════════════════════

    public function test_la_recherche_de_donneur_ignore_la_casse_et_couvre_le_telephone(): void
    {
        $cherche = $this->donneur('B+', ['nom' => 'TSHIBANDA', 'telephone' => '0899123456']);
        $this->donneur('B+', ['nom' => 'LUKUSA', 'telephone' => '0811000000']);

        $parNom = $this->get(route('banque-sang.donneurs', ['recherche' => 'tshib']))->assertOk();
        $this->assertEquals([$cherche->id], $parNom->viewData('donneurs')->pluck('id')->all());

        $parTelephone = $this->get(route('banque-sang.donneurs', ['recherche' => '0899123']))->assertOk();
        $this->assertEquals([$cherche->id], $parTelephone->viewData('donneurs')->pluck('id')->all());
    }

    public function test_pour_un_receveur_seuls_les_donneurs_compatibles_et_aptes_sortent(): void
    {
        $oNegatif = $this->donneur('O-', ['nom' => 'AAA']);
        $aNegatif = $this->donneur('A-', ['nom' => 'BBB']);
        $this->donneur('A+', ['nom' => 'CCC']);                         // Rhésus incompatible
        $this->donneur('B-', ['nom' => 'DDD']);                         // groupe incompatible
        $this->donneur('O-', ['nom' => 'EEE', 'est_eligible' => false]); // écarté

        $reponse = $this->get(route('banque-sang.donneurs', ['pour_receveur' => 'A-']))->assertOk();

        $this->assertEquals(
            [$oNegatif->id, $aNegatif->id],
            $reponse->viewData('donneurs')->pluck('id')->all()
        );
    }

    public function test_l_inscription_d_un_donneur_lui_donne_un_code_et_le_rend_apte(): void
    {
        $this->from(route('banque-sang.donneurs'))
            ->post(route('banque-sang.donneurs.store'), [
                'nom' => 'kalala', 'prenom' => 'Ruth', 'sexe' => 'F',
                'groupe_sanguin' => 'AB+',
                'telephone' => '0975000000',
                'type_donneur' => 'familial',
            ])
            ->assertRedirect(route('banque-sang.donneurs'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'DON-000001')
                && str_contains($message, 'KALALA'));

        $donneur = DonneurSang::sole();
        $this->assertSame('DON-000001', $donneur->code);
        $this->assertSame($this->etab->id, $donneur->establishment_id);
        $this->assertTrue($donneur->est_eligible);
        $this->assertTrue($donneur->peutDonnerMaintenant());
    }

    public function test_un_donneur_sans_groupe_n_est_pas_inscrit(): void
    {
        $this->post(route('banque-sang.donneurs.store'), [
            'nom' => 'SANSGROUPE', 'type_donneur' => 'benevole',
        ])->assertSessionHasErrors('groupe_sanguin');

        $this->post(route('banque-sang.donneurs.store'), [
            'nom' => 'MAUVAISGROUPE', 'groupe_sanguin' => 'C+', 'type_donneur' => 'benevole',
        ])->assertSessionHasErrors('groupe_sanguin');

        $this->assertSame(0, DonneurSang::count());
    }

    // ═══════════════════════════════════════════════════════════
    // Le don
    // ═══════════════════════════════════════════════════════════

    public function test_un_don_cree_une_poche_en_quarantaine_au_groupe_du_donneur(): void
    {
        $donneur = $this->donneur('B-');

        $this->post(route('banque-sang.don', $donneur), [
            'type_produit' => 'concentre_globulaire',
            'volume_ml' => 300,
        ])->assertSessionHas('success', fn ($message) => str_contains($message, 'quarantaine'));

        $poche = PocheSang::sole();
        $this->assertSame('quarantaine', $poche->statut);
        $this->assertSame('B-', $poche->groupe_sanguin);
        $this->assertSame(300, (int) $poche->volume_ml);
        $this->assertSame(
            now()->addDays(PocheSang::CONSERVATION_JOURS['concentre_globulaire'])->toDateString(),
            $poche->date_peremption->toDateString()
        );
        $this->assertFalse($poche->estDelivrable(), 'Une poche non dépistée ne part pas.');

        $donneur->refresh();
        $this->assertSame(1, (int) $donneur->nombre_dons);
        $this->assertSame(now()->toDateString(), $donneur->dernier_don->toDateString());
    }

    public function test_un_donneur_ecarte_ne_donne_pas(): void
    {
        $donneur = $this->donneur('O+', [
            'est_eligible' => false,
            'motif_exclusion' => 'Dépistage positif (Hépatite B)',
        ]);

        $this->post(route('banque-sang.don', $donneur), ['type_produit' => 'sang_total'])
            ->assertSessionHas('error', 'Donneur écarté : Dépistage positif (Hépatite B)');

        $this->assertSame(0, PocheSang::count());
    }

    public function test_le_delai_entre_deux_dons_est_plus_long_pour_une_femme(): void
    {
        // Dix semaines : assez pour un homme (8), pas pour une femme (12).
        $il = $this->donneur('O+', ['sexe' => 'M', 'dernier_don' => now()->subWeeks(10)->toDateString()]);
        $elle = $this->donneur('O+', ['sexe' => 'F', 'dernier_don' => now()->subWeeks(10)->toDateString()]);

        $this->post(route('banque-sang.don', $elle), ['type_produit' => 'sang_total'])
            ->assertSessionHas('error', fn ($message) => str_starts_with($message, 'Délai non écoulé'));
        $this->assertSame(0, PocheSang::count());

        $this->post(route('banque-sang.don', $il), ['type_produit' => 'sang_total'])
            ->assertSessionHas('success');
        $this->assertSame(1, PocheSang::count());
    }

    public function test_un_don_date_dans_le_futur_est_refuse(): void
    {
        $this->post(route('banque-sang.don', $this->donneur()), [
            'type_produit' => 'sang_total',
            'date_prelevement' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('date_prelevement');

        $this->assertSame(0, PocheSang::count());
    }

    // ═══════════════════════════════════════════════════════════
    // Le dépistage
    // ═══════════════════════════════════════════════════════════

    public function test_le_formulaire_de_depistage_demande_une_confirmation(): void
    {
        $this->banque->enregistrerDon($this->donneur(), ['type_produit' => 'sang_total']);

        $this->get(route('banque-sang.index'))
            ->assertOk()
            ->assertSee('name="confirmation" value="1" class="rounded mt-0.5" required', false)
            ->assertSee('data-depistage', false)
            ->assertSee('window.confirm(message)', false);
    }

    public function test_sans_confirmation_le_depistage_n_est_pas_enregistre(): void
    {
        // Le clic trop rapide : rien coché, pas même la confirmation. La
        // poche ne doit surtout pas passer en rayon.
        $poche = $this->banque->enregistrerDon($this->donneur(), ['type_produit' => 'sang_total']);

        $this->post(route('banque-sang.depister', $poche), [])
            ->assertSessionHasErrors(['confirmation' => 'Dépistage non enregistré : confirmez avoir lu les cinq '
                .'résultats (un marqueur non coché vaut négatif). La poche reste en quarantaine.']);

        $this->post(route('banque-sang.depister', $poche), ['depistage_vih' => '1'])
            ->assertSessionHasErrors('confirmation');

        $poche->refresh();
        $this->assertSame('quarantaine', $poche->statut);
        $this->assertNull($poche->depistage_vih);
        $this->assertNull($poche->date_depistage);
        $this->assertFalse($poche->estDelivrable());
    }

    public function test_sans_marqueur_coche_la_poche_passe_en_rayon(): void
    {
        // Le formulaire ne fait cocher que les marqueurs positifs : un envoi
        // confirmé sans case cochée est un dépistage négatif sur les cinq.
        $poche = $this->banque->enregistrerDon($this->donneur(), ['type_produit' => 'sang_total']);

        $this->post(route('banque-sang.depister', $poche), ['confirmation' => '1'])
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'négatif'));

        $poche->refresh();
        $this->assertSame('disponible', $poche->statut);
        $this->assertTrue($poche->depistageComplet());
        $this->assertTrue($poche->estDelivrable());
        $this->assertSame($this->admin->id, $poche->depiste_par);
    }

    public function test_un_marqueur_positif_detruit_la_poche_et_ecarte_le_donneur(): void
    {
        $donneur = $this->donneur('A+');
        $poche = $this->banque->enregistrerDon($donneur, ['type_produit' => 'sang_total']);

        $this->post(route('banque-sang.depister', $poche), ['depistage_hepatite_c' => '1', 'confirmation' => '1'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'détruite')
                && str_contains($message, 'Hépatite C'));

        $this->assertSame('detruite', $poche->fresh()->statut);
        $this->assertFalse($poche->fresh()->estDelivrable());

        $donneur->refresh();
        $this->assertFalse($donneur->est_eligible);
        $this->assertStringContainsString('Hépatite C', $donneur->motif_exclusion);
        $this->assertFalse($donneur->peutDonnerMaintenant());
    }

    public function test_une_poche_detruite_ne_revient_pas_en_rayon_si_le_formulaire_est_renvoye(): void
    {
        // Double clic ou retour arrière : le même formulaire repart, cette
        // fois sans case cochée. La poche VIH positive doit rester détruite.
        $poche = $this->banque->enregistrerDon($this->donneur('O-'), ['type_produit' => 'sang_total']);
        $this->post(route('banque-sang.depister', $poche), ['depistage_vih' => '1', 'confirmation' => '1']);

        $this->post(route('banque-sang.depister', $poche), ['confirmation' => '1'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ne se redépiste pas'));

        $poche->refresh();
        $this->assertSame('detruite', $poche->statut);
        $this->assertTrue($poche->depistage_vih);
        $this->assertFalse($poche->estDelivrable());
        $this->assertSame(0, PocheSang::delivrables()->count());
    }

    public function test_une_poche_deja_en_rayon_ne_se_redepiste_pas(): void
    {
        $poche = $this->pocheEnRayon('O-');
        $datePremierDepistage = $poche->date_depistage;

        $this->post(route('banque-sang.depister', $poche), ['depistage_paludisme' => '1', 'confirmation' => '1'])
            ->assertSessionHas('error');

        $poche->refresh();
        $this->assertSame('disponible', $poche->statut);
        $this->assertFalse($poche->depistage_paludisme);
        $this->assertEquals($datePremierDepistage, $poche->date_depistage);
    }

    public function test_le_service_refuse_lui_aussi_de_redepister_hors_quarantaine(): void
    {
        $poche = $this->pocheEnRayon('O-');
        $poche->update(['statut' => 'transfusee']);

        $this->expectException(\LogicException::class);

        $this->banque->enregistrerDepistage($poche, []);
    }

    // ═══════════════════════════════════════════════════════════
    // La demande d'un service
    // ═══════════════════════════════════════════════════════════

    public function test_une_demande_prend_le_groupe_du_patient_et_son_sejour_en_cours(): void
    {
        $patient = $this->patient('A+');
        $visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'service_id' => Service::value('id'),
            'motif_consultation' => 'Hémorragie du post-partum',
        ]);

        $reponse = $this->post(route('banque-sang.demander'), [
            'patient_id' => $patient->id,
            'type_produit' => 'concentre_globulaire',
            'nombre_poches' => 2,
            'hemoglobine' => 6.1,
            'urgence' => '1',
        ]);

        $demande = DemandeSang::sole();
        $reponse->assertRedirect(route('banque-sang.demande', $demande))
            ->assertSessionHas('success', fn ($message) => str_contains($message, $demande->numero));

        $this->assertSame('A+', $demande->groupe_demande);
        $this->assertSame($visite->id, $demande->visit_id);
        $this->assertSame($this->admin->id, $demande->demandeur_id);
        $this->assertTrue((bool) $demande->urgence);
        $this->assertSame('en_attente', $demande->statut);
        $this->assertSame(2, (int) $demande->nombre_poches);
    }

    public function test_une_demande_pour_un_groupe_inconnu_previent_que_seul_du_o_negatif_sera_propose(): void
    {
        $patient = $this->patient(null);

        $reponse = $this->post(route('banque-sang.demander'), [
            'patient_id' => $patient->id,
            'type_produit' => 'sang_total',
            'nombre_poches' => 1,
        ]);

        $demande = DemandeSang::sole();
        $reponse->assertRedirect(route('banque-sang.demande', $demande))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'O négatif'));

        $this->assertNull($demande->groupeReceveur());
        $this->assertSame(['O-'], PocheSang::groupesCompatiblesPour($demande->groupeReceveur()));
    }

    public function test_une_demande_incomplete_n_est_pas_ouverte(): void
    {
        $patient = $this->patient('O+');

        $this->post(route('banque-sang.demander'), [
            'patient_id' => '00000000-0000-0000-0000-000000000000',
            'type_produit' => 'sang_total',
            'nombre_poches' => 1,
        ])->assertSessionHasErrors('patient_id');

        $this->post(route('banque-sang.demander'), [
            'patient_id' => $patient->id,
            'type_produit' => 'sang_total',
            'nombre_poches' => 0,
        ])->assertSessionHasErrors('nombre_poches');

        $this->post(route('banque-sang.demander'), [
            'patient_id' => $patient->id,
            'type_produit' => 'whisky',
            'nombre_poches' => 1,
        ])->assertSessionHasErrors('type_produit');

        $this->assertSame(0, DemandeSang::count());
    }
}
