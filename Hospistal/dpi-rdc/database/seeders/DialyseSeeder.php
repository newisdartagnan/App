<?php

namespace Database\Seeders;

use App\Models\Establishment;
use App\Models\GenerateurDialyse;
use Illuminate\Database\Seeder;

/** Générateurs de l'unité de dialyse, pour chaque établissement. */
class DialyseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Establishment::all() as $etablissement) {
            GenerateurDialyse::installerPour($etablissement);
        }
    }
}
