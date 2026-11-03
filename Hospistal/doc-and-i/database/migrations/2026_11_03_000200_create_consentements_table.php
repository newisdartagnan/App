<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CPT-04 : consentement explicite, horodaté et révocable. Une ligne
        // n'est jamais modifiée sauf pour enregistrer son retrait : l'historique
        // des acceptations reste lisible.
        Schema::create('consentements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20);
            $table->timestamp('accepte_le');
            $table->string('ip', 45)->nullable();
            $table->string('agent_utilisateur', 255)->nullable();
            $table->timestamp('retire_le')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consentements');
    }
};
