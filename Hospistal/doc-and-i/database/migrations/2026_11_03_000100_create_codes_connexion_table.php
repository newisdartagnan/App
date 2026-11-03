<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Codes à usage unique envoyés par SMS. Le code n'est jamais stocké en
        // clair : seule son empreinte (HMAC) est gardée.
        Schema::create('codes_connexion', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('telephone', 16)->index();
            $table->string('empreinte', 64);
            $table->timestamp('expire_le');
            $table->unsignedSmallInteger('essais')->default(0);
            $table->timestamp('utilise_le')->nullable();
            $table->timestamp('invalide_le')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        // Un seul code actif par numéro, garanti par la base : même des
        // demandes parallèles ne peuvent pas laisser plusieurs codes à deviner.
        DB::statement('CREATE UNIQUE INDEX codes_connexion_un_actif_par_numero ON codes_connexion (telephone) WHERE utilise_le IS NULL AND invalide_le IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_connexion');
    }
};
