<?php

namespace App\Console\Commands;

use App\Services\Personnel\ActivationPersonnelService;
use App\Services\Personnel\ComptePersonnelService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * CPT-05 : création du compte d'un médecin par un administrateur, en
 * attendant l'interface d'administration. Aucun mot de passe n'est créé ni
 * affiché : le médecin le choisit lui-même par le lien d'activation.
 */
class CreerMedecin extends Command
{
    protected $signature = 'personnel:creer-medecin
        {--email= : Courriel professionnel (identifiant de connexion)}
        {--telephone= : Numéro mobile de RDC}
        {--nom= : Nom de famille}
        {--prenom= : Prénom}
        {--specialite= : Spécialité (slug, ex. medecine-generale)}
        {--ordre= : Numéro d\'inscription à l\'Ordre des médecins}';

    protected $description = 'Crée le compte d\'un médecin (non vérifié à l\'Ordre) et affiche son lien d\'activation';

    public function handle(ComptePersonnelService $comptes, ActivationPersonnelService $activation): int
    {
        try {
            $medecin = $comptes->creerMedecin([
                'email' => $this->option('email'),
                'telephone' => $this->option('telephone'),
                'nom' => $this->option('nom'),
                'prenom' => $this->option('prenom'),
                'specialite' => $this->option('specialite'),
                'ordre' => $this->option('ordre'),
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $lien = $activation->emettreLien($medecin->user);

        $this->info('Compte créé pour '.$medecin->user->email.'. Il n\'est pas proposé aux patients avant la vérification à l\'Ordre (personnel:verifier-ordre).');
        $this->line('Lien d\'activation, à usage unique, valable '.config('personnel.activation.validite_heures').' heures. À transmettre au médecin seul :');
        $this->line($lien);

        return self::SUCCESS;
    }
}
