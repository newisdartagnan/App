<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que devient le patient à la fin de la consultation.
 *
 * La consultation s'arrêtait sur une conduite à tenir écrite en toutes
 * lettres. Personne ne savait, en la lisant, si le patient rentrait chez lui
 * ou s'il fallait lui trouver un lit — et le service d'hospitalisation
 * l'apprenait quand le patient se présentait à sa porte, s'il se présentait.
 *
 * Le médecin dit désormais où va le patient. Quand c'est l'hospitalisation,
 * il nomme le service, et le patient apparaît aussitôt dans la liste de ce
 * service, en attente de lit.
 *
 * La décision médicale et la logistique restent séparées : le médecin décide
 * du service, le service attribue le lit. Un médecin en consultation ne sait
 * pas quel lit vient de se libérer en pédiatrie, et ce n'est pas son travail
 * de le savoir.
 *
 * Une orientation manque rarement : elle attend. « En attente de résultats »
 * est une décision comme une autre — c'est même la plus fréquente — et elle
 * doit pouvoir s'écrire, quitte à être reprise quand les examens reviennent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('orientation', 30)->nullable()->after('conduite_a_tenir');
            // Le service voulu, quand l'orientation est l'hospitalisation.
            $table->uuid('service_oriente_id')->nullable()->after('orientation');

            $table->foreign('service_oriente_id')->references('id')->on('services')->nullOnDelete();
        });

        Schema::table('visits', function (Blueprint $table) {
            // Le patient attend un lit : il figure dans le service demandé
            // sans y être encore couché.
            $table->timestamp('admission_demandee_le')->nullable()->after('service_id');
            $table->uuid('admission_service_id')->nullable()->after('admission_demandee_le');
            $table->uuid('admission_par')->nullable()->after('admission_service_id');

            $table->foreign('admission_service_id')->references('id')->on('services')->nullOnDelete();
            $table->foreign('admission_par')->references('id')->on('users')->nullOnDelete();
            $table->index('admission_service_id');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropForeign(['service_oriente_id']);
            $table->dropColumn(['orientation', 'service_oriente_id']);
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['admission_service_id']);
            $table->dropForeign(['admission_par']);
            $table->dropIndex(['admission_service_id']);
            $table->dropColumn(['admission_demandee_le', 'admission_service_id', 'admission_par']);
        });
    }
};
