<?php

namespace Database\Seeders;

use App\Models\Establishment;
use App\Models\TypeDiete;
use Illuminate\Database\Seeder;

/**
 * Régimes alimentaires servis par la cuisine, installés dans chaque
 * établissement. Idempotent : relancer le seeder ne duplique rien.
 */
class TypeDieteSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Establishment::pluck('id') as $establishmentId) {
            TypeDiete::installerPour($establishmentId);
        }
    }
}
