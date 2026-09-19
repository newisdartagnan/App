<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La planification familiale et le Programme élargi de vaccination.
 *
 * Deux registres qui vivaient entièrement sur papier, et deux sections du
 * canevas que le rapport déclarait franchement ne pas savoir remplir.
 *
 * Ils ne se ressemblent pas et ne se rangent pas ensemble.
 *
 * La PF compte des acceptantes : qui a reçu quelle méthode, pour la
 * première fois ou en renouvellement, à quel âge, et par quel canal — au
 * centre de santé ou par un distributeur communautaire. Le canevas la
 * ventile par méthode, par tranche d'âge et par canal, et compte à part le
 * post-partum : la femme qui repart de la maternité avec une méthode, et
 * celle qui a seulement été conseillée.
 *
 * Le PEV compte des doses : quel antigène, quelle stratégie — au poste
 * fixe, en avancée, en équipe mobile. Chaque dose est un acte daté sur un
 * enfant nommé, ce qui est la seule façon de savoir ensuite lesquels sont
 * complètement vaccinés et lesquels ont décroché en route.
 *
 * Source : canevas mensuels du SNIS du 12 octobre 2024 — CS § 3 et § 8.4,
 * HGR et HST § 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actes_planification_familiale', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('establishment_id');
            $table->uuid('user_id');
            $table->uuid('visit_id')->nullable();

            $table->date('date_acte');

            // « methode » : une méthode a été remise ou posée.
            // « conseil_post_partum » : la femme a été conseillée, sans
            // méthode — le canevas compte cette ligne séparément.
            $table->string('type_acte', 24)->default('methode');
            $table->string('methode', 40)->nullable();

            // Première acceptation ou renouvellement : le canevas ne les
            // sépare pas en colonnes, mais la zone le demande à l'oral et
            // c'est la seule façon de mesurer le recrutement.
            $table->string('type_acceptation', 16)->default('nouvelle');

            // ESS : établissement de soins. DBC : distribution à base
            // communautaire. Le canevas a une colonne pour chacun.
            $table->string('canal', 8)->default('ess');

            // Sortie de maternité avec une méthode moderne : c'est la
            // première ligne du § 3.1, et elle ne se déduit d'aucune autre.
            $table->boolean('post_partum')->default(false);

            $table->text('observation')->nullable();

            $table->string('sync_status', 20)->default('pending');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('establishment_id')->references('id')->on('establishments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();

            $table->index(['establishment_id', 'date_acte']);
            $table->index(['patient_id', 'date_acte']);
        });

        Schema::create('vaccinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('establishment_id');
            $table->uuid('user_id');

            $table->date('date_vaccination');
            $table->string('antigene', 24);
            // Fixe, avancée, mobile : le canevas ventile chaque antigène
            // sur les trois, parce que ce sont trois coûts différents.
            $table->string('strategie', 12)->default('fixe');

            // Le lot et la date de péremption : en cas de manifestation
            // post-vaccinale, c'est la première chose qu'on demande.
            $table->string('lot', 60)->nullable();
            $table->text('observation')->nullable();

            $table->string('sync_status', 20)->default('pending');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('establishment_id')->references('id')->on('establishments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');

            $table->index(['establishment_id', 'date_vaccination']);
            $table->index(['patient_id', 'antigene']);

            // Une dose ne se donne qu'une fois : le deuxième enregistrement
            // du même antigène pour le même enfant est une erreur de saisie,
            // et il fausserait la couverture vaccinale à la hausse.
            $table->unique(['patient_id', 'antigene']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccinations');
        Schema::dropIfExists('actes_planification_familiale');
    }
};
