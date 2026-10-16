<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * L'avoir, et ce qui le rend nécessaire : une facture émise ne se touche plus.
 *
 * Jusqu'ici, une facture fausse — mauvaise convention, examen non réalisé,
 * patient enregistré comme privé alors qu'il était assuré — n'avait aucune
 * sortie propre : on la laissait vivre, ou on la modifiait en base. Les
 * deux sont des fautes de caisse. Une pièce remise au patient ou envoyée à
 * l'assureur ne change plus ; on la corrige par une autre pièce, numérotée,
 * motivée et signée : l'avoir.
 *
 * L'avoir annule tout ou partie d'une facture. Ce que le patient avait déjà
 * réglé au-delà de ce qu'il doit encore devient un crédit à son nom — rendu
 * en espèces ou imputé sur sa prochaine facture, comme un acompte. Ce que
 * l'assureur ne doit plus est noté contre le bordereau qui le lui réclamait.
 *
 * La correction se fait par un rôle à part. Le caissier encaisse ; il
 * n'annule pas ce qu'il a encaissé. Le chef de facturation et de
 * recouvrement, la direction et l'administrateur le peuvent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avoirs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 30)->unique();
            $table->foreignUuid('facture_id')->constrained('factures');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->foreignUuid('establishment_id')->constrained('establishments');
            // annulation (toute la facture), partiel (certaines lignes),
            // remise (un geste consenti après coup).
            $table->string('type', 20);
            $table->string('motif_categorie', 30);
            $table->text('motif');
            $table->string('devise', 5)->default('CDF');
            $table->decimal('taux_change', 12, 4)->default(1);
            $table->decimal('montant_total', 12, 2);
            $table->decimal('montant_patient', 12, 2)->default(0);
            $table->decimal('montant_assurance', 12, 2)->default(0);
            // Ce que le patient avait réglé en trop une fois l'avoir passé.
            $table->decimal('credit_patient', 12, 2)->default(0);
            $table->foreignUuid('caution_id')->nullable()->constrained('cautions')->nullOnDelete();
            $table->boolean('liberer_prestations')->default(false);
            $table->json('prestations_liberees')->nullable();
            $table->foreignUuid('facture_convention_id')->nullable()
                ->constrained('factures_convention')->nullOnDelete();
            $table->foreignUuid('emis_par')->constrained('users');
            $table->timestamp('date_avoir');
            $table->timestamp('refacture_le')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamps();

            $table->index('facture_id');
            $table->index(['establishment_id', 'date_avoir']);
        });

        Schema::create('lignes_avoir', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('avoir_id')->constrained('avoirs')->cascadeOnDelete();
            $table->foreignUuid('ligne_facture_id')->nullable()
                ->constrained('lignes_facture')->nullOnDelete();
            $table->string('libelle');
            $table->decimal('montant', 12, 2);
            $table->decimal('montant_patient', 12, 2)->default(0);
            $table->decimal('montant_assurance', 12, 2)->default(0);
            $table->timestamp('created_at')->nullable();
        });

        // La facture réémise après un avoir garde la trace de celle qu'elle
        // remplace : l'assureur qui reçoit les deux doit pouvoir les relier.
        Schema::table('factures', function (Blueprint $table) {
            $table->foreignUuid('avoir_origine_id')->nullable()->after('prescription_id')
                ->constrained('avoirs')->nullOnDelete();
        });

        // Le crédit né d'un avoir se range avec les acomptes : il s'impute
        // de la même façon et se rend de la même façon. Une facture d'examen
        // externe n'a pas toujours de séjour — son crédit non plus.
        Schema::table('cautions', function (Blueprint $table) {
            $table->uuid('visit_id')->nullable()->change();
            $table->foreignUuid('avoir_id')->nullable()->after('patient_id')
                ->constrained('avoirs')->nullOnDelete();
        });

        // Le journal d'une facture rassemble des écritures de plusieurs
        // tables — ses lignes, ses paiements, ses acomptes, ses avoirs. Les
        // rattacher à la facture dès l'écriture évite de les rechercher une
        // à une, et survit à une ligne qui aurait disparu.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->uuid('facture_id')->nullable()->after('record_id');
            $table->index(['facture_id', 'created_at']);
        });

        $this->droits();
    }

    /**
     * Le droit d'annuler, et le rôle qui l'exerce au quotidien.
     *
     * Le seeder ne tourne qu'à l'installation : sans cette reprise, un
     * établissement déjà en service n'aurait ni le droit ni le rôle. On en
     * profite pour reporter les droits ajoutés au seeder depuis — sans en
     * retirer aucun : une installation a pu en accorder d'autres.
     */
    private function droits(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (RolePermissionSeeder::PERMISSIONS as $nom) {
            Permission::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }

        foreach (RolePermissionSeeder::rolesEtDroits() as $role => $droits) {
            $modele = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $manquants = array_values(array_diff($droits, $modele->permissions->pluck('name')->all()));

            if ($manquants !== []) {
                $modele->givePermissionTo($manquants);
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['facture_id', 'created_at']);
            $table->dropColumn('facture_id');
        });

        Schema::table('cautions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avoir_id');
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avoir_origine_id');
        });

        Schema::dropIfExists('lignes_avoir');
        Schema::dropIfExists('avoirs');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::where('name', 'chef_facturation')->where('guard_name', 'web')->delete();
        Permission::where('name', 'facture.annuler')->where('guard_name', 'web')->delete();
    }
};
