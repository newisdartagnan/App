<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // La dialyse est un service d'hospitalisation à part entière, et ses
        // séances sont des actes cliniques facturables comme le bloc.
        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_type_check');
        DB::statement(
            "ALTER TABLE services ADD CONSTRAINT services_type_check CHECK (type::text = ANY (ARRAY[
                'urgence','medecine','chirurgie','maternite','pediatrie',
                'reanimation','neonatologie','dialyse','labo','pharmacie','autre'
            ]::text[]))"
        );

        DB::statement('ALTER TABLE actes_cliniques DROP CONSTRAINT IF EXISTS actes_cliniques_domaine_check');
        DB::statement(
            "ALTER TABLE actes_cliniques ADD CONSTRAINT actes_cliniques_domaine_check CHECK (domaine IN (
                'chirurgie','maternite','hospitalisation','examen_specialise','dialyse'
            ))"
        );

        // Installations déjà en service : on crée le service et ses postes.
        foreach (DB::table('establishments')->pluck('id') as $etablissement) {
            $existant = DB::table('services')
                ->where('establishment_id', $etablissement)
                ->where('code', 'DIAL')
                ->value('id');

            if ($existant) {
                continue;
            }

            $serviceId = (string) Str::uuid();

            DB::table('services')->insert([
                'id' => $serviceId,
                'establishment_id' => $etablissement,
                'code' => 'DIAL',
                'nom' => 'Dialyse / Néphrologie',
                'type' => 'dialyse',
                'capacite_lits' => 8,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            for ($i = 1; $i <= 8; $i++) {
                DB::table('lits')->insert([
                    'id' => (string) Str::uuid(),
                    'establishment_id' => $etablissement,
                    'service_id' => $serviceId,
                    'numero' => 'DIAL-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'statut' => 'libre',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('lits')
            ->whereIn('service_id', DB::table('services')->where('code', 'DIAL')->pluck('id'))
            ->delete();
        DB::table('services')->where('code', 'DIAL')->delete();

        DB::statement('ALTER TABLE actes_cliniques DROP CONSTRAINT IF EXISTS actes_cliniques_domaine_check');
        DB::statement(
            "ALTER TABLE actes_cliniques ADD CONSTRAINT actes_cliniques_domaine_check CHECK (domaine IN (
                'chirurgie','maternite','hospitalisation','examen_specialise'
            ))"
        );

        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_type_check');
        DB::statement(
            "ALTER TABLE services ADD CONSTRAINT services_type_check CHECK (type::text = ANY (ARRAY[
                'urgence','medecine','chirurgie','maternite','pediatrie',
                'reanimation','neonatologie','labo','pharmacie','autre'
            ]::text[]))"
        );
    }
};
