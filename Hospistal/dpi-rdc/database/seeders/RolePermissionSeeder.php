<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'patient.create', 'patient.view', 'patient.update', 'patient.merge',
            'consultation.create', 'consultation.view', 'consultation.validate',
            'prescription.create', 'dispensation.execute',
            'stock.view', 'stock.adjust', 'stock.receive',
            'facture.create', 'paiement.receive', 'rapport.financier.view',
            'sync.manage', 'user.manage', 'settings.manage',
            'labo.create', 'labo.view', 'labo.validate',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $rolePermissions = [
            'super_admin' => $permissions,
            'directeur' => [
                'patient.view', 'consultation.view', 'consultation.validate',
                'rapport.financier.view', 'settings.manage', 'sync.manage',
                'labo.view', 'stock.view',
            ],
            'medecin' => [
                'patient.view', 'patient.create', 'patient.update',
                'consultation.create', 'consultation.view', 'consultation.validate',
                'prescription.create', 'labo.create', 'labo.view',
            ],
            'infirmier_chef' => [
                'patient.view', 'consultation.view', 'prescription.create', 'dispensation.execute',
            ],
            'infirmier' => ['patient.view', 'consultation.view'],
            'laborantin' => ['labo.create', 'labo.view', 'labo.validate', 'patient.view'],
            'pharmacien' => [
                'stock.view', 'stock.adjust', 'stock.receive',
                'dispensation.execute', 'prescription.create', 'patient.view',
            ],
            'caissier' => ['facture.create', 'paiement.receive', 'patient.view'],
            'agent_admin' => [
                'patient.create', 'patient.view', 'patient.update', 'settings.manage',
            ],
        ];

        foreach ($rolePermissions as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }
    }
}
