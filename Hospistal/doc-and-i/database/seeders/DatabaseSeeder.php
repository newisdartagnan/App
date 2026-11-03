<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Données de référence partout ; données de démonstration en local seulement. */
    public function run(): void
    {
        $this->call([RolesSeeder::class, ReferentielMedicalSeeder::class]);

        if (app()->environment('local')) {
            $this->call(DemonstrationLocaleSeeder::class);
        }
    }
}
