<?php

namespace Tests\Feature\Laboratoire;

use App\Services\Laboratoire\ActeurLaboratoire;
use App\Services\Laboratoire\DepotResultats;
use App\Services\Laboratoire\DocumentPdf;
use App\Services\Laboratoire\LaboratoireRefuse;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursLaboratoire;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Non-régression de la revue de sécurité du lot laboratoire (PRE-05 à
 * PRE-08, RG-10) : PDF actif déguisé, accès durable du coordinateur,
 * laboratoire du médecin remplacé par le patient.
 */
class RevueSecuriteLaboratoireTest extends TestCase
{
    use ParcoursConsultation, ParcoursLaboratoire, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation('09:05');
        $this->app->instance(EnvoyeurSms::class, new EnvoyeurSmsFactice);
    }

    /** PDF dont le catalogue est rangé dans un flux d'objets compressé. */
    private function pdfAvecFluxDObjets(string $catalogue): string
    {
        $contenu = '1 0 '.$catalogue;
        $flux = gzcompress($contenu);
        $pdf = "%PDF-1.5\n";
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $pdf .= "3 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length ".strlen($flux)." >>\nstream\n{$flux}\nendstream\nendobj\n";

        return $pdf."trailer << /Root 1 0 R >>\n%%EOF\n";
    }

    private function refuse(string $pdf): bool
    {
        try {
            DocumentPdf::verifier($pdf);

            return false;
        } catch (LaboratoireRefuse) {
            return true;
        }
    }

    public function test_un_pdf_actif_deguise_est_refuse_et_un_pdf_simple_reste_accepte(): void
    {
        $simple = $this->pdfSimple();
        $this->assertFalse($this->refuse($simple));
        $this->assertFalse($this->refuse($this->pdfAvecFluxDObjets('<< /Type /Catalog /Pages 2 0 R >>')), 'Un flux d\'objets sans contenu actif reste accepté.');

        foreach ([
            'nom échappé #61' => str_replace('/Type /Catalog', '/Type /Catalog /OpenAction << /S /J#61vaScript /J#53 (app.alert(1)) >>', $simple),
            'lancement échappé' => str_replace('/Type /Catalog', '/Type /Catalog /OpenAction << /S /L#61unch /F (calc) >>', $simple),
            'JavaScript dans un flux d\'objets' => $this->pdfAvecFluxDObjets('<< /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>'),
            'échappé dans un flux d\'objets' => $this->pdfAvecFluxDObjets('<< /Type /Catalog /OpenAction << /S /L#61unch >> >>'),
            'flux d\'objets à filtre non lu' => str_replace('/Filter /FlateDecode', '/Filter [/ASCIIHexDecode /FlateDecode]', $this->pdfAvecFluxDObjets('<< /Type /Catalog >>')),
            'flux d\'objets illisible' => str_replace('/Type /ObjStm /N 1', '/Type /ObjStm /N 1', preg_replace("#stream\n.*\nendstream#s", "stream\nPAS-DU-ZLIB\nendstream", $this->pdfAvecFluxDObjets('<< /Type /Catalog >>'))),
            'type /ObjStm échappé' => str_replace('/Type /ObjStm', '/Type /Obj#53tm', $this->pdfAvecFluxDObjets('<< /Type /Catalog /OpenAction << /S /JavaScript >> >>')),
            'PDF chiffré' => str_replace('trailer <<', 'trailer << /Encrypt 9 0 R', $simple),
        ] as $cas => $pdf) {
            $this->assertTrue($this->refuse($pdf), "Accepté à tort : {$cas}.");
        }
    }

    public function test_le_coordinateur_ne_garde_pas_l_acces_une_fois_l_escalade_traitee(): void
    {
        $labo = $this->laboratoire();
        $compte = $this->compteLaboratoire($labo);
        $coordinateur = $this->personnelActive('coordinateur');
        $bon = $this->bonEnCours($labo, $compte, [$this->examen()]);
        $resultat = $this->deposer($labo, $compte, $bon, critique: true);

        $this->travel(3)->hours();
        Artisan::call('laboratoire:escalader-alertes');
        $escalade = DB::table('alertes_coordination')->sole();
        $this->commePersonnel($coordinateur)->get(route('personnel.coordination.bon', $bon->id))->assertOk();

        // Escalade traitée : plus de lecture du détail ni du PDF.
        $this->commePersonnel($coordinateur)->post(route('personnel.coordination.escalades.traiter', $escalade->id));
        $this->commePersonnel($coordinateur)->get(route('personnel.coordination.bon', $bon->id))->assertForbidden();
        $this->assertNotSame(200, $this->commePersonnel($this->personnelActive('coordinateur'))->get(route('personnel.coordination.bon', $bon->id))->status());

        // Bon validé par le médecin : rien non plus, même avec une escalade restée ouverte par ailleurs.
        DB::table('alertes_coordination')->update(['traitee_le' => null]);
        $this->commeMedecinRdv()->post(route('personnel.medecin.resultats.valider', $resultat->id), ['commentaire' => null]);
        $this->assertNotNull($resultat->fresh()->valide_le);
        $this->commePersonnel($coordinateur)->get(route('personnel.coordination.bon', $bon->id))->assertForbidden();
    }

    public function test_le_coordinateur_ne_valide_pas_un_resultat_depose_depuis_moins_de_48_h(): void
    {
        $labo = $this->laboratoire();
        $compte = $this->compteLaboratoire($labo);
        $coordinateur = $this->personnelActive('coordinateur');
        $bon = $this->bonEnCours($labo, $compte, [$this->examen(), $this->examen('NFS', 'Numération')]);
        $premier = $this->deposer($labo, $compte, $bon);

        $this->travel(49)->hours();
        Artisan::call('laboratoire:escalader-alertes');
        $this->assertSame(1, DB::table('alertes_coordination')->where('type', 'validation_en_retard')->whereNull('traitee_le')->count());

        // Second résultat tout frais sur le même bon : pas de validation en remplacement avant 48 h.
        $second = app(DepotResultats::class)->deposer(
            ActeurLaboratoire::compte($compte, $labo), $bon->fresh(),
            ['valeurs' => [['analyte' => 'Hémoglobine', 'valeur' => '13', 'unite' => 'g/dL', 'normes' => '12 - 16', 'anormal' => false]], 'critique' => false],
        );
        $this->commePersonnel($coordinateur)->post(route('personnel.coordination.resultats.valider', $second->id))->assertForbidden();
        $this->assertNull($second->fresh()->valide_le);

        $this->commePersonnel($coordinateur)->post(route('personnel.coordination.resultats.valider', $premier->id))->assertSessionHasNoErrors();
        $this->assertSame($coordinateur->id, $premier->fresh()->valide_par);
        // Double clic du coordinateur : sans effet, pas d'erreur.
        $this->commePersonnel($coordinateur)->post(route('personnel.coordination.resultats.valider', $premier->id))->assertSessionHasNoErrors();
    }

    public function test_le_patient_ne_remplace_pas_le_laboratoire_choisi(): void
    {
        $designe = $this->laboratoire('Laboratoire désigné');
        $autre = $this->laboratoire('Autre laboratoire');
        $bon = $this->bonPrescrit([$this->examen()], $designe);

        $this->actingAs($this->patientRdv)->post(route('examens.laboratoire', $bon->id), ['laboratoire_id' => $autre->id]);
        $this->assertSame($designe->id, $bon->fresh()->laboratoire_id, 'Le laboratoire désigné par le médecin ne change pas.');

        // Laissé au choix du patient : il choisit une fois, puis ne change plus.
        $libre = $this->bonPrescrit([$this->examen('NFS', 'Numération')]);
        $this->actingAs($this->patientRdv)->post(route('examens.laboratoire', $libre->id), ['laboratoire_id' => $designe->id])->assertSessionHasNoErrors();
        $this->assertSame($designe->id, $libre->fresh()->laboratoire_id);
        $this->actingAs($this->patientRdv)->post(route('examens.laboratoire', $libre->id), ['laboratoire_id' => $autre->id]);
        $this->assertSame($designe->id, $libre->fresh()->laboratoire_id);
    }
}
