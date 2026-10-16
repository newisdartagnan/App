<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * La remise, réservée à ceux qui en répondent.
 *
 * Le caissier encaisse ; il n'accorde pas de remise. Une remise est une
 * recette à laquelle l'hôpital renonce : elle se décide par le chef de
 * facturation, dans la limite que la direction lui fixe, ou par la
 * direction elle-même. Elle prend la forme d'un avoir de type « remise »,
 * qui ne touche que la part du patient : l'assureur paie ce que prévoit sa
 * convention, une faveur faite au patient ne le regarde pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avoirs', function (Blueprint $table) {
            // Le pourcentage accordé, quand la remise en est un : « 10 % »
            // se lit mieux sur la facture qu'un montant arrondi.
            $table->decimal('taux_remise', 5, 2)->nullable()->after('montant_assurance');
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
        Schema::table('avoirs', function (Blueprint $table) {
            $table->dropColumn('taux_remise');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'facture.remise')->where('guard_name', 'web')->delete();
    }
};
