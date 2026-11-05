<?php

namespace App\Console\Commands;

use App\Services\Personnel\ActivationPersonnelService;
use App\Services\Personnel\ComptePersonnelService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * CPT-05, généralisé : compte d'un membre du personnel (caisse, support…)
 * créé par un administrateur, en attendant l'interface d'administration.
 * Aucun mot de passe n'est créé ni affiché : un lien d'activation à usage
 * unique, à transmettre à la personne seule. Pour un médecin, --specialite
 * et --ordre en plus (même effet que personnel:creer-medecin).
 */
class CreerComptePersonnel extends Command
{
    protected $signature = 'personnel:creer-compte
        {--role= : Rôle du personnel (caisse, support, coordinateur…, ou medecin)}
        {--email= : Courriel professionnel (identifiant de connexion)}
        {--telephone= : Numéro mobile de RDC}
        {--nom= : Nom de famille}
        {--prenom= : Prénom}
        {--specialite= : Médecin seulement : spécialité (slug)}
        {--ordre= : Médecin seulement : numéro d\'inscription à l\'Ordre}';

    protected $description = 'Crée le compte d\'un membre du personnel et affiche son lien d\'activation';

    public function handle(ComptePersonnelService $comptes, ActivationPersonnelService $activation): int
    {
        $identite = [
            'email' => $this->option('email'),
            'telephone' => $this->option('telephone'),
            'nom' => $this->option('nom'),
            'prenom' => $this->option('prenom'),
        ];
        $role = trim((string) $this->option('role'));

        try {
            $user = $role === 'medecin'
                ? $comptes->creerMedecin($identite + ['specialite' => $this->option('specialite'), 'ordre' => $this->option('ordre')])->user
                : $comptes->creerCompte($role, $identite);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $lien = $activation->emettreLien($user);

        $this->info('Compte « '.$role.' » créé pour '.$user->email.'.');
        $this->line('Lien d\'activation, à usage unique, valable '.config('personnel.activation.validite_heures').' heures. À transmettre à la personne seule :');
        $this->line($lien);

        return self::SUCCESS;
    }
}
