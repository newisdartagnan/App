<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarification par catégorie de convention et par fonction tarifaire.
 *
 * Chaque convention (société, assurance, mutuelle) est classée dans une
 * catégorie (A, B, C, D, CD, DD…). Une catégorie porte une rangée de
 * coefficients numérotés de 1 à 8. Chaque acte, examen ou consultation porte
 * une « fonction tarifaire » : le numéro de la colonne de coefficient qui
 * s'applique à lui. Prix conventionné = prix de base × coefficient
 * [catégorie de la convention][fonction tarifaire de l'acte].
 *
 * Le catalogue des actes reçoit sa fiche complète : identifiant, libellé,
 * fonction tarifaire, prix normal / urgent / externe, spécialité,
 * disponibilité (oui, non temporaire, non définitif), catégorie d'acte et
 * observation.
 */
return new class extends Migration
{
    public const NB_FONCTIONS = 8;

    public function up(): void
    {
        Schema::create('categories_tarifaires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('numero')->unique();
            $table->string('nom', 30)->unique();
            $table->text('commentaire')->nullable();
            // [c1, c2, … c8] : la fonction tarifaire n lit la colonne n.
            $table->jsonb('coefficients');
            $table->timestamps();
        });

        Schema::table('assurances', function (Blueprint $table) {
            $table->foreignUuid('categorie_tarifaire_id')->nullable()->constrained('categories_tarifaires')->nullOnDelete();
        });

        Schema::create('actes_catalogue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('numero')->unique();
            $table->string('libelle');
            $table->unsignedSmallInteger('fonction_tarifaire')->default(1);
            $table->decimal('prix_normal', 12, 2)->default(0);
            $table->decimal('prix_urgent', 12, 2)->nullable();
            $table->decimal('prix_externe', 12, 2)->nullable();
            $table->string('devise', 3)->default('USD');
            $table->string('specialite', 150)->nullable();
            $table->enum('disponibilite', ['oui', 'non_temporaire', 'non_definitif'])->default('oui');
            $table->string('categorie_acte', 100)->nullable();
            // Plateau où l'acte se demande : bloc, maternité, examen spécialisé, dialyse.
            $table->string('domaine', 30)->default('chirurgie');
            $table->unsignedInteger('duree_minutes')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();
            $table->index(['domaine', 'disponibilite']);
        });

        Schema::table('actes_cliniques', function (Blueprint $table) {
            $table->foreignUuid('acte_catalogue_id')->nullable()->constrained('actes_catalogue')->nullOnDelete();
            $table->unsignedSmallInteger('fonction_tarifaire')->nullable();
        });

        Schema::table('types_examens', function (Blueprint $table) {
            $table->unsignedSmallInteger('fonction_tarifaire')->nullable();
        });
        Schema::table('types_consultation', function (Blueprint $table) {
            $table->unsignedSmallInteger('fonction_tarifaire')->nullable();
        });

        // La grille de départ, telle que l'établissement la tient.
        $grille = [
            1 => ['A', [0.5504, 0.5491, 0.56, 0.50, 1.00, 0.80, 0.56, 0.7844]],
            2 => ['B', [0.8088, 0.8088, 0.825, 0.737, 1.00, 0.979, 0.75, 0.9599]],
            3 => ['C', [1.00, 1.00, 1.00, 1.00, 1.00, 1.00, 1.00, 1.00]],
            4 => ['D', [1.50, 1.4706, 1.50, 1.00, 1.00, 1.00, 1.50, 1.50]],
            5 => ['CD', [1.50, 1.4706, 1.50, 1.00, 1.00, 1.00, 1.50, 1.50]],
            6 => ['DD', [1.50, 1.4706, 1.50, 1.00, 1.00, 1.00, 1.50, 1.50]],
        ];
        foreach ($grille as $numero => [$nom, $coefficients]) {
            DB::table('categories_tarifaires')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'numero' => $numero,
                'nom' => $nom,
                'coefficients' => json_encode($coefficients),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('types_consultation', fn (Blueprint $t) => $t->dropColumn('fonction_tarifaire'));
        Schema::table('types_examens', fn (Blueprint $t) => $t->dropColumn('fonction_tarifaire'));
        Schema::table('actes_cliniques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acte_catalogue_id');
            $table->dropColumn('fonction_tarifaire');
        });
        Schema::dropIfExists('actes_catalogue');
        Schema::table('assurances', fn (Blueprint $t) => $t->dropConstrainedForeignId('categorie_tarifaire_id'));
        Schema::dropIfExists('categories_tarifaires');
    }
};
