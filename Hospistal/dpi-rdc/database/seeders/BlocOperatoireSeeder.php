<?php

namespace Database\Seeders;

use App\Models\Establishment;
use App\Models\KitOperatoire;
use App\Models\SalleOperation;
use Illuminate\Database\Seeder;

/**
 * Salles d'opération et kits, pour chaque établissement.
 *
 * La migration installe la dotation des établissements existants ; ce
 * seeder la pose sur une base neuve, où les établissements naissent après
 * les migrations.
 */
class BlocOperatoireSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Establishment::all() as $etablissement) {
            SalleOperation::installerPour($etablissement);
            KitOperatoire::installerPour($etablissement);
        }
    }
}
