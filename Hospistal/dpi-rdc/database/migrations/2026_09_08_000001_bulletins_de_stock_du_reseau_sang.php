<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le réseau des banques de sang, entre hôpitaux réellement distants.
 *
 * L'écran « Réseau » existait déjà, mais il lisait le stock des autres
 * établissements dans la base locale : il ne disait la vérité que si tous
 * les hôpitaux partageaient une seule base. Or chaque hôpital tourne chez
 * lui, sur son serveur, avec sa propre base — c'est même le principe de
 * l'application. Entre deux villes, l'écran était donc vide, ou pire :
 * plausible et faux.
 *
 * Chaque maison publie désormais un bulletin — combien de poches par groupe
 * et par produit, et à quel numéro on la joint — et reçoit en retour ceux
 * des autres. Ce qui voyage n'est qu'un décompte : jamais un nom de donneur,
 * jamais un numéro de poche, jamais un patient. Le réseau sert à savoir où
 * appeler, pas à recopier le fichier du voisin.
 *
 * Un bulletin porte son heure. Un stock annoncé il y a six heures n'est pas
 * une promesse, et l'écran doit le dire : à trois heures du matin, on part
 * en ambulance sur cette ligne-là.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulletins_stock_sang', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le code de l'établissement, pas son identifiant : d'une base à
            // l'autre les UUID diffèrent, le code est ce qui les rattache.
            $table->string('etablissement_code', 100)->unique();
            $table->string('nom');
            $table->string('ville')->nullable();
            $table->string('province')->nullable();
            $table->string('telephone')->nullable();

            // Décomptes uniquement : {"sang_total": {"O-": 3, "A+": 5}, …}
            $table->jsonb('stock')->nullable();
            // Donneurs éligibles par groupe — un nombre, jamais une liste.
            $table->jsonb('donneurs')->nullable();

            // L'heure de la maison qui publie, et celle où nous l'avons reçu :
            // les deux comptent quand la liaison a traîné.
            $table->timestamp('publie_le');
            $table->timestamp('recu_le');

            $table->timestamps();

            $table->index('publie_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulletins_stock_sang');
    }
};
