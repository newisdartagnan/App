<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CPT-06 : double authentification du personnel. Une ligne par compte
        // du personnel, à part de users (le patient n'en a pas besoin).
        Schema::create('authentifications_personnel', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            // Secret TOTP CHIFFRÉ par l'application (cast « encrypted ») :
            // une copie de la base ne suffit pas à produire des codes.
            $table->text('totp_secret')->nullable();
            // Dernier pas de 30 s accepté : un code déjà servi est refusé (rejeu).
            $table->bigInteger('totp_dernier_pas')->nullable();
            // Fin de l'activation : mot de passe choisi ET second facteur confirmé.
            $table->timestamp('active_le')->nullable();
            // Échecs consécutifs (mot de passe ou second facteur) et verrouillage.
            $table->unsignedSmallInteger('echecs_consecutifs')->default(0);
            $table->timestamp('verrouille_jusqu_au')->nullable();
            $table->timestamp('derniere_connexion_le')->nullable();
            $table->timestamps();
        });

        // Lien d'activation à usage unique (CPT-05) : seule l'empreinte du
        // jeton est gardée, le jeton lui-même n'existe que dans le lien.
        Schema::create('jetons_activation_personnel', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->char('empreinte', 64)->unique();
            $table->timestamp('expire_le');
            $table->timestamp('utilise_le')->nullable();
            $table->timestamp('invalide_le')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // Codes de récupération : montrés une fois, gardés en empreinte, un seul usage chacun.
        Schema::create('codes_recuperation_personnel', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->char('empreinte', 64)->unique();
            $table->timestamp('utilise_le')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // Un seul lien d'activation valable à la fois par compte.
        DB::statement('CREATE UNIQUE INDEX jetons_activation_un_actif_par_compte ON jetons_activation_personnel (user_id) WHERE utilise_le IS NULL AND invalide_le IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_recuperation_personnel');
        Schema::dropIfExists('jetons_activation_personnel');
        Schema::dropIfExists('authentifications_personnel');
    }
};
