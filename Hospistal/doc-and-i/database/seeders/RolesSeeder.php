<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Les rôles de la section 2 du cahier des charges. Se rejoue sans doublon. */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (config('doc-and-i.roles') as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
