<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'anthropométrie et la prise en charge nutritionnelle.
 *
 * C'était la dernière grande rubrique des canevas que l'application ne
 * savait pas remplir : l'UNTA du centre de santé, l'UNTI de l'hôpital et
 * l'unité de supplémentation. Elle se remontait de mémoire et de fiches
 * cartonnées, alors qu'un enfant admis pour malnutrition sévère est suivi
 * ici semaine après semaine.
 *
 * Deux tables, parce que ce sont deux choses différentes.
 *
 * La mesure est un fait daté : le poids, la taille, le périmètre brachial
 * et les œdèmes d'un jour donné. On en reprend à chaque passage, et c'est
 * leur suite qui dit si l'enfant remonte.
 *
 * La prise en charge est un épisode : une admission dans une unité, un
 * motif d'admission, puis une issue. Elle dure des semaines et survit aux
 * visites. Le canevas compte les entrées et les issues du mois, pas les
 * mesures.
 *
 * Sur le score P/T : l'application ne le calcule pas. Les tables de
 * référence de l'OMS ne sont pas dans le dépôt, et un z-score inventé
 * enverrait un enfant dans la mauvaise unité. Le soignant le lit sur son
 * abaque et le saisit ; l'application en tire la classification, comme
 * elle le fait du périmètre brachial et des œdèmes, qui eux se lisent
 * directement.
 *
 * Source : canevas mensuels du SNIS du 12 octobre 2024 — CS § 11
 * (UNTA, UNS), HGR et HST § 7 (UNTI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesures_anthropometriques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            // La mesure peut se prendre hors visite, au dépistage.
            $table->uuid('visit_id')->nullable();
            $table->uuid('user_id');

            $table->timestamp('mesure_a');

            $table->decimal('poids_kg', 6, 3)->nullable();
            $table->decimal('taille_cm', 5, 1)->nullable();
            // Couché avant deux ans, debout après : deux centimètres d'écart,
            // et deux centimètres déplacent un z-score.
            $table->string('position_taille', 12)->nullable();

            // Le périmètre brachial en millimètres : c'est l'unité du
            // protocole et du ruban, et il n'a pas de décimale.
            $table->unsignedSmallInteger('perimetre_brachial_mm')->nullable();

            // Œdèmes bilatéraux : aucun, +, ++, +++. Leur seule présence
            // signe la malnutrition sévère, quel que soit le poids.
            $table->string('oedemes', 12)->default('aucun');

            // Lu sur l'abaque par le soignant, jamais calculé ici.
            $table->decimal('z_score_pt', 4, 2)->nullable();

            $table->decimal('imc', 5, 2)->nullable();
            $table->text('observation')->nullable();

            $table->string('sync_status', 20)->default('pending');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');

            $table->index(['patient_id', 'mesure_a']);
            $table->index('mesure_a');
        });

        Schema::create('prises_en_charge_nutritionnelles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('establishment_id');
            $table->uuid('visit_id')->nullable();
            $table->uuid('user_id');

            // unta : ambulatoire, sévère sans complication
            // unti : hospitalier, sévère avec complication
            // uns  : supplémentation, malnutrition modérée
            $table->string('unite', 8);

            $table->date('date_admission');
            // Le motif, tel que le canevas le compte : PT/PB, œdèmes,
            // rechute, autre — et pour l'UNS, dépistée ou transférée.
            $table->string('critere_admission', 24);
            // L'UNTI n'admet que les cas compliqués : c'est ce qui la
            // distingue de l'UNTA, et le canevas le dit sur chaque ligne.
            $table->boolean('avec_complication')->default(false);
            $table->uuid('mesure_id')->nullable();

            // Les groupes que l'UNS suit à part : femmes enceintes et
            // allaitantes au périmètre brachial, PVVIH et tuberculeux à l'IMC.
            $table->string('groupe_specifique', 20)->nullable();

            $table->date('date_sortie')->nullable();
            $table->string('issue', 24)->nullable();
            $table->uuid('sortie_par')->nullable();
            $table->text('observation')->nullable();

            $table->string('sync_status', 20)->default('pending');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('establishment_id')->references('id')->on('establishments')->cascadeOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('sortie_par')->references('id')->on('users');
            $table->foreign('mesure_id')->references('id')->on('mesures_anthropometriques')->nullOnDelete();

            $table->index(['establishment_id', 'date_admission']);
            $table->index(['establishment_id', 'date_sortie']);
            $table->index(['patient_id', 'date_admission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prises_en_charge_nutritionnelles');
        Schema::dropIfExists('mesures_anthropometriques');
    }
};
