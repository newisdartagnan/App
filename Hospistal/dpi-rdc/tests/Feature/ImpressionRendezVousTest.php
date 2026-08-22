<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le rendez-vous sur papier, remis au patient.
 *
 * La page existait déjà mais rien ne menait vraiment jusqu'à elle : un lien
 * de onze pixels au fond de l'agenda du jour, et rien du tout ailleurs.
 * Après avoir fixé un rendez-vous, l'écran disait « Rendez-vous fixé. » et
 * s'arrêtait là — or c'est à cette seconde-là que le patient est devant le
 * guichet et attend son papier. Quand il revenait sans, il fallait retrouver
 * le bon jour dans l'agenda.
 */
class ImpressionRendezVousTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $patient;

    protected User $medecin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        // L'agenda n'affiche que les prestataires : un rendez-vous se prend
        // avec un médecin, pas avec le compte d'administration.
        $this->medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'KALALA', 'prenom' => 'Pierre', 'matricule' => 'MED-RDV-1',
            'password' => 'motdepasse123', 'is_active' => true,
        ]);
        $this->medecin->assignRole('medecin');

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'RDV-0001',
            'nom' => 'MBUYI', 'prenom' => 'Thérèse', 'sexe' => 'F',
            'date_naissance' => now()->subYears(34)->toDateString(),
            'telephone' => '0815551234',
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function rendezVous(array $remplacements = []): RendezVous
    {
        return RendezVous::create(array_merge([
            'establishment_id' => $this->etab->id,
            'patient_id' => $this->patient->id,
            'prestataire_id' => $this->medecin->id,
            'cree_par' => $this->admin->id,
            'debut' => now()->addDays(7)->setTime(9, 30),
            'duree_minutes' => 30,
            'statut' => 'fixe',
            'contact' => '0815551234',
        ], $remplacements));
    }

    // ═══════════════════════════════════════════════════════════
    // Au guichet, le patient est encore là
    // ═══════════════════════════════════════════════════════════

    public function test_fixer_un_rendez_vous_propose_aussitot_de_limprimer(): void
    {
        $reponse = $this->post(route('agenda.store'), [
            'dossier_number' => 'RDV-0001',
            'prestataire_id' => $this->medecin->id,
            'debut' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'duree_minutes' => 30,
        ]);

        $reponse->assertRedirect();
        $reponse->assertSessionHas('imprimer');

        // Le lien mène bien au papier de CE rendez-vous.
        $rdv = RendezVous::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame(route('agenda.convocation', $rdv), session('imprimer'));
    }

    public function test_le_message_nomme_le_patient_et_la_date(): void
    {
        // Trois guichets, trois rendez-vous par minute : « Rendez-vous fixé »
        // tout court ne dit pas lequel vient d'être pris.
        $this->post(route('agenda.store'), [
            'dossier_number' => 'RDV-0001',
            'prestataire_id' => $this->medecin->id,
            'debut' => now()->addDays(4)->setTime(14, 15)->format('Y-m-d\TH:i'),
            'duree_minutes' => 30,
        ]);

        $message = session('success');
        $this->assertStringContainsString('MBUYI', $message);
        $this->assertStringContainsString(now()->addDays(4)->format('d/m/Y'), $message);
        $this->assertStringContainsString('14:15', $message);
    }

    public function test_le_bouton_dimpression_saffiche_dans_le_message(): void
    {
        $this->post(route('agenda.store'), [
            'dossier_number' => 'RDV-0001',
            'prestataire_id' => $this->medecin->id,
            'debut' => now()->addDays(5)->setTime(8, 0)->format('Y-m-d\TH:i'),
            'duree_minutes' => 30,
        ]);

        $this->followingRedirects()
            ->from(route('agenda.index'))
            ->post(route('agenda.store'), [
                'dossier_number' => 'RDV-0001',
                'prestataire_id' => $this->medecin->id,
                'debut' => now()->addDays(6)->setTime(8, 0)->format('Y-m-d\TH:i'),
                'duree_minutes' => 30,
            ])
            ->assertOk()
            ->assertSee('Imprimer le rendez-vous à remettre au patient');
    }

    public function test_un_creneau_refuse_ne_propose_rien_a_imprimer(): void
    {
        $this->rendezVous(['debut' => now()->addDays(8)->setTime(11, 0)]);

        $this->post(route('agenda.store'), [
            'dossier_number' => 'RDV-0001',
            'prestataire_id' => $this->medecin->id,
            'debut' => now()->addDays(8)->setTime(11, 0)->format('Y-m-d\TH:i'),
            'duree_minutes' => 30,
        ])->assertSessionMissing('imprimer');
    }

    // ═══════════════════════════════════════════════════════════
    // Le patient revient sans son papier
    // ═══════════════════════════════════════════════════════════

    public function test_la_fiche_du_patient_montre_ses_rendez_vous_a_venir(): void
    {
        $rdv = $this->rendezVous();

        $this->get(route('patients.show', $this->patient))
            ->assertOk()
            ->assertSee('Rendez-vous à venir')
            ->assertSee($rdv->debut->format('d/m/Y'))
            ->assertSee(route('agenda.convocation', $rdv), false)
            ->assertSee('Imprimer le rendez-vous');
    }

    public function test_un_rendez_vous_passe_nencombre_pas_la_fiche(): void
    {
        // Ce qui est fait est fait : la fiche montre ce qui vient.
        $this->rendezVous(['debut' => now()->subDays(10), 'statut' => 'honore']);

        $this->get(route('patients.show', $this->patient))
            ->assertOk()
            ->assertDontSee('Rendez-vous à venir');
    }

    public function test_un_rendez_vous_annule_ne_sy_trouve_plus(): void
    {
        $this->rendezVous(['statut' => 'annule']);

        $this->get(route('patients.show', $this->patient))
            ->assertOk()
            ->assertDontSee('Rendez-vous à venir');
    }

    public function test_les_rendez_vous_se_presentent_dans_lordre(): void
    {
        $tard = $this->rendezVous(['debut' => now()->addDays(20)->setTime(9, 0)]);
        $tot = $this->rendezVous(['debut' => now()->addDays(2)->setTime(9, 0)]);

        $liste = $this->get(route('patients.show', $this->patient))->viewData('rendezVous');

        $this->assertSame($tot->id, $liste->first()->id);
        $this->assertSame($tard->id, $liste->last()->id);
    }

    // ═══════════════════════════════════════════════════════════
    // L'agenda du jour
    // ═══════════════════════════════════════════════════════════

    public function test_lagenda_porte_un_vrai_bouton_dimpression(): void
    {
        $rdv = $this->rendezVous(['debut' => now()->addDay()->setTime(9, 0)]);

        // Un lien de onze pixels au fond d'une colonne chargée ne se voit pas
        // depuis l'autre bout du guichet.
        $this->get(route('agenda.index', ['jour' => $rdv->debut->toDateString()]))
            ->assertOk()
            ->assertSee(route('agenda.convocation', $rdv), false)
            ->assertSee('🖨️ Imprimer');
    }

    // ═══════════════════════════════════════════════════════════
    // Le papier lui-même
    // ═══════════════════════════════════════════════════════════

    public function test_le_papier_porte_un_bouton_imprimer(): void
    {
        // Rien n'invitait à imprimer : il fallait connaître Ctrl+P.
        $this->get(route('agenda.convocation', $this->rendezVous()))
            ->assertOk()
            ->assertSee('data-imprimer', false)
            ->assertSee('🖨️ Imprimer');
    }

    public function test_le_papier_dit_lessentiel_au_patient(): void
    {
        $rdv = $this->rendezVous();

        $this->get(route('agenda.convocation', $rdv))
            ->assertOk()
            ->assertSee('MBUYI')
            ->assertSee('RDV-0001')
            ->assertSee($rdv->debut->format('H:i'))
            ->assertSee('RENDEZ-VOUS')
            ->assertSee('À apporter');
    }

    public function test_le_papier_nomme_lhopital_qui_le_delivre(): void
    {
        // Six des huit documents imprimés ne nommaient l'hôpital nulle part,
        // pas même l'ordonnance. Un papier qui finit chez un pharmacien ou un
        // confrère doit dire d'où il vient et à quel numéro rappeler.
        $this->etab->update(['telephone' => '0812345678', 'ville' => 'Kinshasa']);

        $this->get(route('agenda.convocation', $this->rendezVous()))
            ->assertOk()
            ->assertSee(mb_strtoupper($this->etab->name))
            ->assertSee('0812345678')
            ->assertSee('Kinshasa');
    }

    public function test_tous_les_documents_remis_portent_len_tete_de_lhopital(): void
    {
        // Le bandeau est commun : l'ordonnance, le bon d'examen, la fiche de
        // maternité et la feuille de bloc en profitent du même coup.
        $bandeau = file_get_contents(resource_path('views/partials/bandeau-patient-impression.blade.php'));

        $this->assertStringContainsString('establishment', $bandeau);
        $this->assertStringContainsString('telephone', $bandeau);

        foreach (['labo/bon', 'pharmacie/ordonnance', 'maternite/fiche', 'bloc/feuille'] as $vue) {
            $this->assertStringContainsString(
                'bandeau-patient-impression',
                file_get_contents(resource_path('views/'.$vue.'.blade.php')),
                "La vue {$vue} ne porte pas l'en-tête de l'établissement."
            );
        }
    }

    public function test_le_papier_ne_depend_daucun_script_pour_etre_lisible(): void
    {
        // Le bouton est un confort ; le document doit rester imprimable par
        // le menu du navigateur sur un poste qui bloque les scripts.
        $this->get(route('agenda.convocation', $this->rendezVous()))
            ->assertOk()
            ->assertDontSee('onclick=', false)
            ->assertDontSee('wire:', false);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que l'imprimante reçoit
    // ═══════════════════════════════════════════════════════════

    public function test_le_menu_et_len_tete_ne_partent_pas_sur_le_papier(): void
    {
        $page = $this->get(route('agenda.convocation', $this->rendezVous()))->getContent();

        // Sans cela, le bandeau bleu, les dix-huit onglets et le bouton
        // « Se déconnecter » occupaient le tiers de la première feuille.
        $this->assertMatchesRegularExpression(
            '/<header[^>]*class="[^"]*dpi-sans-impression/',
            $page,
            'L\'en-tête bleu part encore à l\'impression.'
        );
        $this->assertStringContainsString('dpi-nav', $page);
    }

    public function test_la_feuille_de_style_cache_bien_ces_elements(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match('/@media print \{.*?\n\}/s', $css, $trouve);
        $this->assertNotEmpty($trouve, 'Aucune règle d\'impression dans la feuille de style.');

        foreach (['.dpi-sans-impression', '.dpi-nav', '#offline-banner'] as $selecteur) {
            $this->assertStringContainsString($selecteur, $trouve[0]);
        }
    }

    public function test_les_autres_documents_remis_en_main_ont_aussi_leur_bouton(): void
    {
        // Le bulletin de sortie souffrait du même manque : on le remet au
        // patient, et rien n'invitait à l'imprimer.
        foreach (['visites/bulletin', 'snis/imprimable'] as $vue) {
            $this->assertStringContainsString(
                'data-imprimer',
                file_get_contents(resource_path('views/'.$vue.'.blade.php')),
                "La vue {$vue} n'a pas de bouton d'impression."
            );
        }
    }
}
