<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Paramétrage de l'établissement.
     *
     * Les taux de change vivaient dans un fichier de configuration : les
     * modifier imposait d'éditer le code et de redéployer. Ils passent en
     * base, avec l'historique de chaque révision — une hausse du dollar doit
     * se saisir au guichet en trente secondes, et rester traçable.
     */
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('cle', 100);
            $table->jsonb('valeur')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['establishment_id', 'cle']);
        });

        /*
         * Historique des taux.
         *
         * Chaque écriture monétaire fige déjà son propre taux, donc réviser
         * le change ne réécrit rien. Cette table sert au contrôle : savoir
         * qui a passé le dollar de 2 300 à 2 500, et quand.
         */
        Schema::create('taux_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('devise', 5);
            $table->decimal('taux_cdf', 14, 4);
            $table->decimal('taux_precedent', 14, 4)->nullable();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('motif')->nullable();
            $table->timestamp('applique_a');
            $table->timestamps();

            $table->index(['establishment_id', 'devise', 'applique_a']);
        });

        // Les taux du fichier de configuration deviennent les taux courants,
        // pour que rien ne change au moment de la bascule.
        $administrateur = DB::table('users')->orderBy('created_at')->value('id');

        foreach (DB::table('establishments')->pluck('id') as $etablissement) {
            $taux = [];

            foreach (config('dpi.devises', []) as $code => $definition) {
                $taux[$code] = (float) $definition['taux_cdf'];

                if ($administrateur && $code !== config('dpi.devise_pivot', 'CDF')) {
                    DB::table('taux_changes')->insert([
                        'id' => (string) Str::uuid(),
                        'establishment_id' => $etablissement,
                        'devise' => $code,
                        'taux_cdf' => $taux[$code],
                        'taux_precedent' => null,
                        'user_id' => $administrateur,
                        'motif' => 'Taux initial repris du paramétrage de l\'application.',
                        'applique_a' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('parametres')->insert([
                'id' => (string) Str::uuid(),
                'establishment_id' => $etablissement,
                'cle' => 'taux_change',
                'valeur' => json_encode($taux),
                'user_id' => $administrateur,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Modalités de règlement d'une convention : délai accordé et mode
        // attendu, jusqu'ici implicites.
        Schema::table('assurances', function (Blueprint $table) {
            $table->unsignedSmallInteger('delai_reglement_jours')->default(30)->after('taux_couverture');
            $table->string('mode_reglement', 20)->default('virement')->after('delai_reglement_jours');
            $table->string('periodicite_facturation', 20)->default('mensuelle')->after('mode_reglement');
            $table->decimal('ticket_moderateur', 5, 2)->default(0)->after('periodicite_facturation');
            $table->string('contact_nom', 150)->nullable()->after('notes');
            $table->string('contact_telephone', 50)->nullable()->after('contact_nom');
            $table->string('contact_email', 150)->nullable()->after('contact_telephone');
        });

        // Le référentiel des couvertures gagne les catégories introduites
        // depuis (diète, dialyse), sans quoi aucune règle ne peut les viser.
        DB::statement('ALTER TABLE assurance_couvertures DROP CONSTRAINT IF EXISTS assurance_couvertures_type_check');
        DB::statement(
            "ALTER TABLE assurance_couvertures ADD CONSTRAINT assurance_couvertures_type_check
             CHECK (type::text = ANY (ARRAY[
                'consultation','examen_labo','medicament','acte_chirurgical',
                'hospitalisation','imagerie','diete','dialyse','autre'
             ]::text[]))"
        );
    }

    public function down(): void
    {
        Schema::table('assurances', function (Blueprint $table) {
            $table->dropColumn([
                'delai_reglement_jours', 'mode_reglement', 'periodicite_facturation',
                'ticket_moderateur', 'contact_nom', 'contact_telephone', 'contact_email',
            ]);
        });

        Schema::dropIfExists('taux_changes');
        Schema::dropIfExists('parametres');

        DB::statement('ALTER TABLE assurance_couvertures DROP CONSTRAINT IF EXISTS assurance_couvertures_type_check');
        DB::statement(
            "ALTER TABLE assurance_couvertures ADD CONSTRAINT assurance_couvertures_type_check
             CHECK (type::text = ANY (ARRAY[
                'consultation','examen_labo','medicament','acte_chirurgical',
                'hospitalisation','imagerie'
             ]::text[]))"
        );
    }
};
