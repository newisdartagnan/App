<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre des actes infirmiers.
 *
 * Le dossier savait tracer le pansement, le gavage, l'évaluation neurologique
 * et la transfusion. Tout le reste du travail infirmier — l'injection posée à
 * deux heures, la perfusion changée, la sonde placée, l'oxygène branché,
 * l'aspiration, la toilette d'un grabataire — ne laissait aucune trace.
 *
 * Ce n'est pas seulement une question de registre. Un acte non écrit est un
 * acte qu'on refait ou qu'on oublie : à la relève, l'équipe suivante ne sait
 * pas si la deuxième injection a été faite. Et c'est aussi du travail qui ne
 * se voit nulle part — ni dans la facture, ni dans l'activité du service,
 * ni dans ce qu'on peut montrer d'une nuit de garde.
 *
 * L'acte porte le nom de qui l'a réellement fait et l'heure à laquelle il
 * l'a fait. Ni l'un ni l'autre ne se choisissent dans un menu : c'est
 * l'agent connecté et l'horloge, sans quoi la trace ne vaudrait rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actes_infirmiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('visit_id');
            $table->uuid('patient_id');
            // Qui l'a fait. Jamais choisi dans une liste : l'agent connecté.
            $table->uuid('user_id');

            $table->string('type', 40);
            $table->string('libelle');
            // Le détail de l'acte : site d'injection, débit, calibre de sonde.
            $table->text('precisions')->nullable();

            // L'ordre du médecin, quand l'acte en découle. Un soin de confort
            // n'en a pas et ne doit pas pour autant être refusé.
            $table->uuid('prescription_id')->nullable();

            $table->timestamp('realise_a');
            // Ce que l'infirmier a observé en le faisant : c'est souvent là
            // que se voit une complication avant tout le monde.
            $table->text('observation')->nullable();

            $table->string('sync_status', 20)->default('pending');
            $table->timestamps();

            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('prescription_id')->references('id')->on('prescriptions')->nullOnDelete();

            $table->index(['visit_id', 'realise_a']);
            $table->index(['user_id', 'realise_a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actes_infirmiers');
    }
};
