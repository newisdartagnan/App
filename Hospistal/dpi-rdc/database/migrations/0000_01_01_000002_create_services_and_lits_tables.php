<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('nom');
            $table->enum('type', ['urgence', 'medecine', 'chirurgie', 'maternite', 'pediatrie', 'labo', 'pharmacie', 'autre'])->default('medecine');
            $table->integer('capacite_lits')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['establishment_id', 'code']);
        });

        Schema::create('lits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('numero', 20);
            $table->enum('statut', ['libre', 'occupe', 'maintenance', 'reserve'])->default('libre');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['establishment_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lits');
        Schema::dropIfExists('services');
    }
};
