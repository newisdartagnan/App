<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les diagnostics en CIM-11, et le diagnostic qui suit l'épisode.
 *
 * Deux choses.
 *
 * La première : le code demandé était le CIM-10, que l'OMS a remplacé par le
 * CIM-11. On garde l'ancien champ — un dossier ne se réécrit pas — et on
 * ajoute le nouveau à côté.
 *
 * La seconde : le libellé se retapait à chaque écran. Le médecin posait
 * « paludisme grave » en consultation, puis le réécrivait dans l'indication
 * du bon d'examen, puis dans celle de la transfusion, puis dans le bulletin
 * de sortie. Quatre saisies pour une seule idée, et quatre occasions de
 * diverger — c'est ainsi qu'un dossier finit par porter trois diagnostics
 * différents pour un même épisode.
 *
 * Le référentiel des diagnostics tient dans la table qui porte déjà les
 * allergies et les antécédents. On lui ajoute seulement de quoi le
 * chercher : « palu », « TB », « HTA » ne sont pas des libellés officiels,
 * mais c'est ce que les gens tapent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referentiel_medical', function (Blueprint $table) {
            // Ce que le médecin tape réellement, séparé par des virgules.
            $table->text('synonymes')->nullable()->after('libelle');

            // Un code posé de mémoire n'est pas un code vérifié. Tant qu'un
            // médecin de la maison ne l'a pas relu, l'écran le dit — mieux
            // vaut un doute affiché qu'une fausse certitude dans un rapport.
            $table->boolean('code_verifie')->default(false)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('referentiel_medical', function (Blueprint $table) {
            $table->dropColumn(['synonymes', 'code_verifie']);
        });
    }
};
