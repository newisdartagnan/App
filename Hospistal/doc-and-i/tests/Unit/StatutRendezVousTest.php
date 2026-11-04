<?php

namespace Tests\Unit;

use App\Enums\StatutRendezVous as S;
use PHPUnit\Framework\TestCase;

class StatutRendezVousTest extends TestCase
{
    public function test_les_transitions_autorisees_sont_celles_du_cycle_de_vie(): void
    {
        $this->assertTrue(S::EnAttentePaiement->peutPasserA(S::Confirme));
        $this->assertTrue(S::EnAttentePaiement->peutPasserA(S::Expire));
        $this->assertTrue(S::Confirme->peutPasserA(S::AnnuleParMedecin));
        $this->assertTrue(S::Confirme->peutPasserA(S::Termine));
    }

    public function test_un_rendez_vous_final_ne_bouge_plus(): void
    {
        foreach ([S::Expire, S::AnnuleParPatient, S::AnnuleParMedecin, S::Termine, S::AbsencePatient, S::AbsenceMedecin] as $final) {
            $this->assertTrue($final->estFinal());
            foreach (S::cases() as $cible) {
                $this->assertFalse($final->peutPasserA($cible), "{$final->value} → {$cible->value} devrait être refusé");
            }
        }
    }

    public function test_un_rendez_vous_non_paye_ne_peut_pas_etre_termine_ni_annule_par_le_medecin(): void
    {
        $this->assertFalse(S::EnAttentePaiement->peutPasserA(S::Termine));
        $this->assertFalse(S::EnAttentePaiement->peutPasserA(S::AnnuleParMedecin));
        $this->assertFalse(S::Expire->peutPasserA(S::Confirme), 'Un paiement arrivé après expiration ne ressuscite pas le créneau (RG-03).');
    }

    public function test_seuls_l_attente_et_le_confirme_occupent_le_creneau(): void
    {
        $this->assertEqualsCanonicalizing(['en_attente_paiement', 'confirme'], S::occupants());
    }
}
