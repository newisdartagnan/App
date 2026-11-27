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
        // Superviser le serveur : sauvegardes, sessions ouvertes, vidage.
        'maintenance.manage',
        // Lire les rapports d'activité (patients, labo, imagerie, services…).
        'rapport.view',
        // Tenir le registre des sorties d'ambulance.
        'ambulance.manage',
        // Grand dépôt : fournisseurs, commandes, réceptions, inventaires.
        'depot.manage',
        // Gestion financière : saisir une dépense, l'approuver, lire la trésorerie.
        'finance.depense', 'finance.valider', 'finance.view',
    ];

    /**
     * Ce que chaque permission autorise, en clair : la page des rôles
     * l'affiche à côté de la case à cocher.
     */
    public const DESCRIPTIONS = [
        'patient.create' => 'Enregistrer un nouveau patient',
        'patient.view' => 'Voir la liste des patients et ouvrir un dossier',
        'patient.update' => 'Modifier l\'identité d\'un patient (photo, empreintes, relations, carte)',
        'patient.merge' => 'Fusionner deux dossiers en doublon',
        'consultation.create' => 'Consulter un patient : examen clinique, diagnostics, orientation',
        'consultation.view' => 'Lire les consultations et le dossier médical',
        'consultation.validate' => 'Valider une consultation, clôturer un épisode',
        'prescription.create' => 'Prescrire des produits, des soins et des instructions',
        'dispensation.execute' => 'Distribuer les produits prescrits (pharmacie)',
        'stock.view' => 'Voir le stock des produits et intrants',
        'stock.adjust' => 'Ajuster le stock (inventaire, pertes, périmés)',
        'stock.receive' => 'Réceptionner des produits en stock',
        'facture.create' => 'Créer et modifier les factures des patients',
        'paiement.receive' => 'Encaisser un paiement au guichet',
        'rapport.financier.view' => 'Lire les rapports de caisse et de facturation',
        'facture.annuler' => 'Annuler une facture par un avoir',
        'facture.remise' => 'Accorder une remise',
        'sync.manage' => 'Gérer la synchronisation avec le serveur central',
        'user.manage' => 'Gérer les comptes, les rôles et les permissions',
        'settings.manage' => 'Paramétrer l\'établissement : services, locaux, lits, examens, tarifs',
        'labo.create' => 'Demander des examens de laboratoire et d\'imagerie',
        'labo.view' => 'Voir les examens et leurs résultats',
        'labo.validate' => 'Saisir et valider les résultats d\'examens',
        'soin.execute' => 'Administrer les soins, exécuter les instructions, signes vitaux',
        'maintenance.manage' => 'Maintenance : sauvegardes, utilisateurs connectés, vidage des données',
        'rapport.view' => 'Lire les rapports d\'activité (patients, laboratoire, imagerie, services, SNIS)',
        'ambulance.manage' => 'Enregistrer les sorties d\'ambulance',
        'depot.manage' => 'Grand dépôt : fournisseurs, commandes, réceptions, inventaires',
        'finance.depense' => 'Saisir une dépense ou un décaissement',
        'finance.valider' => 'Approuver une dépense, clôturer un mois',
        'finance.view' => 'Lire la trésorerie, le budget et le compte de résultat',
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
                'rapport.view', 'finance.view', 'finance.valider',
            ],
            'medecin' => [
                'patient.view', 'patient.create', 'patient.update',
                'consultation.create', 'consultation.view', 'consultation.validate',
                'prescription.create', 'labo.create', 'labo.view', 'rapport.view',
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
                'depot.manage', 'rapport.view',
            ],
            'caissier' => ['facture.create', 'paiement.receive', 'patient.view', 'ambulance.manage'],
            // Le chef de facturation et de recouvrement corrige les factures
            // et suit ce que les assureurs doivent. Il n'encaisse pas : la
            // main qui reçoit l'argent n'est pas celle qui annule la dette.
            'chef_facturation' => [
                'patient.view', 'facture.create', 'facture.annuler',
                'facture.remise', 'rapport.financier.view', 'finance.view', 'finance.depense',
            ],
            'agent_admin' => [
                'patient.create', 'patient.view', 'patient.update', 'settings.manage',
                'ambulance.manage', 'rapport.view',
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
