<?php

namespace Tests\Feature;

use App\Models\DemandeSang;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\Facture;
use App\Models\NotificationInterne;
use App\Models\Parametre;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\PrescriptionDiete;
use App\Models\Service;
use App\Models\Transfusion;
use App\Models\TypeDiete;
use App\Models\User;
use App\Models\Visit;
use App\Services\BanqueSangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'après-délivrance : hémovigilance, facturation, réseau.
 *
 * Sortir une poche du réfrigérateur n'est pas transfuser. Tant que personne
 * n'écrit l'heure de fin, l'hémoglobine de contrôle et l'incident éventuel,
 * la banque n'a enregistré qu'un mouvement de stock — et un accident
 * transfusionnel n'a nulle part où se déclarer.
 */
class HemovigilanceEtReseauSangTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $patient;

    protected Visit $visite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-012000',
            'nom' => 'NGOY', 'prenom' => 'Espérance', 'sexe' => 'F',
            'date_naissance' => now()->subYears(29)->toDateString(),
            'type_prise_en_charge' => 'prive',
            'groupe_sanguin' => 'A+',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'service_id' => Service::where('type', 'medecine_interne')->value('id')
                ?? Service::value('id'),
            'motif_consultation' => 'Anémie sévère',
        ]);
    }

    /** Un donneur, une poche dépistée négative, prête à partir. */
    protected function pocheDisponible(string $groupe = 'O-', string $produit = 'sang_total'): PocheSang
    {
        $banque = app(BanqueSangService::class);

        $donneur = DonneurSang::create([
            'establishment_id' => $this->etab->id,
            'code' => $banque->genererCodeDonneur($this->etab->id),
            'nom' => 'ILUNGA', 'prenom' => 'Joseph', 'sexe' => 'M',
            'groupe_sanguin' => $groupe,
            'telephone' => '0810000001',
            'type_donneur' => 'benevole',
            'est_eligible' => true,
        ]);

        $poche = $banque->enregistrerDon($donneur, ['type_produit' => $produit]);

        return $banque->enregistrerDepistage($poche, [
            'depistage_vih' => false, 'depistage_hepatite_b' => false,
            'depistage_hepatite_c' => false, 'depistage_syphilis' => false,
            'depistage_paludisme' => false,
        ]);
    }

    protected function demandeOuverte(int $poches = 1): DemandeSang
    {
        return app(BanqueSangService::class)->creerDemande($this->patient, [
            'type_produit' => 'sang_total',
            'nombre_poches' => $poches,
            'hemoglobine' => 5.4,
            'indication' => 'Anémie sévère mal tolérée',
            'visit_id' => $this->visite->id,
        ]);
    }

    protected function delivrer(DemandeSang $demande, PocheSang $poche): Transfusion
    {
        $resultat = app(BanqueSangService::class)->delivrer($demande, $poche, [
            'controle_ultime' => true,
            'hemoglobine_avant' => 5.4,
            'heure_debut' => '08:00',
        ]);

        $this->assertNull($resultat['erreur'], (string) $resultat['erreur']);

        return $resultat['transfusion'];
    }

    // ═══════════════════════════════════════════════════════════
    // Hémovigilance : la transfusion se clôture
    // ═══════════════════════════════════════════════════════════

    public function test_une_poche_delivree_ouvre_une_transfusion_non_cloturee(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->assertFalse($transfusion->estCloturee());
        $this->assertNull($transfusion->heure_fin);
        // Elle est en cours, pas terminée : le registre doit le dire.
        $this->assertTrue($transfusion->enCours());
    }

    public function test_la_cloture_enregistre_lheure_de_fin_et_lhemoglobine(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->post(route('banque-sang.cloturer', $transfusion), [
            'heure_fin' => '11:30',
            'hemoglobine_apres' => 6.8,
            'incident' => 'aucun',
            'observation' => 'Bien supportée',
        ])->assertRedirect();

        $transfusion->refresh();

        $this->assertTrue($transfusion->estCloturee());
        $this->assertSame('11:30', substr((string) $transfusion->heure_fin, 0, 5));
        $this->assertSame(1.4, $transfusion->rendement());
        $this->assertSame($this->admin->id, $transfusion->cloturee_par);
        $this->assertSame(210, $transfusion->dureeMinutes());
    }

    public function test_lheure_de_fin_est_obligatoire(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->post(route('banque-sang.cloturer', $transfusion), [
            'incident' => 'aucun',
        ])->assertSessionHasErrors('heure_fin');

        $this->assertFalse($transfusion->fresh()->estCloturee());
    }

    public function test_le_silence_ne_vaut_pas_absence_dincident(): void
    {
        // « Aucun » est une réponse ; ne rien dire n'en est pas une.
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->post(route('banque-sang.cloturer', $transfusion), [
            'heure_fin' => '11:00',
        ])->assertSessionHasErrors('incident');
    }

    public function test_une_transfusion_deja_cloturee_ne_se_recloture_pas(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->post(route('banque-sang.cloturer', $transfusion), [
            'heure_fin' => '11:00', 'incident' => 'aucun',
        ]);

        $this->post(route('banque-sang.cloturer', $transfusion), [
            'heure_fin' => '23:00', 'incident' => 'hemolyse',
        ])->assertSessionHas('info');

        $this->assertSame('aucun', $transfusion->fresh()->incident);
    }

    public function test_un_gain_dhemoglobine_trop_faible_est_signale(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $transfusion->update(['hemoglobine_avant' => 5.4, 'hemoglobine_apres' => 5.6]);

        $this->assertSame(0.2, $transfusion->fresh()->rendement());
        $this->assertTrue($transfusion->fresh()->rendementInsuffisant());
    }

    // ═══════════════════════════════════════════════════════════
    // L'incident transfusionnel
    // ═══════════════════════════════════════════════════════════

    public function test_un_incident_remonte_au_prescripteur_et_au_laboratoire(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->post(route('banque-sang.cloturer', $transfusion), [
            'heure_fin' => '08:20',
            'incident' => 'hemolyse',
            'observation' => 'Urines rouges, lombalgie',
        ])->assertSessionHas('error');

        $notifications = NotificationInterne::where('type', 'incident_transfusionnel')->get();

        $this->assertTrue($notifications->isNotEmpty());
        $this->assertTrue($notifications->contains('groupe_destinataire', 'laborantin'));
        $this->assertTrue($notifications->contains('destinataire_id', $this->admin->id));
        $this->assertTrue($notifications->every(fn ($n) => $n->priorite === 'urgente'));
    }

    public function test_les_incidents_graves_se_distinguent_des_autres(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());
        $transfusion->update(['incident' => 'frisson']);

        $this->assertTrue($transfusion->fresh()->avecIncident());
        // Frissons : on ralentit, on surveille. On ne débranche pas.
        $this->assertFalse($transfusion->fresh()->incidentEstGrave());

        $transfusion->update(['incident' => 'dyspnee']);
        $this->assertTrue($transfusion->fresh()->incidentEstGrave());
    }

    public function test_la_notification_dincident_mene_au_registre(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->post(route('banque-sang.cloturer', $transfusion), [
            'heure_fin' => '08:20', 'incident' => 'urticaire',
        ]);

        $notification = NotificationInterne::where('type', 'incident_transfusionnel')->firstOrFail();

        $this->assertSame(route('banque-sang.registre'), $notification->lien());
    }

    // ═══════════════════════════════════════════════════════════
    // La poche se facture
    // ═══════════════════════════════════════════════════════════

    public function test_la_poche_delivree_est_portee_sur_une_facture(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->assertNotNull($transfusion->facture_id);

        $facture = Facture::findOrFail($transfusion->facture_id);
        $ligne = $facture->lignes()->firstOrFail();

        $this->assertSame('transfusion', $ligne->type);
        $this->assertSame(45000.0, (float) $facture->total_ttc);
        $this->assertStringContainsString('Sang total', $ligne->designation ?? $ligne->libelle ?? '');
    }

    public function test_chaque_produit_a_son_tarif(): void
    {
        $poche = $this->pocheDisponible('O-', 'plaquettes');

        $this->assertSame(65000.0, $poche->tarif());
        $this->assertSame(45000.0, $this->pocheDisponible('O+')->tarif());
    }

    public function test_une_transfusion_sans_sejour_ouvert_ne_se_facture_pas(): void
    {
        // Aux urgences, la poche part avant l'admission : il n'y a pas encore
        // de séjour à qui adresser la facture.
        $demande = app(BanqueSangService::class)->creerDemande($this->patient, [
            'type_produit' => 'sang_total', 'nombre_poches' => 1, 'visit_id' => null,
        ]);

        $transfusion = $this->delivrer($demande, $this->pocheDisponible());

        $this->assertNull($transfusion->facture_id);
    }

    // ═══════════════════════════════════════════════════════════
    // Le demandeur est prévenu
    // ═══════════════════════════════════════════════════════════

    public function test_la_delivrance_previent_le_service_demandeur(): void
    {
        $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $notification = NotificationInterne::where('type', 'poche_delivree')->firstOrFail();

        $this->assertSame($this->admin->id, $notification->destinataire_id);
        $this->assertStringContainsString('NGOY', $notification->message);
    }

    public function test_un_refus_porte_son_motif_jusquau_demandeur(): void
    {
        $demande = $this->demandeOuverte();

        $this->post(route('banque-sang.refuser', $demande), [
            'motif_refus' => 'Stock O négatif épuisé — donneurs convoqués pour 14 h.',
        ])->assertRedirect();

        $notification = NotificationInterne::where('type', 'demande_refusee')->firstOrFail();

        $this->assertSame($this->admin->id, $notification->destinataire_id);
        $this->assertStringContainsString('Stock O négatif épuisé', $notification->message);
        $this->assertSame(route('banque-sang.demande', $demande->id), $notification->lien());
    }

    // ═══════════════════════════════════════════════════════════
    // Le registre transfusionnel
    // ═══════════════════════════════════════════════════════════

    public function test_le_registre_montre_la_trace_de_la_poche(): void
    {
        $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        $this->get(route('banque-sang.registre'))
            ->assertOk()
            ->assertSee('Registre transfusionnel')
            ->assertSee('NGOY')
            ->assertSee('ILUNGA')
            ->assertSee('PS-')
            ->assertSee('clôturer');
    }

    public function test_le_registre_isole_les_transfusions_a_cloturer(): void
    {
        $ouverte = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());
        $fermee = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible('O+'));

        app(BanqueSangService::class)->cloturerTransfusion($fermee, [
            'heure_fin' => '10:00', 'incident' => 'aucun',
        ]);

        $enCours = app(BanqueSangService::class)
            ->registre($this->etab->id, ['etat' => 'en_cours']);

        $this->assertCount(1, $enCours);
        $this->assertSame($ouverte->id, $enCours->first()->id);
    }

    public function test_le_registre_isole_les_incidents(): void
    {
        $transfusion = $this->delivrer($this->demandeOuverte(), $this->pocheDisponible());

        app(BanqueSangService::class)->cloturerTransfusion($transfusion, [
            'heure_fin' => '08:15', 'incident' => 'frisson',
        ]);

        $this->delivrer($this->demandeOuverte(), $this->pocheDisponible('O+'));

        $incidents = app(BanqueSangService::class)
            ->registre($this->etab->id, ['etat' => 'incident']);

        $this->assertCount(1, $incidents);
        $this->assertSame('frisson', $incidents->first()->incident);
    }

    // ═══════════════════════════════════════════════════════════
    // Le réseau des banques
    // ═══════════════════════════════════════════════════════════

    protected function voisin(string $nom = 'Hôpital Général de Kintambo'): Establishment
    {
        return Establishment::create([
            'code' => 'HGK-'.substr(md5($nom), 0, 6),
            'name' => $nom,
            'type' => 'hopital_general',
            'province' => 'Kinshasa',
            'ville' => 'Kinshasa',
            'telephone' => '0999000111',
            'is_active' => true,
        ]);
    }

    public function test_le_reseau_montre_le_stock_des_autres_maisons(): void
    {
        $voisin = $this->voisin();

        $donneur = DonneurSang::create([
            'establishment_id' => $voisin->id,
            'code' => 'DON-000900', 'nom' => 'KABILA', 'prenom' => 'Grâce',
            'groupe_sanguin' => 'O-', 'telephone' => '0820000002',
            'type_donneur' => 'benevole', 'est_eligible' => true,
        ]);

        $poche = app(BanqueSangService::class)->enregistrerDon($donneur, ['type_produit' => 'sang_total']);
        app(BanqueSangService::class)->enregistrerDepistage($poche, [
            'depistage_vih' => false, 'depistage_hepatite_b' => false,
            'depistage_hepatite_c' => false, 'depistage_syphilis' => false,
            'depistage_paludisme' => false,
        ]);

        $reseau = app(BanqueSangService::class)->reseau($this->etab->id, 'A+');

        $this->assertCount(1, $reseau);
        $this->assertSame($voisin->name, $reseau->first()['nom']);
        // O négatif : compatible avec tous les receveurs, donc avec A+.
        $this->assertSame(1, $reseau->first()['compatibles']);
        $this->assertSame('0999000111', $reseau->first()['telephone']);
    }

    public function test_le_reseau_ne_montre_pas_sa_propre_maison(): void
    {
        $this->pocheDisponible();

        $reseau = app(BanqueSangService::class)->reseau($this->etab->id);

        $this->assertTrue($reseau->doesntContain('id', $this->etab->id));
    }

    public function test_une_maison_retiree_du_reseau_disparait_des_ecrans(): void
    {
        $voisin = $this->voisin();

        Parametre::create([
            'establishment_id' => $voisin->id,
            'cle' => BanqueSangService::CLE_PARTAGE,
            'valeur' => ['actif' => false],
        ]);

        $this->assertCount(0, app(BanqueSangService::class)->reseau($this->etab->id));
    }

    public function test_le_partage_est_ouvert_par_defaut(): void
    {
        // Une banque qui n'annonce rien ne sert à personne.
        $this->assertTrue(app(BanqueSangService::class)->partageSonStock($this->etab->id));
    }

    public function test_la_direction_peut_retirer_la_maison_du_reseau(): void
    {
        $this->post(route('banque-sang.partage'), ['partage' => 0])->assertRedirect();

        $this->assertFalse(app(BanqueSangService::class)->partageSonStock($this->etab->id));

        $this->post(route('banque-sang.partage'), ['partage' => 1]);

        $this->assertTrue(app(BanqueSangService::class)->partageSonStock($this->etab->id));
    }

    public function test_le_partage_ne_seleve_pas_au_niveau_du_laborantin(): void
    {
        $laborantin = User::factory()->create(['establishment_id' => $this->etab->id]);
        $laborantin->assignRole('laborantin');

        $this->actingAs($laborantin)
            ->post(route('banque-sang.partage'), ['partage' => 0])
            ->assertForbidden();
    }

    public function test_lecran_du_reseau_repond(): void
    {
        $this->voisin();

        $this->get(route('banque-sang.reseau', ['groupe' => 'A+']))
            ->assertOk()
            ->assertSee('Réseau des banques')
            ->assertSee('Kintambo')
            ->assertSee('visible du réseau');
    }

    // ═══════════════════════════════════════════════════════════
    // L'éligibilité du donneur, à la main
    // ═══════════════════════════════════════════════════════════

    public function test_un_donneur_secarte_avec_son_motif(): void
    {
        $poche = $this->pocheDisponible();
        $donneur = $poche->donneur;

        $this->post(route('banque-sang.eligibilite', $donneur), [
            'eligible' => 0,
            'motif_exclusion' => 'Poids insuffisant — 47 kg',
        ])->assertRedirect();

        $donneur->refresh();

        $this->assertFalse($donneur->est_eligible);
        $this->assertSame('Poids insuffisant — 47 kg', $donneur->motif_exclusion);
        $this->assertFalse($donneur->peutDonnerMaintenant());
    }

    public function test_on_ne_secarte_pas_un_donneur_sans_dire_pourquoi(): void
    {
        $donneur = $this->pocheDisponible()->donneur;

        $this->post(route('banque-sang.eligibilite', $donneur), ['eligible' => 0])
            ->assertSessionHasErrors('motif_exclusion');

        $this->assertTrue($donneur->fresh()->est_eligible);
    }

    public function test_un_donneur_ecarte_se_reintegre_et_son_motif_disparait(): void
    {
        $donneur = $this->pocheDisponible()->donneur;
        app(BanqueSangService::class)->reglerEligibilite($donneur, false, 'Grossesse en cours');

        $this->post(route('banque-sang.eligibilite', $donneur), ['eligible' => 1])->assertRedirect();

        $donneur->refresh();

        $this->assertTrue($donneur->est_eligible);
        $this->assertNull($donneur->motif_exclusion);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce qui restait inerte ailleurs dans l'application
    // ═══════════════════════════════════════════════════════════

    public function test_lentete_dit_qui_est_connecte_et_offre_la_sortie(): void
    {
        // Un poste d'hôpital passe de main en main : sans déconnexion,
        // l'infirmière de nuit signe sous le nom du médecin de garde.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee($this->admin->nom_complet)
            ->assertSee('Administrateur')
            ->assertSee('Se déconnecter')
            ->assertSee(route('logout'));
    }

    public function test_la_deconnexion_ferme_bien_la_session(): void
    {
        $this->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }

    public function test_la_diete_peut_etre_arretee_depuis_lecran_de_la_cuisine(): void
    {
        $type = TypeDiete::where('is_active', true)->firstOrFail();

        PrescriptionDiete::create([
            'visit_id' => $this->visite->id,
            'type_diete_id' => $type->id,
            'user_id' => $this->admin->id,
            'debut' => now()->subDay()->toDateString(),
        ]);

        $this->get(route('diete.index'))
            ->assertOk()
            ->assertSee('Arrêter la diète');

        $this->post(route('diete.arreter', $this->visite))->assertRedirect();

        $this->assertNotNull(
            PrescriptionDiete::where('visit_id', $this->visite->id)->value('fin')
        );
    }

    public function test_toutes_les_routes_nommees_sont_atteignables_depuis_lapplication(): void
    {
        // Une route que rien ne référence est une fonctionnalité inerte :
        // le code la sert, personne ne peut l'atteindre.
        $sources = '';

        foreach ([resource_path('views'), app_path()] as $racine) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

            foreach ($fichiers as $fichier) {
                if (str_ends_with((string) $fichier, '.php')) {
                    $sources .= file_get_contents((string) $fichier);
                }
            }
        }

        $orphelines = collect(app('router')->getRoutes()->getRoutesByName())
            // Seules nos routes sont en cause : Horizon, Livewire ou Sanctum
            // publient les leurs et savent s'en servir.
            ->filter(fn ($route) => str_starts_with(
                (string) ($route->getAction('controller') ?? ''),
                'App\\Http\\Controllers\\'
            ))
            ->keys()
            ->reject(fn (string $nom) => str_starts_with($nom, 'api.'))
            ->reject(fn (string $nom) => str_contains($sources, "'{$nom}'")
                || str_contains($sources, "\"{$nom}\""))
            ->values()
            ->all();

        $this->assertSame([], $orphelines,
            'Routes qu\'aucune vue ni aucun contrôleur n\'atteint : '.implode(', ', $orphelines));
    }
}
