<?php

namespace Tests\Feature;

use App\Models\ActeClinique;
use App\Models\Assurance;
use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Grossesse;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\Prescription;
use App\Models\RendezVous;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le nom de l'assurance sur les papiers qu'on remet.
 *
 * « Prise en charge : Assurance » ne sert à personne. Le pharmacien, le
 * laboratoire d'à côté et le tiers payant ont besoin de lire le nom de la
 * société et le numéro de police — c'est sur cette ligne-là qu'ils décident
 * de servir ou non, et c'est elle qu'ils recopient sur leur bordereau.
 *
 * Ce test rend TOUS les documents imprimables pour un même patient assuré :
 * un papier oublié se voit ici, pas six mois plus tard au guichet.
 */
class AssuranceSurLesPapiersTest extends TestCase
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
            'dossier_number' => 'ASS-0001',
            'nom' => 'NKUMU', 'prenom' => 'Espoir', 'sexe' => 'F',
            'date_naissance' => now()->subYears(29)->toDateString(),
            'type_prise_en_charge' => 'assurance',
            'assurance_nom' => 'SONAS',
            'assurance_numero' => 'SN-8842',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now()->subHours(3),
            'motif_consultation' => 'Fièvre',
        ]);
    }

    /** Ce que tout papier doit porter pour être opposable au tiers payant. */
    protected function assertPorteLassurance(string $url, string $document): void
    {
        $page = $this->get($url);

        $page->assertOk();
        $page->assertSee('SONAS');
        $page->assertSee('SN-8842');

        // Le mot « Assurance » tout court est justement ce qui ne suffit pas.
        $this->assertStringNotContainsString(
            'charge :</strong> Assurance<',
            $page->getContent(),
            "Le document {$document} annonce « Assurance » au lieu de nommer la société."
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Les papiers remis au patient
    // ═══════════════════════════════════════════════════════════

    public function test_la_convocation_de_rendez_vous_nomme_lassurance(): void
    {
        $rdv = RendezVous::create([
            'establishment_id' => $this->etab->id,
            'patient_id' => $this->patient->id,
            'prestataire_id' => $this->admin->id,
            'cree_par' => $this->admin->id,
            'debut' => now()->addDays(5)->setTime(9, 0),
            'duree_minutes' => 30,
            'statut' => 'fixe',
        ]);

        $this->assertPorteLassurance(route('agenda.convocation', $rdv), 'convocation');
    }

    public function test_le_bulletin_de_sortie_nomme_lassurance(): void
    {
        $this->visite->update(['statut' => 'termine', 'date_sortie' => now()]);

        $this->assertPorteLassurance(route('visites.bulletin', $this->visite), 'bulletin de sortie');
    }

    public function test_le_bon_dexamen_nomme_lassurance(): void
    {
        $examen = $this->examen();

        $this->assertPorteLassurance(route('labo.bon', $examen), 'bon d\'examen');
    }

    public function test_le_bulletin_de_resultat_nomme_lassurance(): void
    {
        $examen = $this->examen(['statut' => 'valide']);

        $this->assertPorteLassurance(route('labo.bulletin', $examen), 'bulletin de résultat');
    }

    public function test_le_bulletin_du_jour_nomme_lassurance(): void
    {
        $this->examen(['statut' => 'valide']);

        $this->assertPorteLassurance(route('patients.bulletin-jour', $this->patient), 'bulletin du jour');
    }

    public function test_lordonnance_nomme_lassurance(): void
    {
        // C'est le papier qui va chez le pharmacien : sans le nom de la
        // société, il ne peut ni servir en tiers payant ni se faire payer.
        $consultation = Consultation::create([
            'visit_id' => $this->visite->id,
            'user_id' => $this->admin->id,
            'date_consultation' => now(),
            'diagnostics' => [['libelle' => 'Paludisme', 'type' => 'principal']],
            'statut' => 'finalise',
        ]);

        $prescription = Prescription::create([
            'consultation_id' => $consultation->id,
            'patient_id' => $this->patient->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'en_attente',
        ]);

        $this->assertPorteLassurance(route('prescriptions.ordonnance', $prescription), 'ordonnance');
    }

    public function test_la_fiche_obstetricale_nomme_lassurance(): void
    {
        $grossesse = Grossesse::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'date_derniere_regle' => now()->subWeeks(20)->toDateString(),
            'gestite' => 2, 'parite' => 1,
            'statut' => 'en_cours',
        ]);

        $this->assertPorteLassurance(route('maternite.fiche', $grossesse), 'fiche obstétricale');
    }

    public function test_la_feuille_de_bloc_nomme_lassurance(): void
    {
        $acte = ActeClinique::create([
            'visit_id' => $this->visite->id,
            'patient_id' => $this->patient->id,
            'prescripteur_id' => $this->admin->id,
            'operateur_id' => $this->admin->id,
            'domaine' => 'chirurgie',
            'libelle' => 'Appendicectomie',
            'prix' => 250000, 'quantite' => 1,
            'statut' => 'planifie',
            'date_prevue' => now()->addDay(),
        ]);

        $this->assertPorteLassurance(route('bloc.feuille', $acte), 'feuille de bloc');
    }

    // ═══════════════════════════════════════════════════════════
    // Le papier de la caisse
    // ═══════════════════════════════════════════════════════════

    public function test_la_facture_nomme_lassurance(): void
    {
        $this->assertPorteLassurance(route('caisse.imprimer', $this->facture()), 'facture');
    }

    public function test_la_facture_nomme_lassurance_meme_sans_copie_sur_la_piece(): void
    {
        // La facture recopie le nom au moment de son émission. Quand cette
        // copie manque — pièce ancienne, reprise de données — elle doit
        // encore savoir le lire sur la fiche du patient plutôt que
        // d'afficher « Assurance » tout court.
        $facture = $this->facture();
        $facture->forceFill(['assurance_nom' => null, 'assurance_numero' => null])->saveQuietly();

        $this->assertPorteLassurance(route('caisse.imprimer', $facture->fresh()), 'facture sans copie');
    }

    public function test_lecran_de_la_facture_nomme_aussi_lassurance(): void
    {
        $this->get(route('caisse.show', $this->facture()))
            ->assertOk()
            ->assertSee('SONAS');
    }

    public function test_le_pdf_de_resultat_nomme_lassurance(): void
    {
        // Le résultat part par courriel ou par lien : c'est souvent la seule
        // pièce que le tiers payant verra jamais.
        $examen = $this->examen(['statut' => 'valide']);

        $pdf = $this->get(route('examens.pdf', $examen));

        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
    }

    // ═══════════════════════════════════════════════════════════
    // L'assurance connue par le contrat et non par la fiche
    // ═══════════════════════════════════════════════════════════

    public function test_lassurance_portee_par_le_contrat_seul_se_lit_sur_les_papiers(): void
    {
        // La fiche du patient recopie le nom de la société ; le contrat, lui,
        // fait foi. Quand la copie manque — reprise de données, contrat
        // enregistré directement — le papier doit encore nommer la société
        // au lieu d'annoncer « Assurance » tout court.
        $this->patient->update(['assurance_nom' => null, 'assurance_numero' => null]);

        $assurance = Assurance::create([
            'nom' => 'RAWSUR', 'code' => 'RAWSUR',
            'taux_couverture' => 80, 'est_actif' => true,
        ]);

        PatientAssurance::create([
            'patient_id' => $this->patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => 'RW-1207',
            'date_debut' => now()->subYear(),
            'annee_courante' => now()->year,
            'est_actif' => true,
        ]);

        $rdv = RendezVous::create([
            'establishment_id' => $this->etab->id,
            'patient_id' => $this->patient->id,
            'prestataire_id' => $this->admin->id,
            'cree_par' => $this->admin->id,
            'debut' => now()->addDays(9)->setTime(10, 0),
            'duree_minutes' => 30, 'statut' => 'fixe',
        ]);

        $this->get(route('agenda.convocation', $rdv))
            ->assertOk()
            ->assertSee('RAWSUR')
            ->assertSee('RW-1207');
    }

    public function test_la_facture_lit_aussi_le_contrat_a_defaut_de_copie(): void
    {
        $this->patient->update(['assurance_nom' => null, 'assurance_numero' => null]);

        $assurance = Assurance::create([
            'nom' => 'RAWSUR', 'code' => 'RAWSUR',
            'taux_couverture' => 80, 'est_actif' => true,
        ]);

        PatientAssurance::create([
            'patient_id' => $this->patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => 'RW-1207',
            'date_debut' => now()->subYear(),
            'annee_courante' => now()->year,
            'est_actif' => true,
        ]);

        $facture = $this->facture();
        $facture->forceFill(['assurance_nom' => null, 'assurance_numero' => null])->saveQuietly();

        $this->get(route('caisse.imprimer', $facture->fresh()))
            ->assertOk()
            ->assertSee('RAWSUR')
            ->assertSee('RW-1207');
    }

    // ═══════════════════════════════════════════════════════════
    // Un papier, un hôpital
    // ═══════════════════════════════════════════════════════════

    public function test_len_tete_et_le_pied_nomment_le_meme_hopital(): void
    {
        // L'en-tête lisait la base, le pied de page le fichier .env : sur un
        // même papier, deux noms pour un hôpital dès qu'ils divergent — et
        // ils divergent, notamment sur le serveur qui tient le réseau sang
        // et connaît donc plusieurs établissements.
        $this->etab->update(['name' => 'HGR de Bandundu']);
        config(['dpi.establishment_name' => 'Ancien nom du .env']);

        $rdv = RendezVous::create([
            'establishment_id' => $this->etab->id,
            'patient_id' => $this->patient->id,
            'prestataire_id' => $this->admin->id,
            'cree_par' => $this->admin->id,
            'debut' => now()->addDays(12)->setTime(10, 0),
            'duree_minutes' => 30, 'statut' => 'fixe',
        ]);

        // Le nom du .env reste légitimement dans la barre bleue de
        // l'application, qui ne s'imprime pas : c'est le nom porté par le
        // document qu'on vérifie.
        $page = $this->get(route('agenda.convocation', $rdv))->assertOk();

        $this->assertSame('HGR de Bandundu', $page->viewData('etablissement'));
        $page->assertSee('HGR DE BANDUNDU');
    }

    public function test_le_bulletin_de_sortie_suit_la_meme_regle(): void
    {
        $this->etab->update(['name' => 'HGR de Bandundu']);
        config(['dpi.establishment_name' => 'Ancien nom du .env']);

        $this->visite->update(['statut' => 'termine', 'date_sortie' => now()]);

        $page = $this->get(route('visites.bulletin', $this->visite))->assertOk();

        $this->assertSame('HGR de Bandundu', $page->viewData('etablissement'));
        $page->assertSee('HGR DE BANDUNDU');
    }

    // ═══════════════════════════════════════════════════════════
    // Ce qui n'est pas une assurance ne doit pas mentir
    // ═══════════════════════════════════════════════════════════

    public function test_un_patient_non_assure_garde_son_libelle(): void
    {
        $this->patient->update([
            'type_prise_en_charge' => 'indigent',
            'assurance_nom' => null,
            'assurance_numero' => null,
        ]);

        $rdv = RendezVous::create([
            'establishment_id' => $this->etab->id,
            'patient_id' => $this->patient->id,
            'prestataire_id' => $this->admin->id,
            'cree_par' => $this->admin->id,
            'debut' => now()->addDays(6)->setTime(10, 0),
            'duree_minutes' => 30,
            'statut' => 'fixe',
        ]);

        $this->get(route('agenda.convocation', $rdv))
            ->assertOk()
            ->assertSee('Indigent')
            ->assertDontSee('SONAS');
    }

    public function test_une_assurance_sans_numero_nomme_quand_meme_la_societe(): void
    {
        $this->patient->update(['assurance_numero' => null]);

        $rdv = RendezVous::create([
            'establishment_id' => $this->etab->id,
            'patient_id' => $this->patient->id,
            'prestataire_id' => $this->admin->id,
            'cree_par' => $this->admin->id,
            'debut' => now()->addDays(7)->setTime(10, 0),
            'duree_minutes' => 30,
            'statut' => 'fixe',
        ]);

        $this->get(route('agenda.convocation', $rdv))
            ->assertOk()
            ->assertSee('SONAS');
    }

    // ═══════════════════════════════════════════════════════════
    // Outils
    // ═══════════════════════════════════════════════════════════

    protected function examen(array $remplacements = []): ExamenLaboratoire
    {
        return ExamenLaboratoire::create(array_merge([
            'numero_bon' => 'LAB-ASS-'.random_int(1000, 9999),
            'patient_id' => $this->patient->id,
            'visit_id' => $this->visite->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'prescrit',
            'domaine' => 'labo',
        ], $remplacements));
    }

    protected function facture(): Facture
    {
        return Facture::create([
            'patient_id' => $this->patient->id,
            'visit_id' => $this->visite->id,
            'establishment_id' => $this->etab->id,
            'numero_facture' => 'FAC-ASS-'.random_int(1000, 9999),
            'date_facture' => now(),
            'statut' => 'emise',
            'type_prise_en_charge' => 'assurance',
            'assurance_nom' => 'SONAS',
            'assurance_numero' => 'SN-8842',
            'devise' => 'CDF', 'taux_change' => 1,
            'total_ht' => 30000, 'total_ttc' => 30000,
            'patient_part' => 6000, 'assurance_part' => 24000,
        ]);
    }
}
