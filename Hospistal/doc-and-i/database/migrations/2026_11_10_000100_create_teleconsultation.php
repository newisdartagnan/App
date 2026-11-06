<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot téléconsultation (TLC-01 à TLC-06, RG-06, RG-07).
 *
 * - consultations : la salle virtuelle d'un rendez-vous confirmé, une seule.
 * - signaux_consultation : messages de mise en relation WebRTC (offre,
 *   réponse, candidats réseau), relayés par le serveur entre les deux
 *   participants et purgés après la consultation. Ni son ni image ne passent
 *   par nos serveurs : le flux va d'un navigateur à l'autre, chiffré (DTLS-SRTP),
 *   ou par le relais TURN, qui ne le déchiffre pas.
 * - messages_consultation : messagerie patient–médecin rattachée à la
 *   consultation (TLC-03), donnée de santé : ni modification ni suppression.
 * - Aucun enregistrement audio ou vidéo (TLC-06) : aucune table pour cela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rendez_vous_id')->unique()->constrained('rendez_vous');
            $table->string('statut', 20);
            $table->timestamp('patient_rejoint_le')->nullable();
            $table->timestamp('medecin_rejoint_le')->nullable();
            // Début réel : les deux participants ont été présents ensemble.
            $table->timestamp('debut_le')->nullable();
            $table->timestamp('fin_le')->nullable();
            // Coupures (RG-07, TLC-04) : comptées, la consultation reprend.
            $table->unsignedSmallInteger('reconnexions')->default(0);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE consultations ADD CONSTRAINT consultations_statut CHECK (statut IN ('en_attente', 'en_cours', 'terminee', 'patient_absent', 'medecin_absent'))");

        Schema::create('signaux_consultation', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignUuid('emetteur_id')->constrained('users');
            // Rôle du destinataire : chaque participant ne lit que ce qui lui est adressé.
            $table->string('destinataire', 10);
            $table->string('type', 20);
            $table->jsonb('charge');
            $table->timestamp('cree_le');

            $table->index(['consultation_id', 'destinataire', 'id']);
        });
        DB::statement("ALTER TABLE signaux_consultation ADD CONSTRAINT signaux_destinataire CHECK (destinataire IN ('patient', 'medecin'))");
        DB::statement("ALTER TABLE signaux_consultation ADD CONSTRAINT signaux_type CHECK (type IN ('offre', 'reponse', 'candidat', 'raccroche', 'audio_seul'))");

        Schema::create('messages_consultation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->constrained('consultations');
            $table->foreignUuid('auteur_id')->constrained('users');
            $table->text('texte')->nullable();
            // Photo jointe : disque privé, servie par une route qui vérifie l'accès.
            $table->string('piece_chemin', 255)->nullable();
            $table->string('piece_type', 100)->nullable();
            $table->unsignedInteger('piece_taille')->nullable();
            $table->timestamp('cree_le');

            $table->index(['consultation_id', 'cree_le']);
        });
        DB::statement('ALTER TABLE messages_consultation ADD CONSTRAINT messages_contenu CHECK (texte IS NOT NULL OR piece_chemin IS NOT NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuser_modification_dossier() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% : donnée du dossier non modifiable', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER messages_consultation_non_modifiables BEFORE UPDATE OR DELETE ON messages_consultation
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER messages_consultation_sans_truncate BEFORE TRUNCATE ON messages_consultation
                FOR EACH STATEMENT EXECUTE FUNCTION refuser_modification_dossier();
        SQL);

        // RG-06 : médecin absent → remboursement intégral, automatique.
        DB::statement('ALTER TABLE remboursements DROP CONSTRAINT remboursements_motif');
        DB::statement("ALTER TABLE remboursements ADD CONSTRAINT remboursements_motif CHECK (motif IN ('annulation_patient', 'annulation_medecin', 'absence_medecin', 'paiement_tardif', 'paiement_en_double', 'geste_commercial'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE remboursements DROP CONSTRAINT remboursements_motif');
        DB::statement("ALTER TABLE remboursements ADD CONSTRAINT remboursements_motif CHECK (motif IN ('annulation_patient', 'annulation_medecin', 'paiement_tardif', 'paiement_en_double', 'geste_commercial'))");
        Schema::dropIfExists('messages_consultation');
        DB::unprepared('DROP FUNCTION IF EXISTS refuser_modification_dossier() CASCADE');
        Schema::dropIfExists('signaux_consultation');
        Schema::dropIfExists('consultations');
    }
};
