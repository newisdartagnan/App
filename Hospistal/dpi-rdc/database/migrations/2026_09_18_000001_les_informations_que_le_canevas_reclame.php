<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que les canevas du SNIS réclament et que le dossier ne portait pas.
 *
 * Trois manques, relevés en lisant les quatre formulaires officiels :
 *
 * 1. D'où vient le patient. La colonne existait — `mode_entree` — mais
 *    n'était jamais renseignée : toute visite valait « venu de lui-même ».
 *    Le canevas du centre de santé compte à part les cas contre-référés et
 *    ceux qu'un relais communautaire a orientés ; celui de l'hôpital compte
 *    les admis « dont référés ». Sans la provenance, ces lignes se
 *    remplissent de mémoire à la fin du mois.
 *
 * 2. Qui est le nouveau cas. Le canevas ventile les nouveaux cas en
 *    travailleurs du secteur formel, mutualistes et indigents. Les deux
 *    premiers n'existaient nulle part : ce ne sont pas des modes de
 *    paiement — un salarié peut être mutualiste, un mutualiste peut payer
 *    de sa poche — donc deux caractéristiques à part, et non une valeur de
 *    plus dans la prise en charge.
 *
 * 3. Comment le séjour s'est terminé. Le canevas veut « statu quo » et
 *    « évadés / abandons » ; la liste des modes de sortie n'avait ni l'un
 *    ni l'autre, et un patient parti sans prévenir était sorti « guéri ».
 *
 * Les contraintes de valeurs partent en même temps : ces listes vivent
 * désormais dans les constantes du modèle, comme partout ailleurs dans
 * l'application. Une valeur de plus n'y demandera plus de migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE visits DROP CONSTRAINT IF EXISTS visits_mode_entree_check');
        DB::statement('ALTER TABLE visits DROP CONSTRAINT IF EXISTS visits_mode_sortie_check');

        Schema::table('patients', function (Blueprint $table) {
            // Le canevas les compte séparément parce qu'ils se cumulent.
            $table->boolean('travailleur_secteur_formel')->default(false)->after('profession');
            $table->boolean('mutualiste')->default(false)->after('travailleur_secteur_formel');
            $table->string('mutuelle_nom', 150)->nullable()->after('mutualiste');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['travailleur_secteur_formel', 'mutualiste', 'mutuelle_nom']);
        });

        // Les contraintes ne sont pas rétablies : des visites peuvent
        // désormais porter une provenance ou une issue que l'ancienne liste
        // refusait, et les remettre ferait échouer la migration inverse sur
        // une base qui a servi.
    }
};
