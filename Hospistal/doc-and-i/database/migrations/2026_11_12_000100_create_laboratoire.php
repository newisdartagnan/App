<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot laboratoire (PRE-04 à PRE-08, RG-01, RG-10), pour tout laboratoire
 * partenaire (Eyano Centre Biologique ou un autre).
 *
 * - examens : catalogue géré par l'administration (libellé, catégorie,
 *   prix, devise). Aucun prix par défaut : décision du client.
 * - bons_examen : la prescription d'examens d'un médecin pour un rendez-vous,
 *   numérotée (BE-AAAA-NNNNNN), payée d'avance (RG-01), puis transmise au
 *   laboratoire choisi avec un code de retrait à présenter.
 * - resultats_examen : déposés par le laboratoire (valeurs et/ou PDF),
 *   validés par le médecin prescripteur avant que le patient ne les voie
 *   (PRE-07, RG-10) ; validés, ils ne changent plus.
 * - alertes_medecin : portail d'alertes des médecins (résultat critique,
 *   résultat à valider ; plus tard objets connectés).
 * - jetons_laboratoire : accès API d'un laboratoire pour déposer ses
 *   résultats depuis son logiciel (empreinte du jeton seulement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('libelle', 200);
            $table->string('categorie', 20);
            $table->decimal('prix', 12, 2)->nullable();
            $table->char('devise', 3)->default('USD');
            $table->string('preparation', 255)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE examens ADD CONSTRAINT examens_categorie CHECK (categorie IN ('biologie', 'imagerie'))");
        DB::statement("ALTER TABLE examens ADD CONSTRAINT examens_devise CHECK (devise IN ('USD', 'CDF'))");
        DB::statement('ALTER TABLE examens ADD CONSTRAINT examens_prix CHECK (prix IS NULL OR prix > 0)');

        Schema::create('bons_examen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 30)->unique();
            $table->foreignUuid('rendez_vous_id')->constrained('rendez_vous');
            $table->foreignUuid('medecin_id')->constrained('medecins');
            $table->foreignUuid('patient_id')->constrained('users');
            $table->foreignUuid('laboratoire_id')->nullable()->constrained('partenaires');
            $table->string('statut', 20);
            $table->text('renseignements')->nullable();
            $table->date('date_souhaitee')->nullable();
            // Montant total figé à la prescription (somme des lignes), devise unique.
            $table->decimal('montant', 12, 2);
            $table->char('devise', 3);
            // Code à présenter au laboratoire, posé au paiement ; empreinte seulement.
            $table->char('code_retrait_empreinte', 64)->nullable();
            $table->string('cle_idempotence', 64)->unique();
            $table->timestamp('paye_le')->nullable();
            $table->timestamp('transmis_le')->nullable();
            $table->timestamp('annule_le')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
            $table->index(['laboratoire_id', 'statut']);
        });
        DB::statement("ALTER TABLE bons_examen ADD CONSTRAINT bons_statut CHECK (statut IN ('prescrit', 'paye', 'en_cours', 'resultats_deposes', 'valide', 'annule'))");
        DB::statement('ALTER TABLE bons_examen ADD CONSTRAINT bons_montant CHECK (montant > 0)');

        Schema::create('lignes_bon_examen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bon_id')->constrained('bons_examen');
            $table->foreignUuid('examen_id')->constrained('examens');
            $table->unsignedSmallInteger('rang');
            // Libellé et prix recopiés : un changement du catalogue ne touche pas un bon émis.
            $table->string('libelle', 200);
            $table->decimal('prix', 12, 2);
            $table->timestamps();

            $table->unique(['bon_id', 'rang']);
        });

        Schema::create('resultats_examen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bon_id')->constrained('bons_examen');
            $table->foreignUuid('ligne_id')->nullable()->constrained('lignes_bon_examen');
            // Valeurs : [{analyte, valeur, unite, normes, anormal}] ; facultatif si un PDF suffit.
            $table->jsonb('valeurs')->nullable();
            $table->string('pdf_chemin', 255)->nullable();
            $table->boolean('critique')->default(false);
            $table->foreignUuid('depose_par')->constrained('users');
            $table->timestamp('depose_le');
            $table->foreignUuid('valide_par')->nullable()->constrained('users');
            $table->timestamp('valide_le')->nullable();
            $table->text('commentaire_medecin')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE resultats_examen ADD CONSTRAINT resultats_contenu CHECK (valeurs IS NOT NULL OR pdf_chemin IS NOT NULL)');

        Schema::create('alertes_medecin', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medecin_id')->constrained('medecins');
            $table->foreignUuid('patient_id')->constrained('users');
            $table->string('type', 30);
            $table->string('gravite', 10);
            $table->string('objet_type', 40)->nullable();
            $table->uuid('objet_id')->nullable();
            // Message sans donnée de santé détaillée : « Résultat critique à voir ».
            $table->string('message', 255);
            $table->timestamp('lue_le')->nullable();
            $table->timestamp('traitee_le')->nullable();
            $table->timestamp('escaladee_le')->nullable();
            $table->timestamps();

            $table->index(['medecin_id', 'traitee_le']);
        });
        DB::statement("ALTER TABLE alertes_medecin ADD CONSTRAINT alertes_type CHECK (type IN ('resultat_a_valider', 'resultat_critique', 'constante_anormale'))");
        DB::statement("ALTER TABLE alertes_medecin ADD CONSTRAINT alertes_gravite CHECK (gravite IN ('info', 'urgent', 'critique'))");

        Schema::create('jetons_laboratoire', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partenaire_id')->constrained('partenaires');
            $table->char('empreinte', 64)->unique();
            $table->string('libelle', 100);
            $table->foreignUuid('cree_par')->constrained('users');
            $table->timestamp('dernier_usage_le')->nullable();
            $table->timestamp('revoque_le')->nullable();
            $table->timestamps();
        });

        // Paiement d'un bon d'examen (RG-01) : même table que la consultation.
        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignUuid('bon_examen_id')->nullable()->constrained('bons_examen');
        });
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_objet');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_objet CHECK ((objet = 'consultation' AND bon_examen_id IS NULL) OR (objet = 'examen' AND bon_examen_id IS NOT NULL AND rendez_vous_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX paiements_une_tentative_par_bon ON paiements (bon_examen_id) WHERE statut IN ('initie', 'en_attente')");

        // Validés, les résultats ne changent plus ; jamais supprimés.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_resultat_valide() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'resultats_examen : un résultat ne se supprime pas';
                END IF;
                IF OLD.valide_le IS NOT NULL THEN
                    RAISE EXCEPTION 'resultats_examen : résultat validé, non modifiable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER resultats_valides_non_modifiables BEFORE UPDATE OR DELETE ON resultats_examen
                FOR EACH ROW EXECUTE FUNCTION proteger_resultat_valide();
            CREATE TRIGGER resultats_examen_sans_truncate BEFORE TRUNCATE ON resultats_examen
                FOR EACH STATEMENT EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER lignes_bon_non_modifiables BEFORE UPDATE OR DELETE ON lignes_bon_examen
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_objet');
        DB::statement('DROP INDEX IF EXISTS paiements_une_tentative_par_bon');
        Schema::table('paiements', fn (Blueprint $t) => $t->dropConstrainedForeignId('bon_examen_id'));
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_objet CHECK (objet IN ('consultation'))");
        foreach (['jetons_laboratoire', 'alertes_medecin', 'resultats_examen', 'lignes_bon_examen', 'bons_examen', 'examens'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS proteger_resultat_valide() CASCADE');
    }
};
