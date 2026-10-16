<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'patient.create', 'patient.view', 'patient.update', 'patient.merge',
        'consultation.create', 'consultation.view', 'consultation.validate',
        'prescription.create', 'dispensation.execute',
        'stock.view', 'stock.adjust', 'stock.receive',
        'facture.create', 'paiement.receive', 'rapport.financier.view',
        // Annuler tout ou partie d'une facture émise, par un avoir. Ce n'est
        // pas le droit d'encaisser : le caissier ne corrige pas sa caisse.
        'facture.annuler',
        // Accorder une remise : renoncer à une part de la recette. Réservé à
        // qui en répond — jamais à la main qui tient le tiroir.
        'facture.remise',
        'sync.manage', 'user.manage', 'settings.manage',
        'labo.create', 'labo.view', 'labo.validate',
        // Exécuter et tracer un soin : c'est le travail infirmier, et il
        // ne se confond pas avec celui de prescrire.
        'soin.execute',
    ];

    /**
     * Droits de chaque rôle. Lue aussi par les migrations qui reportent sur
     * une installation existante les droits ajoutés depuis.
     *
     * @return array<string, array<int, string>>
     */
    public static function rolesEtDroits(): array
    {
        return [
            'super_admin' => self::PERMISSIONS,
            'directeur' => [
                'patient.view', 'consultation.view', 'consultation.validate',
                'rapport.financier.view', 'settings.manage', 'sync.manage',
                'labo.view', 'stock.view', 'facture.annuler', 'facture.remise',
            ],
            'medecin' => [
                'patient.view', 'patient.create', 'patient.update',
                'consultation.create', 'consultation.view', 'consultation.validate',
                'prescription.create', 'labo.create', 'labo.view',
                // Un médecin de garde pose lui-même une voie à trois heures
                // du matin : lui refuser la trace serait la perdre.
                'soin.execute',
            ],
            'infirmier_chef' => [
                'patient.view', 'consultation.view', 'prescription.create',
                'dispensation.execute', 'soin.execute',
            ],
            // L'infirmier exécute et trace : il ne prescrit pas et ne pose
            // pas de diagnostic. Il lui manquait justement le droit d'écrire
            // ce qu'il fait — le dossier de soins n'était gardé par rien.
            'infirmier' => ['patient.view', 'consultation.view', 'soin.execute'],
            'laborantin' => ['labo.create', 'labo.view', 'labo.validate', 'patient.view'],
            // Manipulateur / radiologue : même plateau technique, côté imagerie.
            // Tant qu'aucun agent ne porte ce rôle, l'imagerie est notifiée au
            // laboratoire (cf. NotificationService::groupePourDomaine).
            'radiologue' => ['labo.create', 'labo.view', 'labo.validate', 'patient.view'],
            'pharmacien' => [
                'stock.view', 'stock.adjust', 'stock.receive',
                'dispensation.execute', 'prescription.create', 'patient.view',
            ],
            'caissier' => ['facture.create', 'paiement.receive', 'patient.view'],
            // Le chef de facturation et de recouvrement corrige les factures
            // et suit ce que les assureurs doivent. Il n'encaisse pas : la
            // main qui reçoit l'argent n'est pas celle qui annule la dette.
            'chef_facturation' => [
                'patient.view', 'facture.create', 'facture.annuler',
                'facture.remise', 'rapport.financier.view',
            ],
            'agent_admin' => [
                'patient.create', 'patient.view', 'patient.update', 'settings.manage',
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (self::rolesEtDroits() as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }
    }
}
