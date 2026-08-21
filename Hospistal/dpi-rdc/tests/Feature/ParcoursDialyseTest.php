<?php

namespace Tests\Feature;

use App\Models\ActeClinique;
use App\Models\Establishment;
use App\Models\Facture;
use App\Models\GenerateurDialyse;
use App\Models\LigneFacture;
use App\Models\Lit;
use App\Models\NoteEvolution;
use App\Models\Patient;
use App\Models\PrescriptionDiete;
use App\Models\SeanceDialyse;
use App\Models\Service;
use App\Models\TypeConsultation;
use App\Models\TypeDiete;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\LaboratoireService;
use App\Services\VisiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parcours complet d'une patiente insuffisante rénale chronique :
 * consultation de néphrologie, admission en dialyse, séances et bilans,
 * suivi infirmier et diète, puis règlement intégral et sortie guérie.
 *
 * Ce test tient lieu de recette de bout en bout : il traverse toutes les
 * briques dans l'ordre réel d'utilisation, et échoue dès qu'une couture
 * entre deux modules se défait.
 */
class ParcoursDialyseTest extends TestCase
{
    use RefreshDatabase;

    protected User $medecin;

    protected Establishment $etab;

    protected Patient $patiente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->medecin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->medecin);
        $this->etab = Establishment::firstOrFail();

        $this->patiente = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-004200',
            'nom' => 'NSIMBA', 'postnom' => 'MAKIESE', 'prenom' => 'Béatrice',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(58)->toDateString(),
            'type_prise_en_charge' => 'prive',
            'telephone' => '+243810000042',
        ]);
    }

    /** Règle chaque facture ouverte du séjour au guichet. */
    protected function reglerToutesLesFactures(Visit $visite): float
    {
        $total = 0.0;

        foreach ($visite->factures()->whereIn('statut', ['emise', 'partiellement_payee'])->get() as $facture) {
            $du = (float) $facture->patient_part;

            $this->post(route('caisse.encaisser', $facture), [
                'montant' => $du,
                'devise' => 'CDF',
                'mode_paiement' => 'especes',
            ])->assertSessionMissing('errors');

            $this->assertSame(
                'payee',
                $facture->fresh()->statut,
                "La facture {$facture->numero_facture} devait être soldée."
            );

            $total += $du;
        }

        return $total;
    }

    public function test_parcours_complet_dialyse_de_la_consultation_a_la_sortie_gueri(): void
    {
        // ═══════════════════════════════════════════════════════
        // 1. Consultation de néphrologie
        // ═══════════════════════════════════════════════════════
        $nephrologie = TypeConsultation::where('code', 'CONS-NEPH')->firstOrFail();

        $this->post(route('patients.envoyer-caisse', $this->patiente), [
            'type' => 'consultation_externe',
            'type_consultation_id' => $nephrologie->id,
            'motif' => 'Asthénie, œdèmes des membres inférieurs, oligurie',
        ])->assertSessionMissing('errors');

        $visite = Visit::where('patient_id', $this->patiente->id)->firstOrFail();

        $this->assertSame('consultation_externe', $visite->type);
        $this->assertSame($nephrologie->id, $visite->type_consultation_id);

        // La consultation se paie au guichet avant que le médecin ne reçoive.
        $factureConsultation = $visite->factures()->firstOrFail();
        $this->assertFalse($visite->consultationPayee(), 'Rien n\'est payé à ce stade.');

        $this->post(route('caisse.encaisser', $factureConsultation), [
            'montant' => (float) $factureConsultation->patient_part,
            'devise' => 'CDF',
            'mode_paiement' => 'especes',
        ]);

        $this->assertTrue($visite->fresh()->consultationPayee(), 'La consultation doit être réglée.');

        // ═══════════════════════════════════════════════════════
        // 2. Admission en dialyse
        // ═══════════════════════════════════════════════════════
        $dialyse = Service::where('code', 'DIAL')->firstOrFail();
        $lit = Lit::where('service_id', $dialyse->id)->where('statut', 'libre')->firstOrFail();

        $this->post(route('visites.hospitaliser', $visite), [
            'service_id' => $dialyse->id,
            'lit_id' => $lit->id,
        ])->assertSessionHas('success');

        $visite->refresh();

        $this->assertSame('hospitalisation', $visite->type);
        $this->assertSame($dialyse->id, $visite->service_id);
        $this->assertSame($lit->id, $visite->lit_id);
        $this->assertSame('occupe', $lit->fresh()->statut, 'Le lit doit être marqué occupé.');
        $this->assertTrue($visite->serviACredit(), 'Un hospitalisé est servi puis réglé à la sortie.');

        // Le séjour a commencé quatre jours plus tôt : on antidate pour que
        // les journées, les diètes et la facture portent sur une vraie durée.
        $visite->update(['date_entree' => now()->subDays(4)]);
        $visite->refresh();
        $this->assertSame(5, $visite->joursHospitalisation());

        // ═══════════════════════════════════════════════════════
        // 3. Diète prescrite dès l'admission
        // ═══════════════════════════════════════════════════════
        $hyposodee = TypeDiete::where('code', 'DHS')->firstOrFail();

        $this->post(route('diete.prescrire', $visite), [
            'type_diete_id' => $hyposodee->id,
            'debut' => now()->subDays(4)->toDateString(),
            'observation' => 'Restriction hydrosodée — insuffisance rénale chronique',
        ])->assertSessionHas('success');

        $this->assertSame($hyposodee->id, $visite->fresh()->dieteEnCours()->type_diete_id);

        // ═══════════════════════════════════════════════════════
        // 4. Séances de dialyse
        // ═══════════════════════════════════════════════════════
        // La séance se programme sur un générateur, puis se clôture avec ses
        // poids d'entrée et de sortie : c'est elle qui engendre l'acte
        // facturable, pas l'inverse.
        $generateur = GenerateurDialyse::where('code', 'GEN-1')->firstOrFail();

        foreach ([3, 1] as $joursAvant) {
            $this->post(route('dialyse.planifier'), [
                'patient_id' => $this->patiente->id,
                'generateur_id' => $generateur->id,
                'date_seance' => now()->subDays($joursAvant)->setTime(8, 0)->format('Y-m-d\TH:i'),
                'duree_minutes' => 240,
                'type' => 'hemodialyse',
                'abord' => 'fistule',
            ])->assertSessionHas('success');

            $seance = SeanceDialyse::where('statut', 'planifiee')->firstOrFail();

            $this->post(route('dialyse.realiser', $seance), [
                'poids_avant_kg' => 70.5,
                'poids_apres_kg' => 68.0,
                'ta_avant_systolique' => 150, 'ta_avant_diastolique' => 85,
                'ta_apres_systolique' => 120, 'ta_apres_diastolique' => 75,
                'observations' => 'Séance de 4 h, bien tolérée. J-'.$joursAvant,
            ])->assertSessionHas('success');
        }

        $seances = ActeClinique::where('visit_id', $visite->id)->where('domaine', 'dialyse')->get();

        $this->assertCount(2, $seances);
        $this->assertTrue($seances->every(fn ($a) => $a->statut === 'realise'), 'Les séances sont réalisées.');
        // Deux kilos et demi retirés à chaque séance.
        $this->assertTrue(
            SeanceDialyse::where('statut', 'realisee')->get()
                ->every(fn ($s) => $s->ultrafiltration_ml === 2500),
            'L\'ultrafiltration se déduit du poids perdu.'
        );

        // ═══════════════════════════════════════════════════════
        // 5. Bilans biologiques de surveillance
        // ═══════════════════════════════════════════════════════
        $types = TypeExamen::where('est_actif', true)
            ->whereIn('categorie', ['biochimie', 'hematologie'])
            ->take(2)->get();
        $this->assertCount(2, $types, 'Le référentiel doit proposer des examens de laboratoire.');

        $examen = app(LaboratoireService::class)->prescrireExamens($visite, $types->pluck('id')->all(), 'labo');
        $factureExamen = app(FacturationService::class)->creerFactureExamen($examen);

        $this->assertGreaterThan(0, (float) $factureExamen->total_ttc);

        // ═══════════════════════════════════════════════════════
        // 6. Suivi infirmier pendant le séjour
        // ═══════════════════════════════════════════════════════
        $this->post(route('infirmier.pansement', $visite), [
            'realise_a' => now()->subDays(2)->format('Y-m-d H:i'),
            'localisation' => 'Point de ponction de la fistule artério-veineuse',
            'etat_plaie' => 'propre',
            'protocole' => 'Compression, antiseptique, pansement sec',
            'date_refaire' => now()->addDay()->toDateString(),
        ])->assertSessionHas('success');

        $this->post(route('infirmier.neuro', $visite), [
            'evalue_a' => now()->subDay()->format('Y-m-d H:i'),
            'ouverture_yeux' => 4, 'reponse_verbale' => 5, 'reponse_motrice' => 6,
            'pupille_droite' => 'reactive', 'pupille_gauche' => 'reactive',
        ])->assertSessionHas('success');

        $this->assertSame(15, (int) $visite->fresh()->glasgow, 'La patiente est parfaitement consciente.');

        $this->post(route('bilan-hydrique.store', $visite), [
            'jour' => now()->subDay()->toDateString(),
            'tranche' => 'matin',
            'perfusion' => 500,
            'per_os' => 400,
            'urines' => 200,
        ])->assertSessionHas('success');

        // ═══════════════════════════════════════════════════════
        // 7. Notes d'évolution : dégradée puis bonne
        // ═══════════════════════════════════════════════════════
        $this->post(route('visites.evolution', $visite), [
            'type' => 'evolution',
            'etat_general' => 'degradee',
            'note' => 'Œdèmes persistants, créatinine élevée. Poursuite du rythme de dialyse.',
        ])->assertSessionMissing('errors');

        $this->post(route('visites.evolution', $visite), [
            'type' => 'evolution',
            'etat_general' => 'bonne',
            'note' => 'Œdèmes résorbés, diurèse reprise, bilan biologique en nette amélioration.',
        ])->assertSessionMissing('errors');

        $notes = NoteEvolution::where('visit_id', $visite->id)->orderBy('created_at')->get();

        $this->assertCount(2, $notes);
        $this->assertSame('degradee', $notes->first()->etat_general);
        $this->assertSame('bonne', $notes->last()->etat_general);

        // ═══════════════════════════════════════════════════════
        // 8. La sortie est bloquée tant que tout n'est pas facturé
        // ═══════════════════════════════════════════════════════
        $this->reglerToutesLesFactures($visite);

        $this->post(route('visites.sortir', $visite), ['mode_sortie' => 'gueri'])
            ->assertSessionHas('error');

        $this->assertSame('en_cours', $visite->fresh()->statut, 'Le séjour reste ouvert.');

        // Le contrôle nomme précisément ce qui manque.
        $manquants = app(VisiteService::class)->prestationsNonFacturees($visite);

        $this->assertContains('les journées d\'hospitalisation', $manquants);
        $this->assertTrue(
            (bool) collect($manquants)->first(fn ($m) => str_contains($m, 'actes réalisés')),
            'Les deux séances de dialyse doivent être réclamées.'
        );

        // Les séances sont facturées au guichet.
        foreach ($seances as $seance) {
            $this->post(route('actes.facturer', $seance))->assertSessionMissing('errors');
        }

        $this->assertSame(
            2 * (float) config('dpi.tarifs_cdf.dialyse_seance'),
            (float) LigneFacture::whereIn('facture_id', $visite->factures()->pluck('id'))
                ->where('type', 'dialyse')
                ->sum('total_ligne'),
            'Les deux séances de dialyse sont bien réclamées.'
        );

        // ═══════════════════════════════════════════════════════
        // 9. Facture du séjour : journées + diète sur le même document
        // ═══════════════════════════════════════════════════════
        $this->post(route('visites.facturer-sejour', $visite))->assertSessionHas('success');

        $factureSejour = Facture::where('visit_id', $visite->id)
            ->whereHas('lignes', fn ($q) => $q->where('type', 'hospitalisation'))
            ->firstOrFail();

        $ligneSejour = $factureSejour->lignes->firstWhere('type', 'hospitalisation');
        $ligneDiete = $factureSejour->lignes->firstWhere('type', 'diete');

        $this->assertNotNull($ligneDiete, 'La diète doit apparaître sur la facture du séjour.');

        $tarifJour = (float) config('dpi.tarifs_cdf.hospitalisation_jour');
        $this->assertSame(5.0, (float) $ligneSejour->quantite);
        $this->assertSame(5 * $tarifJour, (float) $ligneSejour->total_ligne);

        $this->assertSame(5.0, (float) $ligneDiete->quantite, 'Cinq journées de diète servies.');
        $this->assertSame(
            5 * (float) $hyposodee->prix_journalier,
            (float) $ligneDiete->total_ligne
        );
        $this->assertStringContainsString('hyposodée', $ligneDiete->libelle);

        $this->assertSame(
            5 * $tarifJour + 5 * (float) $hyposodee->prix_journalier,
            (float) $factureSejour->total_ttc,
            'Le total du séjour additionne les journées et la diète.'
        );

        // La diète est marquée facturée et clôturée : elle ne repassera pas.
        $prescription = PrescriptionDiete::where('visit_id', $visite->id)->firstOrFail();

        $this->assertTrue($prescription->estFacturee());
        $this->assertSame($factureSejour->id, $prescription->facture_id);
        $this->assertSame(5, $prescription->jours_factures);
        $this->assertNotNull($prescription->fin, 'La diète en cours est clôturée à la facturation.');

        // ═══════════════════════════════════════════════════════
        // 10. Règlement intégral et sortie guérie
        // ═══════════════════════════════════════════════════════
        $this->reglerToutesLesFactures($visite);

        $visiteService = app(VisiteService::class);

        $this->assertSame(0, $visiteService->facturesImpayees($visite));
        $this->assertSame([], $visiteService->prestationsNonFacturees($visite));

        $this->post(route('visites.sortir', $visite), ['mode_sortie' => 'gueri'])
            ->assertSessionHas('success');

        $visite->refresh();

        $this->assertSame('termine', $visite->statut);
        $this->assertSame('gueri', $visite->mode_sortie);
        $this->assertNotNull($visite->date_sortie);
        $this->assertNull($visite->lit_id, 'Le lit est rendu au service.');
        $this->assertSame('libre', $lit->fresh()->statut);
        $this->assertFalse($visite->peutRecevoirServices(), 'Le dossier est clos.');

        // Toutes les factures du séjour sont soldées, aucune ligne oubliée.
        $this->assertSame(
            0,
            $visite->factures()->where('statut', '!=', 'payee')->count(),
            'La patiente sort sans dette.'
        );

        $totalRegle = (float) $visite->factures()->sum('total_ttc');
        $lignes = LigneFacture::whereIn('facture_id', $visite->factures()->pluck('id'))->get();

        $this->assertSame($totalRegle, (float) $lignes->sum('total_ligne'));
        $this->assertEqualsCanonicalizing(
            ['consultation', 'examen_labo', 'dialyse', 'hospitalisation', 'diete'],
            $lignes->pluck('type')->unique()->values()->all(),
            'La note finale couvre la consultation, les examens, les séances, le séjour et la diète.'
        );
    }

    public function test_la_diete_deja_facturee_nest_pas_refacturee(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('code', 'DIAL')->firstOrFail()->id,
            'motif_consultation' => 'Insuffisance rénale chronique',
        ]);

        $hyposodee = TypeDiete::where('code', 'DHS')->firstOrFail();

        PrescriptionDiete::create([
            'visit_id' => $visite->id,
            'type_diete_id' => $hyposodee->id,
            'user_id' => $this->medecin->id,
            'debut' => now()->subDays(2)->toDateString(),
        ]);

        $premiere = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $seconde = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $this->assertNotNull($premiere->lignes->firstWhere('type', 'diete'));
        $this->assertNull(
            $seconde,
            'Séjour et diète déjà facturés : aucune seconde facture n\'est émise.'
        );
        $this->assertSame(1, Facture::where('visit_id', $visite->id)->count());
    }

    public function test_seules_les_journees_ecoulees_depuis_la_derniere_facture_sont_reclamees(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('code', 'DIAL')->firstOrFail()->id,
            'motif_consultation' => 'Insuffisance rénale chronique',
        ]);

        $tarif = (float) config('dpi.tarifs_cdf.hospitalisation_jour');

        // Facture intermédiaire à J3.
        $premiere = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $this->assertSame(3.0, (float) $premiere->lignes->firstWhere('type', 'hospitalisation')->quantite);
        $this->assertSame(3, (int) $visite->fresh()->jours_factures);

        // Réémettre le même jour ne réclame rien de plus.
        $this->assertNull(app(FacturationService::class)->creerFactureHospitalisation($visite->fresh()));

        // Deux jours plus tard, seules les deux journées nouvelles le sont.
        $visite->update(['date_entree' => now()->subDays(4)]);
        $seconde = app(FacturationService::class)->creerFactureHospitalisation($visite->fresh());

        $ligne = $seconde->lignes->firstWhere('type', 'hospitalisation');
        $this->assertSame(2.0, (float) $ligne->quantite);
        $this->assertSame(2 * $tarif, (float) $ligne->total_ligne);
        $this->assertStringContainsString('du J4 au J5', $ligne->libelle);

        // Au total, cinq journées facturées une seule fois chacune.
        $this->assertSame(
            5 * $tarif,
            (float) LigneFacture::whereIn('facture_id', $visite->factures()->pluck('id'))
                ->where('type', 'hospitalisation')->sum('total_ligne')
        );
    }

    public function test_un_acte_deja_facture_ne_genere_pas_une_seconde_facture(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'service_id' => Service::where('code', 'DIAL')->firstOrFail()->id,
            'motif_consultation' => 'Séance de dialyse',
        ]);

        $seance = ActeClinique::create([
            'visit_id' => $visite->id,
            'patient_id' => $this->patiente->id,
            'prescripteur_id' => $this->medecin->id,
            'domaine' => 'dialyse',
            'libelle' => 'Séance d\'hémodialyse (4 h)',
            'prix' => config('dpi.tarifs_cdf.dialyse_seance'),
            'statut' => 'realise',
            'date_realisation' => now(),
        ]);

        $service = app(FacturationService::class);
        $premiere = $service->creerFactureActeClinique($seance);
        $seconde = $service->creerFactureActeClinique($seance->fresh());

        $this->assertSame($premiere->id, $seconde->id, 'La même facture est renvoyée.');
        $this->assertSame(1, Facture::where('visit_id', $visite->id)->count());
    }

    public function test_une_consultation_deja_facturee_ne_genere_pas_une_seconde_facture(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'consultation_externe',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => 'Contrôle',
        ]);

        $service = app(FacturationService::class);
        $premiere = $service->creerFactureConsultation($visite);
        $seconde = $service->creerFactureConsultation($visite->fresh());

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, Facture::where('visit_id', $visite->id)->count());
    }

    public function test_une_mise_a_jeun_nalourdit_pas_la_facture(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'service_id' => Service::where('code', 'DIAL')->firstOrFail()->id,
            'motif_consultation' => 'Bilan pré-dialyse',
        ]);

        PrescriptionDiete::create([
            'visit_id' => $visite->id,
            'type_diete_id' => TypeDiete::where('code', 'DABS')->firstOrFail()->id,
            'user_id' => $this->medecin->id,
            'debut' => now()->subDay()->toDateString(),
        ]);

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $this->assertNull($facture->lignes->firstWhere('type', 'diete'), 'Le jeûne ne coûte rien.');
        $this->assertCount(1, $facture->lignes);
    }

    public function test_une_seance_de_dialyse_non_facturee_bloque_la_sortie(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'service_id' => Service::where('code', 'DIAL')->firstOrFail()->id,
            'motif_consultation' => 'Insuffisance rénale chronique',
        ]);

        $seance = ActeClinique::create([
            'visit_id' => $visite->id,
            'patient_id' => $this->patiente->id,
            'prescripteur_id' => $this->medecin->id,
            'domaine' => 'dialyse',
            'libelle' => 'Séance d\'hémodialyse (4 h)',
            'prix' => config('dpi.tarifs_cdf.dialyse_seance'),
            'statut' => 'realise',
            'date_realisation' => now(),
        ]);

        // Le séjour est facturé et soldé : sans le contrôle des actes, la
        // patiente sortirait sans avoir payé sa séance.
        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $this->post(route('caisse.encaisser', $facture), [
            'montant' => (float) $facture->patient_part,
            'devise' => 'CDF',
            'mode_paiement' => 'especes',
        ]);

        $visiteService = app(VisiteService::class);

        $this->assertSame(0, $visiteService->facturesImpayees($visite));
        $this->assertContains(
            'les actes réalisés (Séance d\'hémodialyse (4 h))',
            $visiteService->prestationsNonFacturees($visite)
        );

        $this->post(route('visites.sortir', $visite), ['mode_sortie' => 'gueri'])
            ->assertSessionHas('error');

        $this->assertSame('en_cours', $visite->fresh()->statut);

        // Une fois la séance facturée et réglée, la sortie passe.
        $factureSeance = app(FacturationService::class)->creerFactureActeClinique($seance);
        $this->post(route('caisse.encaisser', $factureSeance), [
            'montant' => (float) $factureSeance->patient_part,
            'devise' => 'CDF',
            'mode_paiement' => 'especes',
        ]);

        $this->assertSame([], $visiteService->prestationsNonFacturees($visite));

        $this->post(route('visites.sortir', $visite), ['mode_sortie' => 'gueri'])
            ->assertSessionHas('success');

        $this->assertSame('termine', $visite->fresh()->statut);
    }

    public function test_un_examen_non_facture_bloque_la_sortie(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'service_id' => Service::where('code', 'DIAL')->firstOrFail()->id,
            'motif_consultation' => 'Bilan rénal',
        ]);

        $type = TypeExamen::where('est_actif', true)->firstOrFail();
        app(LaboratoireService::class)->prescrireExamens($visite, [$type->id], 'labo');

        app(FacturationService::class)->creerFactureHospitalisation($visite);

        $manquants = app(VisiteService::class)->prestationsNonFacturees($visite);

        $this->assertCount(1, $manquants);
        $this->assertStringContainsString('examen(s) de laboratoire', $manquants[0]);
    }

    public function test_la_diete_facturee_reste_visible_le_jour_meme(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('code', 'DIAL')->firstOrFail()->id,
            'motif_consultation' => 'Insuffisance rénale chronique',
        ]);

        PrescriptionDiete::create([
            'visit_id' => $visite->id,
            'type_diete_id' => TypeDiete::where('code', 'DHS')->firstOrFail()->id,
            'user_id' => $this->medecin->id,
            'debut' => now()->subDays(2)->toDateString(),
        ]);

        app(FacturationService::class)->creerFactureHospitalisation($visite);

        // La facturation clôture la prescription ; la cuisine doit malgré
        // tout voir ce qu'elle sert aujourd'hui.
        $this->get(route('diete.index'))
            ->assertOk()
            ->assertSee('hyposodée', false)
            ->assertSee('Portée sur la facture du séjour', false);

        $this->get(route('diete.imprimer'))
            ->assertOk()
            ->assertSee('hyposodée', false);
    }

    public function test_le_service_de_dialyse_est_installe_avec_ses_postes(): void
    {
        $dialyse = Service::where('code', 'DIAL')->firstOrFail();

        $this->assertSame('dialyse', $dialyse->type);
        $this->assertGreaterThanOrEqual(8, Lit::where('service_id', $dialyse->id)->count());

        $this->get(route('dialyse.index'))
            ->assertOk()
            ->assertSee('Dialyse');
    }

    public function test_le_catalogue_de_dialyse_est_propose_a_la_prescription(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'consultation_externe',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => 'Néphrologie',
        ]);

        $this->get(route('dialyse.create', ['visit_id' => $visite->id]))
            ->assertOk()
            ->assertSee('hémodialyse')
            ->assertSee('Dialyse péritonéale')
            ->assertSee('fistule');
    }
}
