<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revue de sécurité du lot téléconsultation : ouvrir la page ne prouve pas
 * une consultation (un médecin passait, repartait, et était payé).
 *
 * - La présence vient désormais de l'appel lui-même : chaque navigateur
 *   connecté (WebRTC « connected ») envoie un battement ; la salle n'est
 *   « en cours » qu'à la première minute où les deux sont connectés
 *   ensemble, et secondes_ensemble mesure le temps réellement partagé.
 * - « interrompue » (RG-07) : consultation commencée mais trop courte ou
 *   coupée pour de bon ; le patient est remboursé (motif
 *   consultation_interrompue) et peut reprendre un créneau.
 * - reconnexions en entier (un smallint saturait à 32 767 rechargements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->timestamp('presence_patient_le')->nullable();
            $table->timestamp('presence_medecin_le')->nullable();
            $table->timestamp('ensemble_le')->nullable();
            $table->unsignedInteger('secondes_ensemble')->default(0);
            $table->timestamp('mesure_ensemble_le')->nullable();
        });
        DB::statement('ALTER TABLE consultations ALTER COLUMN reconnexions TYPE integer');

        DB::statement('ALTER TABLE consultations DROP CONSTRAINT consultations_statut');
        DB::statement("ALTER TABLE consultations ADD CONSTRAINT consultations_statut CHECK (statut IN ('en_attente', 'en_cours', 'terminee', 'interrompue', 'patient_absent', 'medecin_absent'))");

        DB::statement('ALTER TABLE remboursements DROP CONSTRAINT remboursements_motif');
        DB::statement("ALTER TABLE remboursements ADD CONSTRAINT remboursements_motif CHECK (motif IN ('annulation_patient', 'annulation_medecin', 'absence_medecin', 'consultation_interrompue', 'paiement_tardif', 'paiement_en_double', 'geste_commercial'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE remboursements DROP CONSTRAINT remboursements_motif');
        DB::statement("ALTER TABLE remboursements ADD CONSTRAINT remboursements_motif CHECK (motif IN ('annulation_patient', 'annulation_medecin', 'absence_medecin', 'paiement_tardif', 'paiement_en_double', 'geste_commercial'))");
        DB::statement('ALTER TABLE consultations DROP CONSTRAINT consultations_statut');
        DB::statement("ALTER TABLE consultations ADD CONSTRAINT consultations_statut CHECK (statut IN ('en_attente', 'en_cours', 'terminee', 'patient_absent', 'medecin_absent'))");
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['presence_patient_le', 'presence_medecin_le', 'ensemble_le', 'secondes_ensemble', 'mesure_ensemble_le']);
        });
    }
};
