<?php

namespace Database\Seeders;

use App\Models\Medecin;
use App\Models\Specialite;
use App\Models\TypeConsultation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Données de DÉMONSTRATION pour tester en local : deux médecins fictifs avec
 * des plages, et des prix indicatifs. Jamais exécuté hors APP_ENV=local
 * (voir DatabaseSeeder) : aucune donnée fictive n'atteint une base réelle.
 */
class DemonstrationLocaleSeeder extends Seeder
{
    public function run(): void
    {
        TypeConsultation::whereNull('prix')->update(['prix' => 10]);

        $medecins = [
            ['medecin.demo@doc-and-i.local', '+243810000001', 'MUKENDI', 'Pauline', 'medecine-generale', 'CNOM-DEMO-0001'],
            ['pediatre.demo@doc-and-i.local', '+243810000002', 'LUKUSA', 'Albert', 'pediatrie', 'CNOM-DEMO-0002'],
        ];

        foreach ($medecins as [$email, $telephone, $nom, $prenom, $specialite, $ordre]) {
            $user = User::where('email', $email)->first() ?? tap(new User)->forceFill([
                'email' => $email,
                'telephone' => $telephone,
                'telephone_verifie_le' => now(),
                'nom' => $nom,
                'prenom' => $prenom,
                'sexe' => 'F',
                'date_naissance' => '1980-01-01',
                'password' => 'doc-and-i-demo',
            ]);
            $user->save();
            $user->syncRoles([Role::findOrCreate('medecin', 'web')]);

            $medecin = Medecin::firstOrNew(['user_id' => $user->id]);
            $medecin->fill([
                'specialite_id' => Specialite::where('slug', $specialite)->value('id'),
                'numero_ordre' => $ordre,
                'langues' => ['fr', 'ln'],
                'actif' => true,
            ]);
            $medecin->user_id = $user->id;
            $medecin->ordre_verifie_le ??= now();
            $medecin->save();

            if ($medecin->plages()->doesntExist()) {
                foreach (range(1, 5) as $jour) {
                    $medecin->plages()->create(['jour_semaine' => $jour, 'heure_debut' => '09:00', 'heure_fin' => '12:00']);
                    $medecin->plages()->create(['jour_semaine' => $jour, 'heure_debut' => '14:00', 'heure_fin' => '17:00']);
                }
            }
        }
    }
}
