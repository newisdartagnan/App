<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Enums\StatutRendezVous;
use App\Models\BonExamen;
use App\Models\Examen;
use App\Models\LigneBonExamen;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursLaboratoire;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-04, NOT-02 : prescription d'examens par le médecin de CE rendez-vous. */
class PrescriptionExamensTest extends TestCase
{
    use ParcoursConsultation, ParcoursLaboratoire, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    private ?Examen $examenParDefaut = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->preparerConsultation('09:05');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    private function prescrire(array $donnees = [], $rdv = null, $comme = null): TestResponse
    {
        $rdv ??= $this->rdv;
        $donnees['examens'] ??= [($this->examenParDefaut ??= $this->examen('DEF', 'Examen de test', '4.00'))->id];

        return $this->commePersonnel($comme ?? $rdv->medecin->user)->postJson(route('personnel.medecin.consultation.examens.prescrire', $rdv->id), $donnees + [
            'cle' => (string) Str::uuid(), 'renseignements' => 'Fièvre, frissons.',
        ]);
    }

    public function test_le_medecin_prescrit_un_bon_numerote_au_montant_fige(): void
    {
        $labo = $this->laboratoire('Eyano Centre Biologique (test)');
        $gly = $this->examen('GLY', 'Glycémie à jeun', '5.00');
        $nfs = $this->examen('NFS', 'Numération formule sanguine', '12.50');

        $this->prescrire(['examens' => [$nfs->id, $gly->id], 'laboratoire_id' => $labo->id, 'date_souhaitee' => '2026-12-08'])
            ->assertCreated()
            ->assertJsonPath('bon.numero', 'BE-2026-000001')
            ->assertJsonPath('bon.statut', 'prescrit')
            ->assertJsonPath('bon.laboratoire', 'Eyano Centre Biologique (test)')
            ->assertJsonPath('message', 'Bon BE-2026-000001 prescrit. Le patient est prévenu par SMS.')
            ->assertJsonCount(1, 'bons')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $bon = BonExamen::with('lignes')->sole();
        $this->assertSame([$this->rdv->id, $this->medecinRdv->id, $this->patientRdv->id, $labo->id], [$bon->rendez_vous_id, $bon->medecin_id, $bon->patient_id, $bon->laboratoire_id]);
        $this->assertSame(['17.50', 'USD'], [(string) $bon->montant, $bon->devise]);
        $this->assertSame(['Numération formule sanguine', 'Glycémie à jeun'], $bon->lignes->pluck('libelle')->all());
        $this->assertSame('2026-12-08', $bon->date_souhaitee->toDateString());
        $this->assertNull($bon->code_retrait_empreinte);

        // Le catalogue change ensuite : le bon émis garde ses prix.
        $gly->update(['prix' => '9.00', 'libelle' => 'Autre']);
        $this->assertSame(['17.50', 'Glycémie à jeun'], [(string) $bon->fresh()->montant, LigneBonExamen::where('rang', 2)->sole()->libelle]);
    }

    public function test_le_patient_recoit_un_sms_sans_contenu_medical(): void
    {
        $this->prescrire()->assertCreated();

        $this->assertSame([['telephone' => $this->patientRdv->telephone, 'message' => 'MEDIHOME : un document vous attend dans votre espace.']], $this->sms->envoyes);
    }

