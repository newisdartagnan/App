<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles libres, photos de profil et nouvelles permissions.
 *
 * L'établissement crée ses propres fonctions (« Accoucheuse », « Archiviste »,
 * « Comptable »…) et leur coche les permissions : le rôle reçoit donc un
 * libellé lisible et une description. Chaque agent peut mettre sa photo.
 * Les permissions nouvelles (rapports, ambulances, grand dépôt, finances,
 * maintenance) sont reportées sur les rôles existants sans toucher aux droits
 * déjà réglés sur le site.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        Schema::table($tables['roles'], function (Blueprint $table) {
            $table->string('libelle', 150)->nullable();
            $table->text('description')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (RolePermissionSeeder::PERMISSIONS as $nom) {
            Permission::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }

        foreach (RolePermissionSeeder::rolesEtDroits() as $role => $droits) {
            $modele = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $manquants = array_values(array_diff($droits, $modele->permissions->pluck('name')->all()));
            if ($manquants !== []) {
                $modele->givePermissionTo($manquants);
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = config('permission.table_names');

        Schema::table($tables['roles'], function (Blueprint $table) {
            $table->dropColumn(['libelle', 'description']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', [
            'maintenance.manage', 'rapport.view', 'ambulance.manage', 'depot.manage',
            'finance.depense', 'finance.valider', 'finance.view',
        ])->where('guard_name', 'web')->delete();
    }
};
