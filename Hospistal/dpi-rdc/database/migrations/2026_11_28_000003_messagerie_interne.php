<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messagerie interne : des messages texte d'un agent à un autre, sur le
 * réseau local, sans Internet. La conversation est la suite des messages
 * échangés entre deux agents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expediteur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('destinataire_id')->constrained('users')->cascadeOnDelete();
            $table->text('contenu');
            $table->timestamp('lu_at')->nullable();
            // Supprimé par son auteur : effacé pour les deux. Par le
            // destinataire : seulement masqué chez lui.
            $table->boolean('supprime')->default(false);
            $table->boolean('masque_destinataire')->default(false);
            $table->timestamps();
            $table->index(['destinataire_id', 'lu_at']);
            $table->index(['expediteur_id', 'destinataire_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
