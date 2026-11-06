<?php

namespace Tests\Feature\Admin;

use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Administration\TableauDeBordService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** ADM-02 : tableau de bord exact sur un jeu de données construit, agrégats seulement. */
class TableauDeBordTest extends TestCase
{
    use ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $admin;

    private Medecin $albert;

    private Medecin $beatrice;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        // Lundi 7 décembre 2026, midi à Kinshasa.
        $this->voyagerAuLundi('12:00');
        $this->admin = $this->personnelActive('admin_fonctionnel');
        $this->albert = $this->medecinNomme('Albert', 'ZOLA');
        $this->beatrice = $this->medecinNomme('Béatrice', 'AMISI');
        $this->patient = $this->patient();
        $this->patient->forceFill(['nom' => 'KABILA', 'prenom' => 'Joséphine'])->save();
        $this->construireJeuDeDonnees();
    }

    private function medecinNomme(string $prenom, string $nom): Medecin
    {
        $medecin = Medecin::factory()->create();
        $medecin->user->forceFill(['prenom' => $prenom, 'nom' => $nom])->save();
        $this->activer($medecin->user);

        return $medecin;
    }

    private function quand(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Africa/Kinshasa');
    }

    /** Rendez-vous dont la demande date de $demandeLe (délai d'attente). */
    private function rdv(Medecin $medecin, string $debut, StatutRendezVous $statut, ?string $demandeLe = null, ?int $taux = null): RendezVous
    {
        $rdv = $this->rendezVousExistant($medecin, $this->patient, $this->quand($debut), 20, $statut,
            $statut === StatutRendezVous::EnAttentePaiement ? $this->quand($debut) : null);
        $rdv->forceFill(['taux_remboursement' => $taux])->save();
        $rdv->demande->forceFill(['motif' => 'Douleurs thoraciques'])->save();
        if ($demandeLe) {
            DB::table('demandes_consultation')->where('id', $rdv->demande_id)->update(['created_at' => $this->quand($demandeLe)]);
        }

        return $rdv;
    }

    private function mouvement(RendezVous $rdv, string $jour, string $montant, string $devise, bool $remboursement = false): void
    {
        $paiement = Paiement::where('rendez_vous_id', $rdv->id)->where('devise', $devise)->first()
            ?? $this->paiementExistant($rdv, StatutPaiement::Reussi, montant: ltrim($montant, '-'), devise: $devise);
        $remboursementId = null;
        if ($remboursement) {
            $remboursementId = (string) Str::uuid();
            DB::table('remboursements')->insert([
                'id' => $remboursementId, 'paiement_id' => $paiement->id, 'montant' => ltrim($montant, '-'), 'devise' => $devise,
                'motif' => 'annulation_patient', 'statut' => 'reussi', 'cle_idempotence' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('operations_caisse')->insert([
            'id' => (string) Str::uuid(), 'type' => $remboursement ? 'remboursement' : 'encaissement',
            'paiement_id' => $paiement->id, 'remboursement_id' => $remboursementId, 'medecin_id' => $rdv->medecin_id,
            'moyen' => 'mpesa', 'montant' => $montant, 'devise' => $devise, 'jour' => $jour,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function construireJeuDeDonnees(): void
    {
        // Dans les 7 derniers jours (1er au 7 décembre).
        $confirme = $this->rdv($this->albert, '2026-12-07 09:00', StatutRendezVous::Confirme, '2026-12-05 09:00');   // 48 h
        $termineA = $this->rdv($this->albert, '2026-12-06 10:00', StatutRendezVous::Termine, '2026-12-06 08:00');     // 2 h
        $this->rdv($this->albert, '2026-12-05 10:00', StatutRendezVous::AnnuleParMedecin, '2026-12-01 10:00', 100);
        $termineB = $this->rdv($this->beatrice, '2026-12-04 10:00', StatutRendezVous::Termine, '2026-12-04 09:00');   // 1 h
        $this->rdv($this->beatrice, '2026-12-03 10:00', StatutRendezVous::AbsencePatient, '2026-12-03 09:00');       // 1 h
        $this->rdv($this->beatrice, '2026-12-04 11:00', StatutRendezVous::AnnuleParPatient, '2026-12-01 09:00', 50); // payé puis annulé
        $this->rdv($this->beatrice, '2026-12-04 12:00', StatutRendezVous::AnnuleParPatient);                        // attente annulée : non payé
        $this->rdv($this->beatrice, '2026-12-05 09:00', StatutRendezVous::Expire);
        $this->rdv($this->beatrice, '2026-12-07 14:00', StatutRendezVous::EnAttentePaiement);
        // Dans les 30 jours seulement.
        $novembre = $this->rdv($this->albert, '2026-11-20 10:00', StatutRendezVous::Termine, '2026-11-19 10:00');     // 24 h
        // Hors période.
        $this->rdv($this->beatrice, '2026-10-01 10:00', StatutRendezVous::Termine, '2026-09-30 10:00');
        $this->rdv($this->albert, '2026-12-09 10:00', StatutRendezVous::Confirme);

        $this->mouvement($termineA, '2026-12-05', '15.00', 'USD');
        $this->mouvement($termineB, '2026-12-06', '10.00', 'USD');
        $this->mouvement($termineB, '2026-12-06', '-5.00', 'USD', remboursement: true);
        $this->mouvement($confirme, '2026-12-07', '28500.00', 'CDF');
        $this->mouvement($novembre, '2026-11-20', '20.00', 'USD');
        $this->mouvement($novembre, '2026-12-08', '30000.00', 'CDF'); // demain : hors période
    }

    private function tableau(string $periode): array
    {
        return $this->commePersonnel($this->admin)->get('/personnel/admin?periode='.$periode)
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_les_rendez_vous_par_statut_sur_sept_jours(): void
    {
        $parStatut = collect($this->tableau('7j')['parStatut'])->pluck('nombre', 'statut')->all();

        $this->assertSame([
            'en_attente_paiement' => 1, 'confirme' => 1, 'expire' => 1, 'annule_patient' => 2, 'annule_medecin' => 1,
            'termine' => 2, 'absent_patient' => 1, 'absent_medecin' => 0,
        ], $parStatut);
    }

    public function test_les_taux_d_annulation_et_d_absence_portent_sur_les_rendez_vous_payes(): void
    {
        $taux = $this->tableau('7j')['taux'];

        $this->assertSame(6, $taux['payes'], 'Confirmé, 2 terminés, absence, annulé médecin, annulé patient après paiement.');
        $this->assertSame(['nombre' => 1, 'pourcentage' => '16,7 %'], $taux['annulationPatient'], 'L\'attente annulée avant paiement ne compte pas.');
        $this->assertSame(['nombre' => 1, 'pourcentage' => '16,7 %'], $taux['annulationMedecin']);
        $this->assertSame(['nombre' => 1, 'pourcentage' => '16,7 %'], $taux['absencePatient']);
        $this->assertSame(['nombre' => 0, 'pourcentage' => '0,0 %'], $taux['absenceMedecin']);
    }

    public function test_le_delai_d_attente_moyen_va_de_la_demande_au_rendez_vous(): void
    {
        $this->assertSame(['nombre' => 4, 'minutes' => 13 * 60, 'libelle' => '13 h 0 min'], $this->tableau('7j')['delaiAttente']);
        // Sur 30 jours, le rendez-vous de novembre (24 h) s'ajoute : (48 + 2 + 1 + 1 + 24) / 5 = 15 h 12 min.
        $this->assertSame(['nombre' => 5, 'minutes' => 912, 'libelle' => '15 h 12 min'], $this->tableau('30j')['delaiAttente']);
    }

    public function test_le_chiffre_d_affaires_est_par_devise_jamais_additionne(): void
    {
        $this->assertSame([
            ['devise' => 'CDF', 'encaisse' => '28 500 CDF', 'rembourse' => '0 CDF', 'net' => '28 500 CDF'],
            ['devise' => 'USD', 'encaisse' => '25,00 USD', 'rembourse' => '5,00 USD', 'net' => '20,00 USD'],
        ], $this->tableau('7j')['chiffreAffaires']);

        $trente = collect($this->tableau('30j')['chiffreAffaires'])->keyBy('devise');
        $this->assertSame('45,00 USD', $trente['USD']['encaisse']);
        $this->assertSame('40,00 USD', $trente['USD']['net']);
        $this->assertSame('28 500 CDF', $trente['CDF']['net'], 'Le mouvement de demain n\'est pas compté.');
    }

    public function test_le_top_des_medecins_compte_consultations_et_annulations_par_le_medecin(): void
    {
        $this->assertSame([
            ['medecin' => 'Dr Albert ZOLA', 'consultations' => 2, 'annulations' => 1],
            ['medecin' => 'Dr Béatrice AMISI', 'consultations' => 1, 'annulations' => 0],
        ], $this->tableau('7j')['topMedecins']);

        $this->assertSame(3, $this->tableau('30j')['topMedecins'][0]['consultations']);
    }

    public function test_le_mois_en_cours_part_du_premier_du_mois(): void
    {
        $props = $this->tableau('mois');

        $this->assertSame('mois', $props['periode']['cle']);
        $this->assertStringContainsString('du 01/12/2026 au 07/12/2026', $props['periode']['libelle']);
        $this->assertSame(1, collect($props['parStatut'])->firstWhere('statut', 'confirme')['nombre']);
        $this->assertSame(2, collect($props['parStatut'])->firstWhere('statut', 'termine')['nombre'], 'Novembre exclu.');
    }

    public function test_une_periode_inconnue_revient_aux_trente_jours(): void
    {
        $this->assertSame('30j', $this->tableau('n-importe-quoi')['periode']['cle']);
        $this->assertCount(3, $this->tableau('30j')['periodes']);
    }

    public function test_la_satisfaction_attend_les_avis_des_patients(): void
    {
        $this->assertSame('Disponible avec les avis des patients (ADM-03, V2).', $this->tableau('7j')['satisfaction']);
    }

    public function test_aucun_nom_de_patient_ni_motif_ne_sort_du_tableau_de_bord(): void
    {
        $json = json_encode($this->tableau('30j'), JSON_UNESCAPED_UNICODE);

        foreach (['KABILA', 'Joséphine', 'Douleurs', 'thoraciques', $this->patient->telephone] as $interdit) {
            $this->assertStringNotContainsString($interdit, $json);
        }
    }

    public function test_sans_donnees_le_tableau_reste_lisible(): void
    {
        $this->travelTo($this->quand('2027-06-15 12:00'));
        $props = $this->tableau('7j');

        $this->assertSame(0, $props['taux']['payes']);
        $this->assertNull($props['taux']['annulationPatient']['pourcentage']);
        $this->assertNull($props['delaiAttente']['libelle']);
        $this->assertSame([], $props['chiffreAffaires']);
        $this->assertSame([], $props['topMedecins']);
    }

    public function test_les_calculs_en_entiers(): void
    {
        $this->assertSame('33,3 %', TableauDeBordService::pourcentage(1, 3));
        $this->assertSame('66,7 %', TableauDeBordService::pourcentage(2, 3));
        $this->assertSame('100,0 %', TableauDeBordService::pourcentage(4, 4));
        $this->assertSame('2 j 3 h', TableauDeBordService::duree(2 * 86400 + 3 * 3600 + 59));
        $this->assertSame('45 min', TableauDeBordService::duree(2700));
    }

    public function test_seul_l_administrateur_voit_le_tableau_de_bord(): void
    {
        foreach (['caisse', 'support'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->get('/personnel/admin?periode=7j')->assertForbidden();
        }
        $this->commePersonnel($this->albert->user)->get('/personnel/admin')->assertForbidden();
        $this->actingAs($this->patient)->get('/personnel/admin')->assertForbidden();
    }
}
