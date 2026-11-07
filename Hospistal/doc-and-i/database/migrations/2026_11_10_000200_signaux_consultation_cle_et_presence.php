<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Signalisation de l'appel vidéo (TLC-01, TLC-04).
 *
 * - cle : identifiant donné par le navigateur à chaque signal. En 3G, une
 *   requête renvoyée après un délai dépassé ne crée pas un second signal
 *   (double envoi) : index unique par consultation et émetteur.
 * - type « pret » : « je suis dans la salle, envoyez-moi une offre ». Le
 *   patient l'envoie en arrivant (salle d'attente) et après un rechargement ;
 *   le médecin y répond par une nouvelle offre. Sans lui, un participant qui
 *   revient après une coupure ne pourrait pas relancer l'appel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signaux_consultation', function (Blueprint $table) {
            $table->uuid('cle')->nullable();
            $table->unique(['consultation_id', 'emetteur_id', 'cle']);
        });

        DB::statement('ALTER TABLE signaux_consultation DROP CONSTRAINT signaux_type');
        DB::statement("ALTER TABLE signaux_consultation ADD CONSTRAINT signaux_type CHECK (type IN ('offre', 'reponse', 'candidat', 'raccroche', 'audio_seul', 'pret'))");
    }

    public function down(): void
    {
        DB::table('signaux_consultation')->where('type', 'pret')->delete();
        DB::statement('ALTER TABLE signaux_consultation DROP CONSTRAINT signaux_type');
        DB::statement("ALTER TABLE signaux_consultation ADD CONSTRAINT signaux_type CHECK (type IN ('offre', 'reponse', 'candidat', 'raccroche', 'audio_seul'))");

        Schema::table('signaux_consultation', function (Blueprint $table) {
            $table->dropUnique(['consultation_id', 'emetteur_id', 'cle']);
            $table->dropColumn('cle');
        });
    }
};
