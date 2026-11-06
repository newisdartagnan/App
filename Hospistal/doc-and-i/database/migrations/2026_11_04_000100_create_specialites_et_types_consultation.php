<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Ce qu'on réserve : une durée et un prix (PAY-01). Le prix est fixé
        // dans une devise de référence ; il est recopié sur le rendez-vous au
        // moment de la réservation, pour qu'une révision ne change rien après coup.
        Schema::create('types_consultation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('specialite_id')->constrained('specialites');
            $table->string('nom', 150);
            $table->unsignedSmallInteger('duree_minutes');
            $table->decimal('prix', 12, 2)->nullable();
            $table->char('devise', 3)->default('USD');
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['specialite_id', 'nom']);
        });

        DB::statement('ALTER TABLE types_consultation ADD CONSTRAINT types_consultation_duree CHECK (duree_minutes BETWEEN 10 AND 120)');
        DB::statement("ALTER TABLE types_consultation ADD CONSTRAINT types_consultation_devise CHECK (devise IN ('USD', 'CDF'))");
        DB::statement('ALTER TABLE types_consultation ADD CONSTRAINT types_consultation_prix CHECK (prix IS NULL OR prix >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('types_consultation');
        Schema::dropIfExists('specialites');
    }
};
