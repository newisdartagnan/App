<?php

namespace Tests\Feature\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\LigneOrdonnance;
use App\Models\Ordonnance;
use App\Services\Dossier\DocumentOrdonnance;
use App\Services\Dossier\OrdonnanceService;
use App\Services\Paiement\FacturationService;
use App\Sms\EnvoyeurSms;
use App\Support\Empreinte;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-01, PRE-03, NOT-02 : ordonnance signée, numérotée, non modifiable ; PDF ; SMS sans contenu médical. */
class OrdonnanceTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->preparerConsultation('09:05');
        $this->patientRdv->forceFill(['date_naissance' => '1990-03-15'])->save();
        $this->medecinRdv->forceFill(['numero_ordre' => 'CNOM-12345'])->save();
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    public static function ligne(array $autres = []): array
    {
        return $autres + [
            'medicament' => 'Paracétamol', 'dosage' => '500 mg', 'voie' => 'orale',
            'posologie' => '1 comprimé 3 fois par jour', 'duree' => '5 jours', 'consignes' => 'Pendant les repas',
        ];
    }

    private function emettre(array $donnees = [], $rdv = null): TestResponse
    {
        $rdv ??= $this->rdv;

        return $this->commePersonnel($rdv->medecin->user)->postJson(
            route('personnel.medecin.consultation.ordonnances.emettre', ['rendezVous' => $rdv->id]),
            $donnees + ['cle' => (string) Str::uuid(), 'consignes' => 'Revenir si la fièvre persiste.', 'lignes' => [self::ligne()]],
        );
    }

    // --- Émission ----------------------------------------------------------------------------------

    public function test_signer_et_emettre_numerote_empreint_et_donne_un_code_de_verification(): void
    {
        $this->emettre(['lignes' => [self::ligne(), self::ligne(['medicament' => 'Amoxicilline', 'dosage' => '1 g', 'consignes' => null])]])
            ->assertCreated()->assertJsonPath('ordonnance.numero', 'OR-2026-000001')
            ->assertJsonPath('message', 'Ordonnance OR-2026-000001 émise. Le patient est prévenu par SMS.')
            ->assertJsonMissingPath('ordonnance.code_verification')
            ->assertJsonCount(1, 'ordonnances')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $o = Ordonnance::with('lignes')->sole();
        $this->assertSame([$this->rdv->id, $this->medecinRdv->id, $this->patientRdv->id], [$o->rendez_vous_id, $o->medecin_id, $o->patient_id]);
        $this->assertTrue($o->signee_le->equalTo($this->lundi('09:05')));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $o->code_verification);
        $this->assertSame([1, 2], $o->lignes->pluck('rang')->all());
        $this->assertSame(['Paracétamol', 'Amoxicilline'], $o->lignes->pluck('medicament')->all());
        $this->assertNull($o->lignes[1]->consignes);

        $this->assertSame(Empreinte::de([
            'numero' => 'OR-2026-000001', 'rendez_vous_id' => $this->rdv->id, 'medecin_id' => $this->medecinRdv->id, 'patient_id' => $this->patientRdv->id,
            'consignes' => 'Revenir si la fièvre persiste.', 'signee_le' => $this->lundi('09:05')->utc()->format('Y-m-d\TH:i:s\Z'),
            'lignes' => [
                ['rang' => 1, 'medicament' => 'Paracétamol', 'dosage' => '500 mg', 'voie' => 'orale', 'posologie' => '1 comprimé 3 fois par jour', 'duree' => '5 jours', 'consignes' => 'Pendant les repas'],
                ['rang' => 2, 'medicament' => 'Amoxicilline', 'dosage' => '1 g', 'voie' => 'orale', 'posologie' => '1 comprimé 3 fois par jour', 'duree' => '5 jours', 'consignes' => null],
            ],
        ]), $o->empreinte);
        $this->assertTrue(OrdonnanceService::estAuthentique($o));
    }

    public function test_l_ordonnance_reste_authentique_quand_l_emission_tombe_entre_deux_secondes(): void
    {
        // La base garde la seconde (arrondie) : l'empreinte doit porter sur la même valeur.
        $this->travelTo($this->lundi('09:07')->addMicroseconds(700000));
        $this->emettre()->assertCreated();

        $this->assertTrue(OrdonnanceService::estAuthentique(Ordonnance::sole()));
    }

    public function test_plusieurs_ordonnances_par_rendez_vous_numerotees_sans_trou(): void
    {
        $this->emettre()->assertCreated();
        // Un envoi refusé (validation) ne consomme pas de numéro.
        $this->emettre(['lignes' => [self::ligne(['dosage' => ''])]])->assertStatus(422);
        $this->emettre()->assertCreated();
        // Une facture entre-temps : numérotation propre à chaque préfixe.
        app(FacturationService::class)->numeroSuivant('FA');
        $this->emettre()->assertCreated()->assertJsonCount(3, 'ordonnances');

        $this->assertSame(['OR-2026-000001', 'OR-2026-000002', 'OR-2026-000003'], Ordonnance::orderBy('numero')->pluck('numero')->all());
    }

    public function test_un_double_clic_ne_fait_pas_deux_ordonnances(): void
    {
        $cle = (string) Str::uuid();

        $premiere = $this->emettre(['cle' => $cle])->assertCreated()->json('ordonnance.id');
        $seconde = $this->emettre(['cle' => $cle])->assertCreated()->json('ordonnance.id');

        $this->assertSame($premiere, $seconde);
        $this->assertSame(1, Ordonnance::count());
        $this->assertCount(1, $this->sms->envoyes, 'Un seul SMS.');
        $this->assertSame('OR-2026-000001', Ordonnance::sole()->numero);
        $this->emettre()->assertCreated()->assertJsonPath('ordonnance.numero', 'OR-2026-000002');
    }

    public function test_une_cle_deja_servie_sur_un_autre_rendez_vous_est_refusee(): void
    {
        $cle = (string) Str::uuid();
        $this->emettre(['cle' => $cle])->assertCreated();
        $autre = $this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00'));

        $this->emettre(['cle' => $cle], $autre)->assertForbidden()->assertJsonPath('message', 'Ce formulaire a déjà servi. Rechargez la page.');
        $this->assertSame(1, Ordonnance::count());
    }

    public function test_une_ordonnance_ne_se_modifie_pas_une_erreur_se_corrige_par_une_nouvelle(): void
    {
        $this->emettre()->assertCreated();
        $o = Ordonnance::with('lignes')->sole();

        foreach ([fn () => $o->forceFill(['consignes' => 'Autre'])->save(), fn () => $o->lignes[0]->forceFill(['dosage' => '1 g'])->save(), fn () => $o->delete()] as $tentative) {
            try {
                $tentative();
                $this->fail('Ordonnance modifiée.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        // Aucune route ne modifie ni ne supprime une ordonnance.
        $this->commeMedecinRdv()->putJson(route('personnel.medecin.consultation.ordonnances', $this->rdv->id), ['consignes' => 'x'])->assertStatus(405);
        $this->commeMedecinRdv()->deleteJson(route('personnel.medecin.consultation.ordonnances', $this->rdv->id))->assertStatus(405);

        $this->emettre(['lignes' => [self::ligne(['dosage' => '1 g'])]])->assertCreated();
        $this->assertSame(2, Ordonnance::count());
        $this->assertSame('500 mg', LigneOrdonnance::whereHas('ordonnance', fn ($q) => $q->where('numero', 'OR-2026-000001'))->sole()->dosage);
    }

    public function test_les_lignes_sont_validees(): void
    {
        foreach ([
            ['lignes' => []],
            ['lignes' => [self::ligne(['medicament' => ''])]],
            ['lignes' => [self::ligne(['posologie' => null])]],
            ['lignes' => [self::ligne(['duree' => str_repeat('x', 101)])]],
            ['lignes' => [self::ligne(['voie' => "orale\u{0007}"])]],
            ['lignes' => array_fill(0, OrdonnanceService::LIGNES_MAX + 1, self::ligne())],
            ['lignes' => 'Paracétamol'],
            ['cle' => 'pas-une-cle'],
            ['consignes' => str_repeat('x', 2001)],
        ] as $invalide) {
            $this->emettre($invalide)->assertStatus(422);
        }

        $this->assertSame(0, Ordonnance::count());
        $this->assertSame([], $this->sms->envoyes);
    }

    // --- Accès ---------------------------------------------------------------------------------------

    public function test_seul_le_medecin_de_ce_rendez_vous_prescrit_et_voit_les_ordonnances(): void
    {
        $this->emettre()->assertCreated();
        $adresse = route('personnel.medecin.consultation.ordonnances', $this->rdv->id);

        $confrere = $this->medecinActive()->user;
        $this->commePersonnel($confrere)->getJson($adresse)->assertForbidden();
        $this->commePersonnel($confrere)->postJson($adresse, ['cle' => (string) Str::uuid(), 'lignes' => [self::ligne()]])->assertForbidden();
        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->getJson($adresse)->assertForbidden();
            $this->commePersonnel($this->personnelActive($role))->postJson($adresse, ['cle' => (string) Str::uuid(), 'lignes' => [self::ligne()]])->assertForbidden();
        }
        $this->assertNotSame(201, $this->commePatientRdv()->postJson($adresse, ['cle' => (string) Str::uuid(), 'lignes' => [self::ligne()]])->status());

        $this->assertSame(1, Ordonnance::count());
    }

    public function test_un_rendez_vous_annule_ou_expire_ne_permet_pas_de_prescrire(): void
    {
        foreach ([StatutRendezVous::AnnuleParPatient, StatutRendezVous::AnnuleParMedecin, StatutRendezVous::Expire, StatutRendezVous::AbsenceMedecin, StatutRendezVous::EnAttentePaiement] as $statut) {
            $this->rdv->forceFill(['statut' => $statut])->save();
            $this->emettre()->assertForbidden();
        }

        $this->assertSame(0, Ordonnance::count());
        $this->assertSame(0, (int) \DB::table('compteurs_numerotation')->where('prefixe', 'OR')->value('dernier'), 'Aucun numéro consommé.');
    }

    public function test_on_prescrit_pendant_la_consultation_ou_apres_quand_elle_est_terminee(): void
    {
        $this->travelTo($this->lundi('10:21'));
        $this->emettre()->assertForbidden();

        $this->rdv->forceFill(['statut' => StatutRendezVous::Termine])->save();
        $this->travelTo($this->lundi('18:00'));
        $this->emettre()->assertCreated();
    }

    // --- SMS (NOT-02) --------------------------------------------------------------------------------

    public function test_le_patient_est_prevenu_par_un_sms_sans_contenu_medical(): void
    {
        $this->emettre()->assertCreated();

        $this->assertSame([['telephone' => $this->patientRdv->telephone, 'message' => 'MEDIHOME : un document vous attend dans votre espace.']], $this->sms->envoyes);
    }

    public function test_une_panne_sms_ne_defait_pas_l_ordonnance_et_le_journal_n_a_ni_numero_ni_contenu(): void
    {
        $this->app->instance(EnvoyeurSms::class, new class implements EnvoyeurSms
        {
            public function envoyer(string $telephone, string $message): void
            {
                throw new RuntimeException("Échec vers {$telephone}");
            }
        });
        Log::spy();

        $this->emettre()->assertCreated();

        $this->assertSame(1, Ordonnance::count());
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $contexte) {
            $texte = $message.json_encode($contexte);

            return str_contains($message, 'SMS') && ! str_contains($texte, $this->patientRdv->telephone) && ! str_contains($texte, 'Paracétamol');
        })->once();
    }

    // --- PDF ------------------------------------------------------------------------------------------

    public function test_le_pdf_porte_le_qr_code_integre_et_aucune_ressource_externe(): void
    {
        $this->emettre()->assertCreated();
        $o = Ordonnance::sole();

        $reponse = $this->commePatientRdv()->get(route('ordonnances.pdf', $o->id))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertDownload('OR-2026-000001.pdf');
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        $pdf = $reponse->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        // Le QR code (SVG en « data: ») est dessiné en vecteurs : des centaines de tracés.
        $this->assertGreaterThan(200, preg_match_all('/ l\b/', self::contenuPdf($pdf)), 'QR code absent du PDF.');

        $html = view('pdf.ordonnance', [
            'ordonnance' => $o->load('lignes'), 'date' => '07/12/2026', 'heure' => '09:05', 'medecin' => 'Dr X', 'numeroOrdre' => 'CNOM-12345',
            'specialite' => null, 'patient' => 'Marie KABILA', 'age' => 36, 'qr' => 'data:image/svg+xml;base64,AAAA', 'adresse' => DocumentOrdonnance::adresseVerification($o),
        ])->render();
        $this->assertDoesNotMatchRegularExpression('/(src|href)=["\']https?:/i', $html, 'Aucune ressource externe.');
    }

    public function test_le_pdf_montre_medecin_patient_lignes_et_l_adresse_de_verification(): void
    {
        $this->emettre()->assertCreated();
        $o = Ordonnance::sole();
        $this->commeMedecinRdv()->get(route('personnel.medecin.ordonnances.pdf', $o->id))->assertOk()->assertDownload('OR-2026-000001.pdf');

        $donnees = app(DocumentOrdonnance::class)->donneesPdf($o);
        $html = view('pdf.ordonnance', $donnees)->render();
        $patient = trim($this->patientRdv->prenom.' '.mb_strtoupper((string) $this->patientRdv->nom));
        foreach (['MEDI', 'HOME', 'Ordonnance n° OR-2026-000001', '07/12/2026', 'CNOM-12345', e($patient).', 36 ans', 'Paracétamol — 500 mg', 'Voie : orale',
            'Posologie : 1 comprimé 3 fois par jour', 'Durée : 5 jours', 'Consignes : Pendant les repas', 'Revenir si la fièvre persiste.',
            '/verification-ordonnance/'.$o->code_verification, 'src="data:image/svg+xml;base64,'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertStringNotContainsString((string) $this->patientRdv->telephone, $html);
        $this->assertSame(route('ordonnance.verification', $o->code_verification), $donnees['adresse']);
    }

    public function test_le_pdf_est_reserve_au_patient_et_au_medecin_de_l_ordonnance(): void
    {
        $this->emettre()->assertCreated();
        $o = Ordonnance::sole();

        $this->commePatientRdv()->get(route('ordonnances.pdf', $o->id))->assertOk();
        $this->commeMedecinRdv()->get(route('personnel.medecin.ordonnances.pdf', $o->id))->assertOk();

        $this->actingAs($this->patient())->get(route('ordonnances.pdf', $o->id))->assertNotFound();
        $this->commePersonnel($this->medecinActive()->user)->get(route('personnel.medecin.ordonnances.pdf', $o->id))->assertNotFound();
        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->get(route('personnel.medecin.ordonnances.pdf', $o->id))->assertForbidden();
            $this->commePersonnel($this->personnelActive($role))->get(route('ordonnances.pdf', $o->id))->assertForbidden();
        }
        $this->assertNotSame(200, $this->commePatientRdv()->get(route('personnel.medecin.ordonnances.pdf', $o->id))->status());
    }

    // --- Mes ordonnances ---------------------------------------------------------------------------------

    public function test_mes_ordonnances_ne_montre_que_les_siennes_sans_code_de_verification(): void
    {
        $this->emettre()->assertCreated();
        $autre = $this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00'));
        $this->emettre([], $autre)->assertCreated();

        $reponse = $this->commePatientRdv()->get(route('ordonnances.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dossier/MesOrdonnances')
                ->has('ordonnances', 1)
                ->where('ordonnances.0.numero', 'OR-2026-000001')
                ->where('ordonnances.0.lignes', 1));
        $this->assertStringNotContainsString(Ordonnance::where('numero', 'OR-2026-000001')->value('code_verification'), $reponse->getContent());
    }

    /** Flux décompressés d'un PDF. */
    public static function contenuPdf(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $flux);

        return implode("\n", array_filter(array_map(fn ($f) => @gzuncompress($f) ?: null, $flux[1])));
    }
}
