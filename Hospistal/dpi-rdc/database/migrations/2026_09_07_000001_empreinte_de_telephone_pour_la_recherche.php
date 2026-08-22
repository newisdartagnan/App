<?php

use App\Models\Patient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrouver un patient par son numéro de téléphone.
 *
 * Le téléphone est chiffré en base — et il doit le rester : c'est la donnée
 * qui permet de joindre quelqu'un chez lui. Mais un chiffrement à vecteur
 * aléatoire donne deux écritures différentes pour le même numéro, si bien
 * qu'aucun LIKE ne peut jamais y répondre. Deux écrans le promettaient
 * pourtant depuis toujours : l'accueil tapait le numéro et n'obtenait rien.
 *
 * On garde donc à côté une empreinte du numéro — une signature HMAC, non
 * réversible et inutilisable sans la clé de l'application — qui se compare
 * à l'identique. On ne cherche plus « un morceau du numéro » : on cherche
 * le numéro, ce qui est de toute façon ce qu'on fait avec un téléphone
 * en main.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('telephone_index', 64)->nullable()->after('telephone');
            $table->index('telephone_index');
        });

        // Les dossiers déjà ouverts doivent être retrouvables eux aussi :
        // sans reprise, la recherche ne marcherait que pour les nouveaux.
        Patient::withTrashed()
            ->whereNotNull('telephone')
            ->chunkById(200, function ($patients) {
                foreach ($patients as $patient) {
                    $empreinte = Patient::empreinteTelephone($patient->telephone);

                    if ($empreinte !== null) {
                        DB::table('patients')->where('id', $patient->id)
                            ->update(['telephone_index' => $empreinte]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['telephone_index']);
            $table->dropColumn('telephone_index');
        });
    }
};
