<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le patient s'identifie par son numéro de téléphone (CPT-01) ; le
        // personnel aura en plus un courriel, un mot de passe et une double
        // authentification (CPT-06, à venir).
        Schema::create('users', function (Blueprint $table) {
            // UUID : aucun identifiant séquentiel, devinable, dans les adresses.
            $table->uuid('id')->primary();
            $table->string('telephone', 16)->unique();
            $table->timestamp('telephone_verifie_le')->nullable();
            $table->string('nom', 100)->nullable();
            $table->string('postnom', 100)->nullable();
            $table->string('prenom', 100)->nullable();
            $table->char('sexe', 1)->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('commune', 100)->nullable();
            $table->string('langue', 2)->default('fr');
            $table->string('contact_urgence_nom', 150)->nullable();
            $table->string('contact_urgence_telephone', 16)->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
