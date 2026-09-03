<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le thème de l'application, choisi par chacun.
 *
 * « Trop de blanc », disent ceux qui passent la journée devant l'écran — et
 * ils ont raison : un fond blanc pur sous un néon d'hôpital fatigue, et la
 * garde de nuit travaille dans le noir avec un écran qui éblouit.
 *
 * Le choix est attaché à l'agent et non au poste. Un poste d'hôpital passe
 * de main en main toute la journée : si le réglage suivait la machine,
 * l'infirmière de nuit hériterait de celui du médecin du matin et devrait le
 * refaire à chaque prise de service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 20)->default('clair')->after('specialite');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
