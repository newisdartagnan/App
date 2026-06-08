<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('table_name', 100);
            $table->uuid('record_id');
            $table->enum('action', ['create', 'update', 'delete']);
            $table->jsonb('payload')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('priority')->default(5);
            $table->timestamp('created_at')->nullable();

            $table->index(['establishment_id', 'synced_at', 'priority', 'created_at']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('table_name', 100);
            $table->uuid('record_id');
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->jsonb('local_data');
            $table->jsonb('central_data');
            $table->enum('resolution', ['pending', 'local_wins', 'central_wins', 'manual'])->default('pending');
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['establishment_id', 'resolution']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('establishment_id')->nullable()->constrained('establishments')->nullOnDelete();
            $table->string('action', 100);
            $table->string('table_name', 100)->nullable();
            $table->uuid('record_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['establishment_id', 'created_at']);
            $table->index(['table_name', 'record_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE RULE no_update_audit AS ON UPDATE TO audit_logs DO INSTEAD NOTHING');
            DB::statement('CREATE RULE no_delete_audit AS ON DELETE TO audit_logs DO INSTEAD NOTHING');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP RULE IF EXISTS no_delete_audit ON audit_logs');
            DB::statement('DROP RULE IF EXISTS no_update_audit ON audit_logs');
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_queue');
    }
};
