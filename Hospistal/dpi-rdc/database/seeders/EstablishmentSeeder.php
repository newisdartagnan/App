<?php

namespace Database\Seeders;

use App\Models\Establishment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EstablishmentSeeder extends Seeder
{
    public function run(): void
    {
        $code = env('ESTABLISHMENT_CODE', 'HGR_KINSHASA_01');
        $name = env('ESTABLISHMENT_NAME', 'Hôpital Général de Kinshasa');

        $establishment = Establishment::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'type' => 'hopital_general',
                'province' => 'Kinshasa',
                'ville' => 'Kinshasa',
                'is_active' => true,
                'settings' => ['locale' => 'fr', 'currency' => 'CDF'],
            ]
        );

        $urgence = Service::firstOrCreate(
            ['establishment_id' => $establishment->id, 'code' => 'URG'],
            ['nom' => 'Urgences', 'type' => 'urgence', 'capacite_lits' => 20]
        );

        Service::firstOrCreate(
            ['establishment_id' => $establishment->id, 'code' => 'MED'],
            ['nom' => 'Médecine interne', 'type' => 'medecine', 'capacite_lits' => 40]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@dpi-rdc.local'],
            [
                'establishment_id' => $establishment->id,
                'matricule' => 'ADM001',
                'nom' => 'Administrateur',
                'prenom' => 'Système',
                'password' => Hash::make('dpi-admin-2024'),
                'is_active' => true,
            ]
        );

        $admin->assignRole('super_admin');
    }
}
