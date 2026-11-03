<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Données de référence uniquement : aucun patient fictif en base réelle. */
    public function run(): void
    {
        $this->call(RolesSeeder::class);
    }
}
