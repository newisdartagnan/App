<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TLC-03 : ordre du fil de messages. L'horodatage est à la seconde et les
 * identifiants sont aléatoires : deux messages de la même seconde
 * revenaient dans le désordre. Un rang croissant, posé par la base, fixe
 * l'ordre et sert de repère à la lecture « après le dernier reçu ».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages_consultation ADD COLUMN rang BIGSERIAL');
        DB::statement('CREATE UNIQUE INDEX messages_consultation_rang ON messages_consultation (consultation_id, rang)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_consultation_rang');
        DB::statement('ALTER TABLE messages_consultation DROP COLUMN rang');
    }
};
