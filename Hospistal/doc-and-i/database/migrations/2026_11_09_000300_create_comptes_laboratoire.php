<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADM-01 : un compte « laboratoire » est rattaché à UN laboratoire
 * partenaire. Table à part : le rattachement ne concerne qu'un rôle, et le
 * lot examens s'en servira pour limiter chaque laboratoire à ses demandes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comptes_laboratoire', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users');
            $table->foreignUuid('partenaire_id')->constrained('partenaires');
            $table->timestamps();

            $table->index('partenaire_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes_laboratoire');
    }
};
