<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La notification suit le dossier du patient, plus le seul prescripteur.
 *
 * Un résultat de laboratoire n'était annoncé qu'au médecin qui l'avait
 * demandé. Mais celui-ci finit sa garde à six heures, part en congé, ou
 * consulte à l'autre bout de l'hôpital : pendant ce temps le résultat
 * dort dans une boîte que personne d'autre n'ouvre, et le confrère qui
 * reprend le patient ne sait même pas qu'il existe.
 *
 * Une valeur critique — une hémoglobine à 4, une kaliémie à 7 — n'appartient
 * pas à un médecin : elle appartient au dossier du patient. Le prescripteur
 * reste nommé, parce qu'il faut savoir qui a demandé quoi, mais l'annonce
 * devient lisible par tout médecin, et se retrouve depuis le dossier.
 *
 * On garde trace de qui l'a lue : quand un confrère prend un résultat en
 * charge, les autres doivent voir que quelqu'un s'en occupe plutôt que de
 * le traiter trois fois — ou de croire que personne ne l'a vu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_internes', function (Blueprint $table) {
            $table->uuid('patient_id')->nullable()->after('code_reference');
            $table->uuid('lu_par')->nullable()->after('read_at');

            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
            $table->foreign('lu_par')->references('id')->on('users')->nullOnDelete();
            $table->index('patient_id');
        });

        // Les annonces déjà en boîte doivent, elles aussi, se retrouver
        // depuis le dossier : sans reprise, seules les nouvelles y seraient.
        $this->rattacherLexistant('examens_laboratoire', 'App\Models\ExamenLaboratoire');
        $this->rattacherLexistant('prescriptions', 'App\Models\Prescription');
        $this->rattacherLexistant('demandes_sang', 'App\Models\DemandeSang');
    }

    public function down(): void
    {
        Schema::table('notifications_internes', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['lu_par']);
            $table->dropIndex(['patient_id']);
            $table->dropColumn(['patient_id', 'lu_par']);
        });
    }

    /**
     * Retrouve le patient d'une notification par la pièce qu'elle référence.
     */
    private function rattacherLexistant(string $table, string $modele): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'patient_id')) {
            return;
        }

        DB::table('notifications_internes')
            ->where('reference_type', $modele)
            ->whereNull('patient_id')
            ->update([
                'patient_id' => DB::raw(
                    "(SELECT patient_id FROM {$table} WHERE {$table}.id = notifications_internes.reference_id)"
                ),
            ]);
    }
};
