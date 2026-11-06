<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\StatutConsultation;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\SignalConsultation;
use App\Models\User;
use App\Services\Teleconsultation\AccesConsultation;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** TLC-01 : signalisation de l'appel, relayée seulement entre le patient et son médecin. */
class SignalisationTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private const SDP = "v=0\r\no=- 46117317 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n";

    private User $patient;

    private Medecin $medecin;

    private RendezVous $rdv;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('08:55');
        $this->patient = $this->patient();
        $this->medecin = $this->medecinActive();
        $this->rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:00'));
        [$this->consultation] = app(AccesConsultation::class)->entrer($this->patient, $this->rdv);
    }

    private function envoiPatient(string $type, mixed $charge = [], ?string $cle = null, ?RendezVous $rdv = null): TestResponse
    {
        return $this->actingAs($this->patient)->postJson(route('consultation.signaux.envoyer', $rdv ?? $this->rdv), ['type' => $type, 'charge' => $charge, 'cle' => $cle]);
    }

    private function envoiMedecin(string $type, mixed $charge = [], ?string $cle = null): TestResponse
    {
        return $this->commePersonnel($this->medecin->user)
            ->postJson(route('personnel.medecin.consultation.signaux.envoyer', $this->rdv), ['type' => $type, 'charge' => $charge, 'cle' => $cle]);
    }

    private function lecturePatient(int $apres = 0, ?RendezVous $rdv = null): TestResponse
    {
        return $this->actingAs($this->patient)->getJson(route('consultation.signaux', [$rdv ?? $this->rdv, 'apres' => $apres]));
    }

    private function lectureMedecin(int $apres = 0): TestResponse
    {
        return $this->commePersonnel($this->medecin->user)->getJson(route('personnel.medecin.consultation.signaux', [$this->rdv, 'apres' => $apres]));
    }

    private function offre(): array
    {
        return ['type' => 'offer', 'sdp' => self::SDP];
    }

    public function test_le_medecin_offre_le_patient_lit_et_repond(): void
    {
        $this->envoiMedecin('offre', $this->offre())->assertCreated();
        $this->envoiMedecin('candidat', ['candidate' => 'candidate:1 1 udp 2122260223 192.168.1.10 54321 typ host', 'sdpMid' => '0', 'sdpMLineIndex' => 0])->assertCreated();

        $lu = $this->lecturePatient()->assertOk()->json();
        $this->assertSame('patient', $lu['role']);
        $this->assertSame('en_attente', $lu['statut']);
        $this->assertSame(['offre', 'candidat'], array_column($lu['signaux'], 'type'));
        $this->assertEquals($this->offre(), $lu['signaux'][0]['charge']);
        $this->assertNull($lu['signaux'][1]['charge']['usernameFragment']);

        $this->envoiPatient('reponse', ['type' => 'answer', 'sdp' => self::SDP])->assertCreated();
        $this->assertSame(['reponse'], array_column($this->lectureMedecin()->json('signaux'), 'type'));
        // Chacun ne relit pas ce qu'il a envoyé.
        $this->assertSame(['offre', 'candidat'], array_column($this->lecturePatient()->json('signaux'), 'type'));
    }

    public function test_la_lecture_reprend_apres_le_dernier_signal_lu(): void
    {
        $premier = $this->envoiMedecin('offre', $this->offre())->json('id');
        $this->envoiMedecin('raccroche')->assertCreated();

        $this->assertSame(['raccroche'], array_column($this->lecturePatient($premier)->json('signaux'), 'type'));
        $this->assertSame([], $this->lecturePatient(PHP_INT_MAX)->json('signaux'));
    }

    public function test_pret_et_audio_seul_vont_au_medecin(): void
    {
        $this->envoiPatient('pret')->assertCreated();
        $this->envoiPatient('audio_seul', ['actif' => true, 'raison' => 'debit'])->assertCreated();

        $signaux = $this->lectureMedecin()->json('signaux');
        $this->assertSame(['pret', 'audio_seul'], array_column($signaux, 'type'));
        $this->assertEquals(['actif' => true, 'raison' => 'debit'], $signaux[1]['charge']);
    }

    public function test_un_tiers_ne_lit_ni_n_ecrit(): void
    {
        $this->envoiMedecin('offre', $this->offre());
        $tiers = [
            fn () => $this->actingAs($this->patient()),
            fn () => $this->commePersonnel($this->medecinActive()->user),
            fn () => $this->commePersonnel($this->personnelActive('caisse')),
            fn () => $this->commePersonnel($this->personnelActive('admin_fonctionnel')),
        ];

        foreach ($tiers as $commeTiers) {
            foreach (['consultation.signaux', 'personnel.medecin.consultation.signaux'] as $route) {
                $reponse = $commeTiers()->getJson(route($route, $this->rdv));
                $this->assertContains($reponse->status(), [403], $route);
                $this->assertArrayNotHasKey('signaux', $reponse->json() ?? []);
            }
            foreach (['consultation.signaux.envoyer', 'personnel.medecin.consultation.signaux.envoyer'] as $route) {
                $commeTiers()->postJson(route($route, $this->rdv), ['type' => 'pret', 'charge' => []])->assertForbidden();
            }
        }

        $this->assertSame(1, SignalConsultation::count());
    }

    public function test_un_patient_ne_lit_pas_les_signaux_adresses_au_patient_d_un_autre_rendez_vous(): void
    {
        $autrePatient = $this->patient();
        $autreMedecin = $this->medecinActive();
        $autreRdv = $this->rendezVousExistant($autreMedecin, $autrePatient, $this->lundi('09:00'));
        app(AccesConsultation::class)->entrer($autrePatient, $autreRdv);
        // Le médecin de l'autre rendez-vous envoie une offre à SON patient.
        $this->commePersonnel($autreMedecin->user)
            ->postJson(route('personnel.medecin.consultation.signaux.envoyer', $autreRdv), ['type' => 'offre', 'charge' => $this->offre()])
            ->assertCreated();

        $this->assertSame([], $this->lecturePatient()->json('signaux'), 'Rien dans sa propre salle.');
        $this->lecturePatient(0, $autreRdv)->assertForbidden();
        $this->envoiPatient('reponse', ['type' => 'answer', 'sdp' => self::SDP], null, $autreRdv)->assertForbidden();
        $this->actingAs($autrePatient)->getJson(route('consultation.signaux', $autreRdv))->assertOk()->assertJsonCount(1, 'signaux');
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function formesInvalides(): array
    {
        return [
            'type inconnu' => ['bonjour', []],
            'offre sans sdp' => ['offre', ['type' => 'offer']],
            'offre mal typée' => ['offre', ['type' => 'answer', 'sdp' => self::SDP]],
            'sdp qui n\'en est pas un' => ['offre', ['type' => 'offer', 'sdp' => '<script>alert(1)</script>']],
            'réponse en texte' => ['reponse', 'v=0'],
            'candidat sans repère' => ['candidat', ['candidate' => 'candidate:1 1 udp 1 1.2.3.4 1 typ host']],
            'candidat qui n\'en est pas un' => ['candidat', ['candidate' => 'rm -rf /', 'sdpMid' => '0', 'sdpMLineIndex' => 0]],
            'index négatif' => ['candidat', ['candidate' => 'candidate:1', 'sdpMid' => '0', 'sdpMLineIndex' => -1]],
            'audio seul sans raison' => ['audio_seul', ['actif' => true]],
            'audio seul en texte' => ['audio_seul', ['actif' => 'oui', 'raison' => 'manuel']],
            'raccroche avec données' => ['raccroche', ['message' => 'x']],
        ];
    }

    #[DataProvider('formesInvalides')]
    public function test_la_forme_des_signaux_est_validee(string $type, mixed $charge): void
    {
        $this->envoiMedecin($type, $charge)->assertStatus(422);

        $this->assertSame(0, SignalConsultation::count());
    }

    public function test_les_champs_inconnus_ne_sont_pas_gardes(): void
    {
        $this->envoiMedecin('offre', $this->offre() + ['espion' => 'x'])->assertCreated();

        $this->assertEquals($this->offre(), SignalConsultation::sole()->charge);
    }

    public function test_la_taille_d_un_signal_est_bornee(): void
    {
        config(['doc-and-i.teleconsultation.signal_octets_max' => 2048]);

        $this->envoiMedecin('offre', ['type' => 'offer', 'sdp' => self::SDP.str_repeat('a=x'."\r\n", 600)])
            ->assertStatus(422)->assertJsonValidationErrors(['charge' => 'Signal trop volumineux.']);
        $this->envoiMedecin('offre', ['type' => 'offer', 'sdp' => self::SDP.str_repeat('a=x'."\r\n", 100)])->assertCreated();

        $this->assertSame(1, SignalConsultation::count());
    }

    public function test_le_debit_est_borne_par_un_limiteur_nomme(): void
    {
        config(['doc-and-i.teleconsultation.signaux_par_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->envoiPatient('pret')->assertCreated();
        }
        $this->envoiPatient('pret')->assertStatus(429);
        $this->assertSame(3, SignalConsultation::count());

        $route = app('router')->getRoutes()->getByName('consultation.signaux.envoyer');
        $this->assertContains('throttle:signaux-consultation', $route->gatherMiddleware());

        $this->travel(61)->seconds();
        $this->envoiPatient('pret')->assertCreated();
    }

    public function test_double_envoi_avec_la_meme_cle_un_seul_signal(): void
    {
        $cle = (string) Str::uuid();

        $premier = $this->envoiMedecin('offre', $this->offre(), $cle)->assertCreated()->json('id');
        $second = $this->envoiMedecin('offre', $this->offre(), $cle)->assertOk()->json('id');

        $this->assertSame($premier, $second);
        $this->assertSame(1, SignalConsultation::count());
        $this->envoiMedecin('offre', $this->offre(), 'pas-une-cle')->assertStatus(422);
    }

    public function test_hors_de_la_fenetre_rien_ne_passe(): void
    {
        $this->envoiMedecin('offre', $this->offre())->assertCreated();

        // Fin prévue 09:20, salle fermée une heure après.
        $this->travelTo($this->lundi('10:21'));
        $this->lecturePatient()->assertForbidden()->assertJson(['message' => 'La salle de consultation est fermée à cette heure.']);
        $this->envoiMedecin('offre', $this->offre())->assertForbidden();

        $this->travelTo($this->lundi('08:49'));
        $this->lecturePatient()->assertForbidden();
        $this->assertSame(1, SignalConsultation::count());
    }

    public function test_une_salle_close_purge_ses_signaux_et_ne_relaie_plus(): void
    {
        app(AccesConsultation::class)->entrer($this->medecin->user, $this->rdv);
        $this->envoiMedecin('offre', $this->offre())->assertCreated();
        $this->envoiPatient('pret')->assertCreated();

        DB::transaction(function () {
            $c = Consultation::whereKey($this->consultation->id)->lockForUpdate()->firstOrFail();
            $c->transitionVers(StatutConsultation::Terminee);
            $c->save();
        });

        $this->assertSame(0, SignalConsultation::count(), 'Purgés dans la transaction de fermeture.');
        $this->lecturePatient()->assertForbidden();
        $this->envoiMedecin('offre', $this->offre())->assertForbidden();
        $this->assertSame(0, SignalConsultation::count());
    }

    public function test_un_rendez_vous_annule_ferme_la_signalisation(): void
    {
        $this->rdv->forceFill(['statut' => StatutRendezVous::AnnuleParMedecin])->save();

        $this->envoiPatient('pret')->assertForbidden();
    }

    public function test_sans_salle_ouverte_la_signalisation_refuse(): void
    {
        $autreRdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('11:00'));

        $this->envoiPatient('pret', [], null, $autreRdv)->assertForbidden();
        $this->assertSame(0, SignalConsultation::count());
    }

    public function test_la_commande_planifiee_purge_les_salles_closes_passees_et_les_vieux_signaux(): void
    {
        // Salle ouverte, signal récent : gardé.
        $this->envoiMedecin('offre', $this->offre())->assertCreated();

        // Salle d'un rendez-vous passé depuis plus d'une heure : purgée.
        $this->travelTo($this->lundi('07:00'));
        $passe = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('07:00'));
        [$sallePassee] = app(AccesConsultation::class)->entrer($this->medecin->user, $passe);
        $this->commePersonnel($this->medecin->user)
            ->postJson(route('personnel.medecin.consultation.signaux.envoyer', $passe), ['type' => 'offre', 'charge' => $this->offre()])->assertCreated();

        // Signal très ancien dans la salle encore ouverte : purgé.
        DB::table('signaux_consultation')->insert([
            'consultation_id' => $this->consultation->id, 'emetteur_id' => $this->medecin->user_id, 'destinataire' => 'patient',
            'type' => 'raccroche', 'charge' => '{}', 'cree_le' => $this->lundi('05:00'),
        ]);

        $this->travelTo($this->lundi('08:55'));
        $this->artisan('teleconsultation:purger-signaux')->assertSuccessful();

        $restants = SignalConsultation::all();
        $this->assertCount(1, $restants);
        $this->assertSame($this->consultation->id, $restants[0]->consultation_id);
        $this->assertSame('offre', $restants[0]->type);
        $this->assertSame(0, SignalConsultation::where('consultation_id', $sallePassee->id)->count());

        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'teleconsultation:purger-signaux'));
        $this->assertNotNull($evenement);
        $this->assertSame('*/10 * * * *', $evenement->expression);
    }

    public function test_un_identifiant_mal_forme_donne_404(): void
    {
        $this->actingAs($this->patient)->getJson('/consultation/abc/signaux')->assertNotFound();
    }
}
