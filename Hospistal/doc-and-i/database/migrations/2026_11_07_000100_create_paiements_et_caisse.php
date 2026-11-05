<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot paiement (PAY-01 à PAY-09, RG-01 à RG-03).
 *
 * Garanties portées par la base, en plus des services :
 * - une clé d'idempotence unique par paiement et par remboursement (RG-02) ;
 * - un rappel d'opérateur n'est traité qu'une fois (identifiant d'événement unique) ;
 * - une seule tentative de paiement en cours par rendez-vous ;
 * - le cumul des remboursements ne dépasse jamais le montant payé ;
 * - factures, avoirs et journal de caisse ne se modifient ni ne se suppriment
 *   (PAY-05, PAY-08) : une erreur se corrige par un avoir ;
 * - un remboursement n'est pas validé par celui qui l'a demandé (PAY-07) ;
 * - un reversement n'est pas payé par celui qui l'a préparé (PAY-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        // PAY-01 : taux du jour, saisi par la caisse ou l'administration.
        Schema::create('taux_change', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('jour')->unique();
            $table->decimal('cdf_par_usd', 12, 4);
            $table->foreignUuid('saisi_par')->constrained('users');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE taux_change ADD CONSTRAINT taux_change_positif CHECK (cdf_par_usd > 0)');

        Schema::create('paiements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('users');
            // Examens au lot suivant : la colonne objet dira ce qui est payé.
            $table->foreignUuid('rendez_vous_id')->nullable()->constrained('rendez_vous');
            $table->string('objet', 20);
            $table->string('moyen', 20);
            // Mobile Money : numéro débité, et crédité en cas de remboursement
            // (PAY-07, même moyen de paiement). Jamais dans un journal en clair.
            $table->string('telephone_payeur', 20)->nullable();
            // Montant réellement demandé à l'opérateur, dans la devise choisie.
            $table->decimal('montant', 12, 2);
            $table->char('devise', 3);
            // Prix figé du rendez-vous (RG-13) et taux appliqué s'il y a conversion.
            $table->decimal('montant_reference', 12, 2);
            $table->char('devise_reference', 3);
            $table->foreignUuid('taux_change_id')->nullable()->constrained('taux_change');
            $table->string('statut', 20);
            $table->string('cle_idempotence', 64)->unique();
            $table->string('fournisseur', 30);
            $table->string('reference_fournisseur', 100)->nullable();
            $table->timestamp('confirme_le')->nullable();
            $table->timestamp('echoue_le')->nullable();
            $table->string('motif_echec', 255)->nullable();
            // Remboursements engagés (à valider, validés, en cours, réussis) :
            // tenu par RemboursementService sous verrou ; la base refuse tout
            // dépassement du montant payé.
            $table->decimal('montant_rembourse', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['fournisseur', 'reference_fournisseur']);
            $table->index(['patient_id', 'created_at']);
            $table->index(['statut', 'created_at']);
            $table->index(['rendez_vous_id']);
        });
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_objet CHECK (objet IN ('consultation'))");
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_moyen CHECK (moyen IN ('mpesa', 'orange_money', 'airtel_money', 'afrimoney', 'carte'))");
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_statut CHECK (statut IN ('initie', 'en_attente', 'reussi', 'echoue', 'expire'))");
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_devises CHECK (devise IN ('USD', 'CDF') AND devise_reference IN ('USD', 'CDF'))");
        DB::statement('ALTER TABLE paiements ADD CONSTRAINT paiements_montants CHECK (montant > 0 AND montant_reference > 0)');
        DB::statement('ALTER TABLE paiements ADD CONSTRAINT paiements_rembourse_borne CHECK (montant_rembourse >= 0 AND montant_rembourse <= montant)');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_mobile_money_numero CHECK (moyen = 'carte' OR telephone_payeur IS NOT NULL)");
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_rembourse_si_reussi CHECK (montant_rembourse = 0 OR statut = 'reussi')");
        // Une seule tentative en cours par rendez-vous : un double clic ou deux
        // onglets ne lancent pas deux débits.
        DB::statement("CREATE UNIQUE INDEX paiements_une_tentative_par_rendez_vous ON paiements (rendez_vous_id) WHERE statut IN ('initie', 'en_attente')");

        // Rappels serveur de l'opérateur (PAY-04), gardés après vérification de
        // la signature. Un rappel rejoué est reconnu et ignoré (RG-02).
        Schema::create('evenements_paiement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('fournisseur', 30);
            $table->string('identifiant_evenement', 100);
            $table->string('type', 20);
            $table->foreignUuid('paiement_id')->nullable()->constrained('paiements');
            $table->uuid('remboursement_id')->nullable();
            $table->jsonb('charge_utile');
            $table->timestamp('recu_le');
            $table->timestamp('traite_le')->nullable();
            $table->string('resultat', 255)->nullable();
            $table->timestamps();

            $table->unique(['fournisseur', 'identifiant_evenement']);
        });
        DB::statement("ALTER TABLE evenements_paiement ADD CONSTRAINT evenements_type CHECK (type IN ('paiement', 'remboursement'))");

        Schema::create('remboursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('paiement_id')->constrained('paiements');
            $table->decimal('montant', 12, 2);
            $table->char('devise', 3);
            $table->string('motif', 30);
            $table->string('statut', 20);
            // Remboursement de règle (paiement tardif, en double) : exécuté sans
            // validation humaine, tracé comme tel.
            $table->boolean('automatique')->default(false);
            $table->foreignUuid('demande_par')->nullable()->constrained('users');
            $table->foreignUuid('valide_par')->nullable()->constrained('users');
            $table->timestamp('valide_le')->nullable();
            $table->string('motif_refus', 255)->nullable();
            $table->string('cle_idempotence', 64)->unique();
            $table->string('reference_fournisseur', 100)->nullable();
            $table->timestamp('termine_le')->nullable();
            $table->string('motif_echec', 255)->nullable();
            $table->timestamps();

            $table->index(['statut', 'created_at']);
        });
        DB::statement("ALTER TABLE remboursements ADD CONSTRAINT remboursements_motif CHECK (motif IN ('annulation_patient', 'annulation_medecin', 'paiement_tardif', 'paiement_en_double', 'geste_commercial'))");
        DB::statement("ALTER TABLE remboursements ADD CONSTRAINT remboursements_statut CHECK (statut IN ('a_valider', 'valide', 'en_cours', 'reussi', 'echoue', 'refuse'))");
        DB::statement("ALTER TABLE remboursements ADD CONSTRAINT remboursements_devise CHECK (devise IN ('USD', 'CDF'))");
        DB::statement('ALTER TABLE remboursements ADD CONSTRAINT remboursements_montant CHECK (montant > 0)');
        // PAY-07, quatre yeux : le demandeur ne valide pas sa propre demande.
        DB::statement('ALTER TABLE remboursements ADD CONSTRAINT remboursements_quatre_yeux CHECK (valide_par IS NULL OR demande_par IS NULL OR valide_par <> demande_par)');
        // Un remboursement de règle par paiement et par motif : l'annulation
        // rejouée ne rembourse pas deux fois. Le geste commercial est libre,
        // dans la limite du montant payé.
        DB::statement("CREATE UNIQUE INDEX remboursements_un_par_motif ON remboursements (paiement_id, motif) WHERE statut NOT IN ('refuse', 'echoue') AND motif <> 'geste_commercial'");
        DB::statement('ALTER TABLE evenements_paiement ADD CONSTRAINT evenements_remboursement FOREIGN KEY (remboursement_id) REFERENCES remboursements (id)');

        // PAY-05, PAY-08 : factures et avoirs numérotés sans trou, par année.
        Schema::create('compteurs_numerotation', function (Blueprint $table) {
            $table->string('prefixe', 10);
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('dernier')->default(0);
            $table->primary(['prefixe', 'annee']);
        });

        Schema::create('factures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 30)->unique();
            $table->string('type', 10);
            $table->foreignUuid('patient_id')->constrained('users');
            $table->foreignUuid('medecin_id')->nullable()->constrained('medecins');
            $table->foreignUuid('paiement_id')->constrained('paiements');
            $table->foreignUuid('remboursement_id')->nullable()->constrained('remboursements');
            $table->uuid('facture_origine_id')->nullable();
            $table->string('libelle', 255);
            $table->decimal('montant', 12, 2);
            $table->char('devise', 3);
            $table->string('moyen', 20);
            $table->timestamp('emise_le');
            $table->timestamps();

            $table->index(['patient_id', 'emise_le']);
        });
        // Clé vers la table elle-même : posée une fois la clé primaire créée.
        DB::statement('ALTER TABLE factures ADD CONSTRAINT factures_origine FOREIGN KEY (facture_origine_id) REFERENCES factures (id)');
        DB::statement("ALTER TABLE factures ADD CONSTRAINT factures_type CHECK ((type = 'facture' AND facture_origine_id IS NULL AND remboursement_id IS NULL) OR (type = 'avoir' AND facture_origine_id IS NOT NULL))");
        DB::statement('ALTER TABLE factures ADD CONSTRAINT factures_montant CHECK (montant > 0)');
        DB::statement("CREATE UNIQUE INDEX factures_une_par_paiement ON factures (paiement_id) WHERE type = 'facture'");
        DB::statement("CREATE UNIQUE INDEX factures_un_avoir_par_remboursement ON factures (remboursement_id) WHERE type = 'avoir'");

        // PAY-09 : journal de caisse, une ligne par mouvement d'argent.
        Schema::create('operations_caisse', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20);
            $table->foreignUuid('paiement_id')->constrained('paiements');
            $table->foreignUuid('remboursement_id')->nullable()->constrained('remboursements');
            $table->foreignUuid('medecin_id')->nullable()->constrained('medecins');
            $table->string('moyen', 20);
            // Positif pour un encaissement, négatif pour un remboursement.
            $table->decimal('montant', 12, 2);
            $table->char('devise', 3);
            // Jour comptable, heure de Kinshasa.
            $table->date('jour');
            $table->timestamps();

            $table->index(['jour', 'moyen']);
            $table->index(['medecin_id', 'jour']);
        });
        DB::statement("ALTER TABLE operations_caisse ADD CONSTRAINT operations_type CHECK ((type = 'encaissement' AND montant > 0 AND remboursement_id IS NULL) OR (type = 'remboursement' AND montant < 0 AND remboursement_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX operations_un_encaissement_par_paiement ON operations_caisse (paiement_id) WHERE type = 'encaissement'");
        DB::statement("CREATE UNIQUE INDEX operations_une_sortie_par_remboursement ON operations_caisse (remboursement_id) WHERE type = 'remboursement'");

        // Pièces comptables et journal : ni modification ni suppression.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuser_modification_comptable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% : pièce comptable non modifiable (corriger par un avoir)', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER factures_non_modifiables BEFORE UPDATE OR DELETE ON factures
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_comptable();
            CREATE TRIGGER operations_caisse_non_modifiables BEFORE UPDATE OR DELETE ON operations_caisse
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_comptable();
        SQL);

        // PAY-06 : relevés de l'agrégateur et écarts du rapprochement quotidien.
        Schema::create('releves_fournisseur', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('fournisseur', 30);
            $table->date('jour');
            $table->string('type', 20);
            $table->string('reference_fournisseur', 100);
            $table->string('reference_marchand', 100)->nullable();
            $table->decimal('montant', 12, 2);
            $table->char('devise', 3);
            $table->string('statut', 20);
            $table->timestamps();

            $table->unique(['fournisseur', 'type', 'reference_fournisseur']);
            $table->index(['fournisseur', 'jour']);
        });

        Schema::create('ecarts_rapprochement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('jour');
            $table->string('fournisseur', 30);
            $table->string('nature', 30);
            $table->foreignUuid('paiement_id')->nullable()->constrained('paiements');
            $table->foreignUuid('remboursement_id')->nullable()->constrained('remboursements');
            $table->string('reference_fournisseur', 100)->nullable();
            $table->decimal('montant_attendu', 12, 2)->nullable();
            $table->decimal('montant_releve', 12, 2)->nullable();
            $table->char('devise', 3)->nullable();
            $table->string('statut', 20)->default('ouvert');
            $table->foreignUuid('traite_par')->nullable()->constrained('users');
            $table->timestamp('traite_le')->nullable();
            $table->string('commentaire', 500)->nullable();
            $table->timestamps();

            $table->index(['statut', 'jour']);
        });
        DB::statement("ALTER TABLE ecarts_rapprochement ADD CONSTRAINT ecarts_nature CHECK (nature IN ('absent_chez_nous', 'absent_du_releve', 'montant_different', 'statut_different'))");
        DB::statement("ALTER TABLE ecarts_rapprochement ADD CONSTRAINT ecarts_statut CHECK (statut IN ('ouvert', 'traite'))");

        // PAY-09 : reversement des honoraires aux médecins, par période.
        Schema::create('reversements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medecin_id')->constrained('medecins');
            $table->date('periode_debut');
            $table->date('periode_fin');
            $table->char('devise', 3);
            $table->decimal('montant_brut', 12, 2);
            $table->decimal('commission', 12, 2);
            $table->decimal('montant_net', 12, 2);
            $table->string('statut', 20);
            $table->foreignUuid('prepare_par')->constrained('users');
            $table->foreignUuid('paye_par')->nullable()->constrained('users');
            $table->timestamp('paye_le')->nullable();
            $table->string('reference_virement', 100)->nullable();
            $table->timestamps();

            $table->unique(['medecin_id', 'periode_debut', 'devise']);
        });
        DB::statement("ALTER TABLE reversements ADD CONSTRAINT reversements_statut CHECK (statut IN ('prepare', 'paye'))");
        DB::statement('ALTER TABLE reversements ADD CONSTRAINT reversements_periode CHECK (periode_fin >= periode_debut)');
        DB::statement('ALTER TABLE reversements ADD CONSTRAINT reversements_montants CHECK (montant_brut >= 0 AND commission >= 0 AND montant_net = montant_brut - commission)');
        DB::statement('ALTER TABLE reversements ADD CONSTRAINT reversements_quatre_yeux CHECK (paye_par IS NULL OR paye_par <> prepare_par)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reversements');
        Schema::dropIfExists('ecarts_rapprochement');
        Schema::dropIfExists('releves_fournisseur');
        Schema::dropIfExists('operations_caisse');
        Schema::dropIfExists('factures');
        DB::unprepared('DROP FUNCTION IF EXISTS refuser_modification_comptable() CASCADE');
        Schema::dropIfExists('compteurs_numerotation');
        DB::statement('ALTER TABLE IF EXISTS evenements_paiement DROP CONSTRAINT IF EXISTS evenements_remboursement');
        Schema::dropIfExists('remboursements');
        Schema::dropIfExists('evenements_paiement');
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('taux_change');
    }
};
