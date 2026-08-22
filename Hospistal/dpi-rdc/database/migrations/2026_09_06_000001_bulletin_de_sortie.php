<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sortie du patient, enfin écrite quelque part.
 *
 * Jusqu'ici la sortie ne laissait qu'un mode — « guéri », « amélioré » — et
 * une date. Le service de sortie acceptait bien des observations, mais les
 * jetait sans les enregistrer. Le patient repartait donc sans document, et le
 * médecin traitant qui le reçoit ensuite n'avait rien à lire : ni ce dont il
 * a été traité, ni ce qu'il faut surveiller, ni quand le revoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->text('observations_sortie')->nullable()->after('mode_sortie');
            $table->text('recommandations_sortie')->nullable()->after('observations_sortie');
            $table->date('rendez_vous_controle')->nullable()->after('recommandations_sortie');
            $table->foreignUuid('sortie_par')->nullable()->after('rendez_vous_controle')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sortie_par');
            $table->dropColumn(['observations_sortie', 'recommandations_sortie', 'rendez_vous_controle']);
        });
    }
};
