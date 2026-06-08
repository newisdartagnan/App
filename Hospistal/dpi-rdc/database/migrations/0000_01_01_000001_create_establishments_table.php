<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->enum('type', ['hopital_general', 'clinique', 'centre_sante', 'dispensaire']);
            $table->string('province', 100)->nullable();
            $table->string('ville', 100)->nullable();
            $table->text('adresse')->nullable();
            $table->string('telephone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('central_sync_token', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
