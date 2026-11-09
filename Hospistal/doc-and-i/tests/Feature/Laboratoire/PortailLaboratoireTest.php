<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\AlerteMedecin;
use App\Models\BonExamen;
use App\Models\Partenaire;
use App\Models\ResultatExamen;
use App\Models\User;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursLaboratoire;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-05, PRE-06 : portail du laboratoire (réception par code de retrait, dépôt des résultats). */
class PortailLaboratoireTest extends TestCase
{
    use ParcoursConsultation, ParcoursLaboratoire, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    private Partenaire $labo;

    private User $compte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation('09:05');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        $this->labo = $this->laboratoire('Eyano Centre Biologique (test)');
        $this->compte = $this->compteLaboratoire($this->labo);
    }

    private function bonPaye(): BonExamen
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->labo);
        $this->payerBon($bon);

        return $bon->fresh();
    }

    private function recevoir($bon, string $code, $compte = null)
    {
        return $this->commePersonnel($compte ?? $this->compte)->post(route('personnel.laboratoire.recevoir', $bon->id), ['code' => $code]);
    }

    private function deposerHttp($bon, array $donnees, $compte = null)
    {
        return $this->commePersonnel($compte ?? $this->compte)->post(route('personnel.laboratoire.deposer', $bon->id), $donnees + ['cle' => (string) Str::uuid()]);
    }

    public function test_le_compte_laboratoire_arrive_sur_ses_bons_payes_seulement(): void
    {
        $paye = $this->bonPaye();
        $nonPaye = $this->bonPrescrit([$this->examen('NFS', 'NFS')], $this->labo);
        $autreLabo = $this->bonPrescrit([$this->examen('URE', 'Urée')], $this->laboratoire('Autre laboratoire'));
        $this->payerBon($autreLabo);

        $this->commePersonnel($this->compte)->get(route('personnel.accueil'))->assertRedirect(route('personnel.laboratoire.accueil'));
        $reponse = $this->commePersonnel($this->compte)->get(route('personnel.laboratoire.accueil'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Laboratoire/Portail')->has('bons', 1)->where('bons.0.numero', $paye->numero));

        $this->commePersonnel($this->compte)->get(route('personnel.laboratoire.bon', $nonPaye->id))->assertNotFound();
        $this->commePersonnel($this->compte)->get(route('personnel.laboratoire.bon', $autreLabo->id))->assertNotFound();
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
    }

    public function test_le_laboratoire_ne_voit_rien_d_autre_du_dossier(): void
    {
        $this->rdv->demande->forceFill(['motif' => 'Motif confidentiel de la demande'])->save();
        $bon = $this->bonPaye();

        $contenu = $this->commePersonnel($this->compte)->get(route('personnel.laboratoire.bon', $bon->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Laboratoire/BonLaboratoire')
                ->where('bon.numero', $bon->numero)->where('bon.renseignements', 'Fièvre depuis trois jours.')
                ->missing('bon.telephone')->missing('bon.motif')->missing('bon.patient_id'))
            ->getContent();
        $this->assertStringNotContainsString('Motif confidentiel', $contenu);
        $this->assertStringNotContainsString(substr((string) $this->patientRdv->telephone, 4), $contenu);
    }

    public function test_patient_recu_avec_le_bon_code_et_double_envoi_sans_effet(): void
    {
        $bon = $this->bonPaye();
        $this->recevoir($bon, '12345678')->assertSessionHasErrors(['code' => 'Le patient n\'a pas encore obtenu son code de retrait : il le trouve dans son espace, « Mes examens ».']);
        $code = $this->codeRetrait($bon);

        $this->recevoir($bon, '00000000')->assertSessionHasErrors(['code' => 'Code de retrait incorrect.']);
        $this->assertSame(StatutBonExamen::Paye, $bon->fresh()->statut);

        $this->recevoir($bon, $code)->assertSessionHasNoErrors();
        $this->assertSame([StatutBonExamen::EnCours, $this->compte->id], [$bon->fresh()->statut, $bon->fresh()->recu_par]);
        $this->recevoir($bon, $code)->assertSessionHasNoErrors();
        $this->assertSame(StatutBonExamen::EnCours, $bon->fresh()->statut);
    }

    public function test_les_essais_de_code_faux_sont_bornes(): void
    {
        $bon = $this->bonPaye();
        $code = $this->codeRetrait($bon);

        for ($i = 0; $i < 5; $i++) {
            $this->recevoir($bon, '1111111'.$i)->assertSessionHasErrors('code');
        }
        $this->recevoir($bon, $code)->assertSessionHasErrors(['code' => 'Trop d\'essais de code pour ce bon. Réessayez dans une heure, ou demandez au patient un nouveau code.']);
        $this->assertSame(StatutBonExamen::Paye, $bon->fresh()->statut);
    }

    public function test_un_autre_laboratoire_un_compte_ou_un_laboratoire_desactive_n_agit_pas(): void
    {
        $bon = $this->bonPaye();
        $code = $this->codeRetrait($bon);

        $autre = $this->compteLaboratoire($this->laboratoire('Autre laboratoire'));
        $this->recevoir($bon, $code, $autre)->assertSessionHasErrors(['code' => 'Bon introuvable pour votre laboratoire.']);
        $this->commePersonnel($autre)->get(route('personnel.laboratoire.bon', $bon->id))->assertNotFound();

        $this->labo->update(['actif' => false]);
        $this->commePersonnel($this->compte)->get(route('personnel.laboratoire.accueil'))->assertForbidden();
        $this->recevoir($bon, $code)->assertForbidden();
        $this->labo->update(['actif' => true]);

        $this->compte->forceFill(['desactive_le' => now()])->save();
        $this->assertNotSame(200, $this->commePersonnel($this->compte->fresh())->get(route('personnel.laboratoire.accueil'))->status());

        foreach (['medecin' => $this->medecinRdv->user, 'caisse' => $this->personnelActive('caisse'), 'admin' => $this->personnelActive('admin_fonctionnel')] as $qui) {
            $this->commePersonnel($qui)->get(route('personnel.laboratoire.accueil'))->assertForbidden();
        }
        $this->assertSame(StatutBonExamen::Paye, $bon->fresh()->statut);
    }

    public function test_depot_de_valeurs_et_d_un_pdf_puis_alerte_au_prescripteur(): void
    {
        $bon = $this->bonEnCours($this->labo, $this->compte, [$this->examen()]);

        $this->deposerHttp($bon, [
            'valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1,05', 'unite' => 'g/L', 'normes' => '0,70 - 1,10', 'anormal' => '0'], ['analyte' => '', 'valeur' => '']],
            'pdf' => UploadedFile::fake()->createWithContent('cr.pdf', $this->pdfSimple()),
        ])->assertSessionHasNoErrors();

        $resultat = ResultatExamen::sole();
        $this->assertEquals([['analyte' => 'Glycémie', 'valeur' => '1,05', 'unite' => 'g/L', 'normes' => '0,70 - 1,10', 'anormal' => false]], $resultat->valeurs, 'jsonb ne garde pas l\'ordre des clés');
        $this->assertMatchesRegularExpression('#^resultats/'.$bon->id.'/[A-Za-z0-9]{40}$#', $resultat->pdf_chemin);
        Storage::disk('local')->assertExists($resultat->pdf_chemin);
        $this->assertSame([hash('sha256', $this->pdfSimple()), strlen($this->pdfSimple()), 'portail', $this->compte->id], [$resultat->pdf_empreinte, $resultat->pdf_taille, $resultat->source, $resultat->depose_par]);
        $this->assertSame(StatutBonExamen::ResultatsDeposes, $bon->fresh()->statut);

        $alerte = AlerteMedecin::sole();
        $this->assertSame(['resultat_a_valider', 'urgent', $this->medecinRdv->id, 'Résultats d\'examen à valider (bon '.$bon->numero.').'], [$alerte->type, $alerte->gravite, $alerte->medecin_id, $alerte->message]);

        // Un second dépôt (autre examen) ne double pas l'alerte « à valider » ; un critique en ajoute une.
        $this->deposerHttp($bon, ['valeurs' => [['analyte' => 'Potassium', 'valeur' => '7,1', 'unite' => 'mmol/L', 'anormal' => '1']], 'critique' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, AlerteMedecin::where('type', 'resultat_a_valider')->count());
        $critique = AlerteMedecin::where('type', 'resultat_critique')->sole();
        $this->assertSame(['critique', 'Résultat critique à voir sans attendre (bon '.$bon->numero.').'], [$critique->gravite, $critique->message]);
        $this->assertStringNotContainsString('Potassium', $critique->message);
    }

    public function test_un_pdf_falsifie_est_refuse(): void
    {
        $bon = $this->bonEnCours($this->labo, $this->compte, [$this->examen()]);

        foreach ([
            'texte nommé .pdf' => 'ceci n\'est pas un PDF',
            'image nommée .pdf' => "\x89PNG\r\n\x1a\n".str_repeat('x', 200),
            'PDF tronqué' => substr($this->pdfSimple(), 0, -20),
            'PDF avec JavaScript' => str_replace('/Type /Catalog', '/Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >>', $this->pdfSimple()),
            'PDF avec fichier joint' => str_replace('/Type /Catalog', '/Type /Catalog /Names << /EmbeddedFiles << >> >> /EmbeddedFile', $this->pdfSimple()),
        ] as $cas => $octets) {
            $this->deposerHttp($bon, ['pdf' => UploadedFile::fake()->createWithContent('cr.pdf', $octets)])->assertSessionHasErrors('depot', $cas);
        }

        $this->assertSame(0, ResultatExamen::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(StatutBonExamen::EnCours, $bon->fresh()->statut);
    }

    public function test_un_pdf_trop_lourd_ou_au_dela_du_quota_est_refuse(): void
    {
        $bon = $this->bonEnCours($this->labo, $this->compte, [$this->examen()]);
        config(['doc-and-i.laboratoire.pdf_octets_max' => 400]);
        $this->deposerHttp($bon, ['pdf' => UploadedFile::fake()->createWithContent('cr.pdf', $this->pdfSimple(str_repeat('x', 500)))])->assertSessionHasErrors('depot');

        config(['doc-and-i.laboratoire.pdf_octets_max' => 10_000, 'doc-and-i.laboratoire.pdf_octets_par_bon' => (int) (strlen($this->pdfSimple()) * 1.5)]);
        $this->deposerHttp($bon, ['pdf' => UploadedFile::fake()->createWithContent('cr.pdf', $this->pdfSimple())])->assertSessionHasNoErrors();
        $this->deposerHttp($bon, ['pdf' => UploadedFile::fake()->createWithContent('cr.pdf', $this->pdfSimple())])->assertSessionHasErrors('depot');

        $this->assertSame(1, ResultatExamen::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_pas_de_depot_avant_la_reception_ni_sans_contenu_et_double_depot_sans_effet(): void
    {
        $bon = $this->bonPaye();
        $this->deposerHttp($bon, ['valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1']]])->assertSessionHasErrors(['depot' => 'Recevez d\'abord le patient avec son code de retrait.']);

        $this->recevoir($bon, $this->codeRetrait($bon));
        $this->deposerHttp($bon, ['valeurs' => []])->assertSessionHasErrors('valeurs');
        $this->deposerHttp($bon, ['valeurs' => [['analyte' => 'Glycémie', 'valeur' => '']]])->assertSessionHasErrors();

        $cle = (string) Str::uuid();
        $this->deposerHttp($bon, ['valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1']], 'cle' => $cle])->assertSessionHasNoErrors();
        $this->deposerHttp($bon, ['valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1']], 'cle' => $cle])->assertSessionHasNoErrors();
        $this->assertSame(1, ResultatExamen::count());
        $this->assertSame(1, AlerteMedecin::count());
    }
}