    public function test_un_double_clic_ne_fait_qu_un_bon_et_un_numero(): void
    {
        $cle = (string) Str::uuid();
        $examen = $this->examen();

        $a = $this->prescrire(['cle' => $cle, 'examens' => [$examen->id]])->assertCreated()->json('bon.id');
        $b = $this->prescrire(['cle' => $cle, 'examens' => [$examen->id]])->assertCreated()->json('bon.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, BonExamen::count());
        $this->assertCount(1, $this->sms->envoyes);
        $this->prescrire(['examens' => [$examen->id]])->assertCreated()->assertJsonPath('bon.numero', 'BE-2026-000002');
    }

    public function test_seuls_les_examens_actifs_avec_un_prix_dans_une_meme_monnaie_se_prescrivent(): void
    {
        $sansPrix = $this->examen('ECG', 'Électrocardiogramme', null);
        $inactif = $this->examen('VS', 'Vitesse de sédimentation', '3.00', actif: false);
        $usd = $this->examen('GLY', 'Glycémie', '5.00');
        $cdf = $this->examen('URE', 'Urée', '9000.00', 'CDF');

        $this->prescrire(['examens' => [$sansPrix->id]])->assertForbidden();
        $this->prescrire(['examens' => [$inactif->id]])->assertForbidden();
        $this->prescrire(['examens' => [$usd->id, $cdf->id]])->assertForbidden()->assertJsonPath('message', 'Ces examens ne sont pas dans la même monnaie : faites un bon par monnaie.');
        $this->prescrire(['examens' => []])->assertStatus(422);
        $this->prescrire(['examens' => [$usd->id, $usd->id]])->assertStatus(422);
        $this->prescrire(['examens' => [$usd->id], 'date_souhaitee' => '2026-12-01'])->assertStatus(422);
        $this->prescrire(['examens' => [$usd->id], 'laboratoire_id' => $this->laboratoire('Fermé', actif: false)->id])->assertStatus(422);

        $this->assertSame(0, BonExamen::count());
        $this->assertSame(0, (int) DB::table('compteurs_numerotation')->where('prefixe', 'BE')->value('dernier'), 'Aucun numéro consommé.');
        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_seul_le_medecin_de_ce_rendez_vous_prescrit_et_voit_les_bons(): void
    {
        $this->prescrire()->assertCreated();
        $adresse = route('personnel.medecin.consultation.examens', $this->rdv->id);

        $confrere = $this->medecinActive()->user;
        $this->commePersonnel($confrere)->getJson($adresse)->assertForbidden();
        $this->prescrire(comme: $confrere)->assertForbidden();
        foreach (['caisse', 'admin_fonctionnel', 'laboratoire', 'coordinateur'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->postJson(route('personnel.medecin.consultation.examens.prescrire', $this->rdv->id), ['cle' => (string) Str::uuid(), 'examens' => []])->assertForbidden();
        }
        $this->assertNotSame(201, $this->commePatientRdv()->postJson(route('personnel.medecin.consultation.examens.prescrire', $this->rdv->id), ['cle' => (string) Str::uuid()])->status());

        $this->assertSame(1, BonExamen::count());
    }

    public function test_un_rendez_vous_annule_ou_expire_ne_permet_pas_de_prescrire_hors_fenetre_non_plus(): void
    {
        foreach ([StatutRendezVous::AnnuleParPatient, StatutRendezVous::Expire, StatutRendezVous::AbsencePatient] as $statut) {
            $this->rdv->forceFill(['statut' => $statut])->save();
            $this->prescrire()->assertForbidden();
        }

        $this->rdv->forceFill(['statut' => StatutRendezVous::Confirme])->save();
        $this->travelTo($this->lundi('10:21'));
        $this->prescrire()->assertForbidden();

        // Terminé : se prescrit après coup, depuis la page « Note ».
        $this->rdv->forceFill(['statut' => StatutRendezVous::Termine])->save();
        $this->travelTo($this->lundi('18:00'));
        $this->prescrire()->assertCreated();
        $this->assertSame(1, BonExamen::count());
    }

    public function test_le_composant_recoit_le_catalogue_prescriptible_et_les_laboratoires_actifs(): void
    {
        $this->examen('GLY', 'Glycémie', '5.00');
        $this->examen('ECG', 'Électrocardiogramme', null);
        $this->laboratoire('Actif');
        $this->laboratoire('Désactivé', actif: false);

        $this->commeMedecinRdv()->getJson(route('personnel.medecin.consultation.examens', $this->rdv->id))
            ->assertOk()->assertJsonPath('redigeable', true)
            ->assertJsonCount(1, 'catalogue')->assertJsonPath('catalogue.0.libelle', 'Glycémie')
            ->assertJsonCount(1, 'laboratoires')->assertJsonPath('laboratoires.0.nom', 'Actif');
    }

    public function test_un_bon_prescrit_ne_peut_aller_au_laboratoire_sans_paiement(): void
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->laboratoire());

        $this->assertSame(StatutBonExamen::Prescrit, $bon->statut);
        $this->assertFalse(StatutBonExamen::Prescrit->peutPasserA(StatutBonExamen::EnCours));
    }
}
