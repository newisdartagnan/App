<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revue de sécurité du lot administration.
 * - users.version_session : augmentée à chaque désactivation ; une session
 *   ouverte avant ne sert plus, même si le compte est réactivé ensuite.
 * - Journal d'administration et pièces comptables : TRUNCATE refusé aussi
 *   (le déclencheur par ligne ne le voyait pas).
 * - rendez_vous(debut) : le tableau de bord filtre par période.
 */
return new class extends Migration
{
    private const TABLES_PROTEGEES = [
        'journal_administration' => 'refuser_modification_journal',
        'factures' => 'refuser_modification_comptable',
        'operations_caisse' => 'refuser_modification_comptable',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('version_session')->default(0);
        });

        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->index('debut');
        });

        foreach (self::TABLES_PROTEGEES as $table => $fonction) {
            DB::unprepared("CREATE TRIGGER {$table}_sans_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION {$fonction}()");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES_PROTEGEES) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_sans_truncate ON {$table}");
        }
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropIndex(['debut']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('version_session');
        });
    }
};
