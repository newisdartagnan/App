<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\BonExamen;
use App\Models\Examen;
use App\Models\LigneBonExamen;
use App\Models\Partenaire;
use App\Models\RendezVous;
use App\Models\ResultatExamen;
use App\Services\Laboratoire\AccesLaboratoire;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** Socle du laboratoire : états du bon, immuabilité, accès d'un compte laboratoire. */
class SocleLaboratoireTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
    }

    private function bon(RendezVous $rdv, ?Partenaire $labo = null, bool $paye = false): BonExamen
    {
        $examen = Examen::create(['code' => 'GLY-'.Str::random(4), 'libelle' => 'Glycémie à jeun', 'categorie' => 'biologie', 'prix' => '5.00', 'devise' => 'USD']);
        $bon = new BonExamen;
        $bon->forceFill([
            'numero' => 'BE-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'rendez_vous_id' => $rdv->id, 'medecin_id' => $rdv->medecin_id, 'patient_id' => $rdv->patient_id,
            'laboratoire_id' => $labo?->id, 'statut' => $paye ? StatutBonExamen::Paye : StatutBonExamen::Prescrit,
            'montant' => '5.00', 'devise' => 'USD', 'cle_idempotence' => (string) Str::uuid(), 'paye_le' => $paye ? now() : null,
        ])->save();
        $ligne = new LigneBonExamen;
        $ligne->forceFill(['bon_id' => $bon->id, 'examen_id' => $examen->id, 'rang' => 1, 'libelle' => 'Glycémie à jeun', 'prix' => '5.00'])->save();

        return $bon;
    }

    private function compteLaboratoire(Partenaire $labo)
    {
        $user = $this->personnelActive('laboratoire');
        DB::table('comptes_laboratoire')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'partenaire_id' => $labo->id, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_le_bon_refuse_les_transitions_interdites(): void
    {
        $bon = $this->bon($this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00')));

        $this->assertTrue(StatutBonExamen::Prescrit->peutPasserA(StatutBonExamen::Paye));
        $this->assertFalse(StatutBonExamen::Prescrit->peutPasserA(StatutBonExamen::EnCours), 'Rien n\'est transmis avant paiement (RG-01).');
        $this->assertFalse(StatutBonExamen::ResultatsDeposes->peutPasserA(StatutBonExamen::Annule));

        $this->expectException(LogicException::class);
        $bon->transitionVers(StatutBonExamen::ResultatsDeposes);
    }

    public function test_le_laboratoire_ne_voit_que_les_bons_payes_qui_lui_sont_transmis(): void
    {
        $eyano = Partenaire::create(['type' => 'laboratoire', 'nom' => 'Eyano Centre Biologique']);
        $autre = Partenaire::create(['type' => 'laboratoire', 'nom' => 'Autre laboratoire']);
        $compte = $this->compteLaboratoire($eyano);
        $rdv = $this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00'));
        $acces = app(AccesLaboratoire::class);

        $this->assertTrue($eyano->is($acces->laboratoireDe($compte)));
        $this->assertTrue($acces->peutTraiter($compte, $this->bon($rdv, $eyano, true)));
        $this->assertFalse($acces->peutTraiter($compte, $this->bon($rdv, $eyano, false)), 'Non payé.');
        $this->assertFalse($acces->peutTraiter($compte, $this->bon($rdv, $autre, true)), 'Autre laboratoire.');

        $eyano->update(['actif' => false]);
        $this->assertNull($acces->laboratoireDe($compte), 'Laboratoire désactivé.');
        $this->assertNull($acces->laboratoireDe($this->personnelActive('caisse')));
    }

    public function test_un_resultat_valide_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $labo = Partenaire::create(['type' => 'laboratoire', 'nom' => 'Labo']);
        $compte = $this->compteLaboratoire($labo);
        $bon = $this->bon($this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00')), $labo, true);
        $resultat = new ResultatExamen;
        $resultat->forceFill(['bon_id' => $bon->id, 'valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1,05', 'unite' => 'g/L']], 'depose_par' => $compte->id, 'depose_le' => now()])->save();

        $resultat->forceFill(['critique' => true])->save();
        $resultat->forceFill(['valide_par' => $bon->medecin->user_id, 'valide_le' => now()])->save();

        try {
            $resultat->forceFill(['commentaire_medecin' => 'x'])->save();
            $this->fail('Résultat validé modifié par le modèle.');
        } catch (LogicException) {
        }

        foreach (['UPDATE resultats_examen SET critique = false', 'DELETE FROM resultats_examen', 'UPDATE lignes_bon_examen SET prix = 1'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/non modifiable|ne se supprime pas/', $e->getMessage());
            }
        }
    }

    public function test_un_paiement_d_examen_porte_sur_un_bon_et_pas_sur_un_rendez_vous(): void
    {
        $bon = $this->bon($this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00')));

        $this->expectException(QueryException::class);
        DB::table('paiements')->insert([
            'id' => (string) Str::uuid(), 'patient_id' => $bon->patient_id, 'objet' => 'examen', 'rendez_vous_id' => $bon->rendez_vous_id, 'bon_examen_id' => $bon->id,
            'moyen' => 'carte', 'montant' => '5.00', 'devise' => 'USD', 'montant_reference' => '5.00', 'devise_reference' => 'USD',
            'statut' => 'initie', 'cle_idempotence' => (string) Str::uuid(), 'fournisseur' => 'simulation', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_aucun_prix_d_examen_par_defaut(): void
    {
        $examen = Examen::create(['code' => 'NFS', 'libelle' => 'Numération formule sanguine', 'categorie' => 'biologie']);

        $this->assertNull($examen->fresh()->prix);
    }
}
