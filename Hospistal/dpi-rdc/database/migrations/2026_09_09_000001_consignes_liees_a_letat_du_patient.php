<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les consignes liées à l'état du patient, sur l'ordonnance.
 *
 * Une ordonnance porte deux choses qu'on confondait dans une seule case.
 *
 * Les instructions du produit — « à prendre après le repas », « ne pas
 * écraser le comprimé » — tiennent à la molécule et valent pour n'importe
 * qui : elles restent sur la ligne du médicament.
 *
 * Les consignes liées à l'état du patient — « boire trois litres d'eau par
 * jour », « revenir immédiatement si la fièvre dépasse 39 », « ne pas
 * allaiter pendant le traitement » — tiennent à cette personne-là, à ce
 * moment-là. Elles ne se rattachent à aucun produit en particulier et
 * concernent souvent toute l'ordonnance. Écrites dans la case d'un
 * médicament, elles disparaissent dès qu'on retire ce médicament.
 *
 * Le champ « observations » existait déjà, mais c'est la note libre du
 * prescripteur : la mélanger avec ce que le patient doit faire chez lui
 * revenait à ne rien dire de clair ni à l'un ni à l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->text('consignes_patient')->nullable()->after('observations');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('consignes_patient');
        });
    }
};
