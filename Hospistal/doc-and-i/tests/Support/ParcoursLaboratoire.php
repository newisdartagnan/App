<?php

namespace Tests\Support;

use App\Enums\MoyenPaiement;
use App\Models\BonExamen;
use App\Models\Examen;
use App\Models\Paiement;
use App\Models\Partenaire;
use App\Models\ResultatExamen;
use App\Models\User;
use App\Services\Laboratoire\ActeurLaboratoire;
use App\Services\Laboratoire\BonsPatient;
use App\Services\Laboratoire\DepotResultats;
use App\Services\Laboratoire\PrescriptionExamens;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\PaiementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Circuit du laboratoire pour les tests, à utiliser avec
 * ParcoursConsultation (consultation du lundi 7 décembre 2026, 09:00),
 * ParcoursPaiement, ParcoursRendezVous et PersonnelDeTest.
 * Laboratoires et examens FICTIFS ; prix fictifs.
 */
trait ParcoursLaboratoire
{
    protected function laboratoire(string $nom = 'Laboratoire de test', bool $actif = true): Partenaire
    {
        return Partenaire::create(['type' => 'laboratoire', 'nom' => $nom, 'commune' => 'Gombe', 'actif' => $actif]);
    }

    protected function compteLaboratoire(Partenaire $labo): User
    {
        $user = $this->personnelActive('laboratoire');
        DB::table('comptes_laboratoire')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'partenaire_id' => $labo->id, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    protected function examen(string $code = 'GLY', string $libelle = 'Glycémie à jeun', ?string $prix = '5.00', string $devise = 'USD', bool $actif = true): Examen
    {
        return Examen::create(['code' => $code, 'libelle' => $libelle, 'categorie' => 'biologie', 'prix' => $prix, 'devise' => $devise, 'actif' => $actif]);
    }

    /** Bon prescrit par le médecin du rendez-vous (par le service). */
    protected function bonPrescrit(array $examens, ?Partenaire $labo = null): BonExamen
    {
        return app(PrescriptionExamens::class)->prescrire($this->medecinRdv->user, $this->rdv, [
            'cle' => (string) Str::uuid(),
            'examens' => array_map(fn (Examen $e) => $e->id, $examens),
            'renseignements' => 'Fièvre depuis trois jours.',
            'laboratoire_id' => $labo?->id,
        ]);
    }

    /** Le patient paie : tentative, puis rappel signé réussi de l'opérateur. */
    protected function payerBon(BonExamen $bon, bool $reussi = true): Paiement
    {
        $tentative = app(PaiementService::class)->initierBon($bon, $bon->patient, MoyenPaiement::MPesa, '0810000001', (string) Str::uuid());
        $paiement = $tentative->paiement->fresh();
        $this->envoyerRappel($this->simulation()->rappelSigne(
            RappelFournisseur::PAIEMENT, $paiement->cle_idempotence, (string) $paiement->reference_fournisseur, $reussi, (string) $paiement->montant, $paiement->devise,
        ))->assertOk();

        return $paiement->fresh();
    }

    protected function codeRetrait(BonExamen $bon): string
    {
        return app(BonsPatient::class)->remettreCode($bon->patient, $bon, true);
    }

    /** Bon payé, patient reçu au laboratoire. */
    protected function bonEnCours(Partenaire $labo, User $compte, array $examens): BonExamen
    {
        $bon = $this->bonPrescrit($examens, $labo);
        $this->payerBon($bon);
        app(DepotResultats::class)->recevoir(ActeurLaboratoire::compte($compte, $labo), $bon->fresh(), $this->codeRetrait($bon->fresh()));

        return $bon->fresh();
    }

    protected function deposer(Partenaire $labo, User $compte, BonExamen $bon, bool $critique = false, ?array $valeurs = null): ResultatExamen
    {
        return app(DepotResultats::class)->deposer(ActeurLaboratoire::compte($compte, $labo), $bon, [
            'valeurs' => $valeurs ?? [['analyte' => 'Glycémie', 'valeur' => '1,05', 'unite' => 'g/L', 'normes' => '0,70 - 1,10', 'anormal' => false]],
            'critique' => $critique,
        ]);
    }

    /** Un PDF minimal, valide. */
    protected function pdfSimple(string $texte = 'Compte rendu'): string
    {
        $flux = "BT /F1 12 Tf 72 720 Td ({$texte}) Tj ET";
        $objets = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($flux)." >>\nstream\n{$flux}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $positions = [];
        foreach ($objets as $i => $objet) {
            $positions[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$objet}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objets) + 1)."\n0000000000 65535 f \n";
        foreach ($positions as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }

        return $pdf.'trailer << /Size '.(count($objets) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
