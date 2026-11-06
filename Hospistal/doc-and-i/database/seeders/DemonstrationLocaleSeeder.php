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
 * des plages, des prix indicatifs et deux agents de caisse. Jamais exécuté hors APP_ENV=local
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

        // Deux agents de caisse fictifs : de quoi essayer les quatre yeux
        // (l'un demande ou prépare, l'autre valide ou paie). Comme les médecins
        // de démonstration, à activer : php artisan personnel:lien-activation <courriel>.
        $caisse = [
            ['caisse1.demo@doc-and-i.local', '+243810000011', 'KABONGO', 'Rose'],
            ['caisse2.demo@doc-and-i.local', '+243810000012', 'MBUYI', 'Patrick'],
        ];

        foreach ($caisse as [$email, $telephone, $nom, $prenom]) {
            $user = User::where('email', $email)->first() ?? tap(new User)->forceFill([
                'email' => $email,
                'telephone' => $telephone,
                'telephone_verifie_le' => now(),
                'nom' => $nom,
                'prenom' => $prenom,
            ]);
            $user->save();
            $user->syncRoles([Role::findOrCreate('caisse', 'web')]);
        }

        // Administrateur fonctionnel de démonstration (ADM-01, ADM-02), au nom
        // de l'administrateur désigné par le client ; courriel et numéro fictifs.
        $admin = User::where('email', 'admin.demo@doc-and-i.local')->first() ?? tap(new User)->forceFill([
            'email' => 'admin.demo@doc-and-i.local',
            'telephone' => '+243810000021',
            'telephone_verifie_le' => now(),
            'nom' => 'IKULA',
            'prenom' => 'Boris',
        ]);
        $admin->save();
        $admin->syncRoles([Role::findOrCreate('admin_fonctionnel', 'web')]);
    }
}
