<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot dossier médical et ordonnance (DOS-01 à DOS-04, PRE-01 à PRE-03,
 * RG-11 signature).
 *
 * - antecedents : antécédents, allergies, traitements en cours, déclarés
 *   par le patient ou saisis par un médecin qui le suit. Jamais supprimés :
 *   retirés (date et auteur), l'historique reste lisible.
 * - constantes : poids, taille, tension… déclarées, avec leur date.
 * - dossiers_patients : groupe sanguin. AUCUNE valeur par défaut : vide
 *   veut dire « inconnu » (leçon de l'audit de dpi-rdc).
 * - notes_consultation : note structurée du médecin, modifiable tant
 *   qu'elle n'est pas signée ; signée, plus rien ne bouge (déclencheur) ;
 *   une correction est un avenant daté et signé (RG-11).
 * - ordonnances et lignes : numérotées sans trou, signées, non
 *   modifiables ; un code de vérification aléatoire pour le QR code du
 *   pharmacien (PRE-03).
 * - demandes_rectification : le patient demande la correction de son
 *   dossier (DOS-04) ; un médecin y répond, par avenant si besoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers_patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->unique()->constrained('users');
            $table->string('groupe_sanguin', 3)->nullable();
            $table->foreignUuid('groupe_sanguin_par')->nullable()->constrained('users');
            $table->timestamp('groupe_sanguin_le')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE dossiers_patients ADD CONSTRAINT dossiers_groupe_sanguin CHECK (groupe_sanguin IS NULL OR groupe_sanguin IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'))");

        Schema::create('antecedents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('users');
            $table->string('categorie', 30);
            $table->string('libelle', 200);
            $table->string('precision', 500)->nullable();
            $table->foreignUuid('saisi_par')->constrained('users');
            $table->string('source', 10);
            $table->timestamp('retire_le')->nullable();
            $table->foreignUuid('retire_par')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['patient_id', 'categorie']);
        });
        DB::statement("ALTER TABLE antecedents ADD CONSTRAINT antecedents_categorie CHECK (categorie IN ('medical', 'chirurgical', 'familial', 'allergie', 'traitement'))");
        DB::statement("ALTER TABLE antecedents ADD CONSTRAINT antecedents_source CHECK (source IN ('patient', 'medecin'))");

        Schema::create('constantes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('users');
            $table->string('type', 30);
            $table->decimal('valeur', 8, 2);
            // Tension : deuxième valeur (diastolique).
            $table->decimal('valeur2', 8, 2)->nullable();
            $table->timestamp('mesure_le');
            $table->foreignUuid('saisi_par')->constrained('users');
            $table->timestamps();

            $table->index(['patient_id', 'type', 'mesure_le']);
        });
        DB::statement("ALTER TABLE constantes ADD CONSTRAINT constantes_type CHECK (type IN ('poids', 'taille', 'tension', 'temperature', 'glycemie', 'frequence_cardiaque'))");

        Schema::create('notes_consultation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rendez_vous_id')->unique()->constrained('rendez_vous');
            $table->foreignUuid('medecin_id')->constrained('medecins');
            $table->foreignUuid('patient_id')->constrained('users');
            $table->text('motif')->nullable();
            $table->text('examen')->nullable();
            $table->text('hypotheses')->nullable();
            // Diagnostic CIM-11 : code et libellé recopiés (le référentiel est à fournir).
            $table->string('diagnostic_code', 20)->nullable();
            $table->string('diagnostic_libelle', 255)->nullable();
            $table->text('conduite')->nullable();
            $table->timestamp('signee_le')->nullable();
            // SHA-256 du contenu signé : toute altération en base se voit.
            $table->char('empreinte', 64)->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'signee_le']);
        });

        Schema::create('avenants_note', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('note_id')->constrained('notes_consultation');
            $table->foreignUuid('auteur_id')->constrained('users');
            $table->text('contenu');
            $table->timestamp('signe_le');
            $table->char('empreinte', 64);
            $table->timestamps();
        });

        Schema::create('ordonnances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 30)->unique();
            $table->foreignUuid('rendez_vous_id')->constrained('rendez_vous');
            $table->foreignUuid('medecin_id')->constrained('medecins');
            $table->foreignUuid('patient_id')->constrained('users');
            $table->text('consignes')->nullable();
            $table->timestamp('signee_le');
            $table->char('empreinte', 64);
            // PRE-03 : code aléatoire du QR code ; le pharmacien vérifie l'authenticité.
            $table->string('code_verification', 32)->unique();
            $table->timestamps();

            $table->index(['patient_id', 'signee_le']);
        });

        Schema::create('lignes_ordonnance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ordonnance_id')->constrained('ordonnances');
            $table->unsignedSmallInteger('rang');
            $table->string('medicament', 200);
            $table->string('dosage', 100);
            $table->string('voie', 50);
            $table->string('posologie', 255);
            $table->string('duree', 100);
            $table->string('consignes', 255)->nullable();
            $table->timestamps();

            $table->unique(['ordonnance_id', 'rang']);
        });

        Schema::create('demandes_rectification', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('users');
            $table->text('demande');
            $table->string('statut', 20)->default('ouverte');
            $table->text('reponse')->nullable();
            $table->foreignUuid('traitee_par')->nullable()->constrained('users');
            $table->timestamp('traitee_le')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE demandes_rectification ADD CONSTRAINT rectification_statut CHECK (statut IN ('ouverte', 'acceptee', 'refusee'))");

        DB::unprepared(<<<'SQL'
            -- RG-11 : une note signée ne change plus ; seule la signature d'une note
            -- encore brouillon est permise.
            CREATE OR REPLACE FUNCTION proteger_note_signee() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'notes_consultation : une note ne se supprime pas';
                END IF;
                IF OLD.signee_le IS NOT NULL THEN
                    RAISE EXCEPTION 'notes_consultation : note signée, non modifiable (avenant)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER notes_signees_non_modifiables BEFORE UPDATE OR DELETE ON notes_consultation
                FOR EACH ROW EXECUTE FUNCTION proteger_note_signee();

            CREATE TRIGGER avenants_non_modifiables BEFORE UPDATE OR DELETE ON avenants_note
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER ordonnances_non_modifiables BEFORE UPDATE OR DELETE ON ordonnances
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER lignes_ordonnance_non_modifiables BEFORE UPDATE OR DELETE ON lignes_ordonnance
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER antecedents_sans_suppression BEFORE DELETE ON antecedents
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER constantes_sans_suppression BEFORE DELETE ON constantes
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
        SQL);
        foreach (['notes_consultation', 'avenants_note', 'ordonnances', 'lignes_ordonnance', 'antecedents', 'constantes'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_sans_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION refuser_modification_dossier()");
        }
    }

    public function down(): void
    {
        foreach (['demandes_rectification', 'lignes_ordonnance', 'ordonnances', 'avenants_note', 'notes_consultation', 'constantes', 'antecedents', 'dossiers_patients'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS proteger_note_signee() CASCADE');
    }
};
