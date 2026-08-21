<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le catalogue d'examens sert deux plateaux techniques qui n'ont rien à voir :
 * le laboratoire rend des valeurs mesurées avec leurs normes, l'imagerie rend
 * un compte rendu signé par le radiologue.
 *
 * Jusqu'ici les deux se distinguaient au préfixe du code (« IMG- »), ce qui
 * mélangeait les bulletins, les bons et les statistiques. On l'inscrit dans le
 * schéma.
 */
return new class extends Migration
{
    /** Modalités reconnues, d'après le code du catalogue. */
    private const MODALITES = [
        'IMG-RX-' => 'radiographie',
        'IMG-ECHO-' => 'echographie',
        'IMG-SCAN' => 'scanner',
        'IMG-IRM' => 'irm',
        'IMG-MAMMO' => 'mammographie',
        'IMG-DOPP' => 'doppler',
    ];

    public function up(): void
    {
        Schema::table('types_examens', function (Blueprint $table) {
            $table->string('domaine', 20)->default('labo')->index();
            $table->string('modalite', 30)->nullable();
        });

        DB::statement("ALTER TABLE types_examens ADD CONSTRAINT types_examens_domaine_check
            CHECK (domaine IN ('labo', 'imagerie'))");

        // « imagerie » devient une catégorie à part entière du catalogue :
        // elle était rangée dans « autre », au milieu des analyses.
        DB::statement('ALTER TABLE types_examens DROP CONSTRAINT IF EXISTS types_examens_categorie_check');
        DB::statement("ALTER TABLE types_examens ADD CONSTRAINT types_examens_categorie_check
            CHECK (categorie IN ('hematologie', 'biochimie', 'microbiologie', 'serologie',
                                 'parasitologie', 'anatomopathologie', 'imagerie', 'autre'))");

        // Reprise de l'existant : tout ce qui porte un code d'imagerie bascule.
        DB::table('types_examens')->where('code', 'like', 'IMG-%')
            ->update(['domaine' => 'imagerie', 'categorie' => 'imagerie']);

        foreach (self::MODALITES as $prefixe => $modalite) {
            DB::table('types_examens')
                ->where('domaine', 'imagerie')
                ->where('code', 'like', $prefixe.'%')
                ->update(['modalite' => $modalite]);
        }

        DB::table('types_examens')
            ->where('domaine', 'imagerie')
            ->whereNull('modalite')
            ->update(['modalite' => 'autre']);

        // L'imagerie ne porte pas de normes : un compte rendu n'a pas de
        // valeur de référence. On nettoie ce que le catalogue traînait.
        DB::table('types_examens')->where('domaine', 'imagerie')->update(['valeurs_reference' => '[]']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE types_examens DROP CONSTRAINT IF EXISTS types_examens_domaine_check');

        Schema::table('types_examens', function (Blueprint $table) {
            $table->dropColumn(['domaine', 'modalite']);
        });
    }
};
