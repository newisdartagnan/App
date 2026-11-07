<?php

namespace App\Providers;

use App\Services\CodeConnexionService;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\Fournisseur\SignatureRappel;
use App\Sms\EnvoyeurSms;
use App\Sms\EnvoyeurSmsJournal;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnvoyeurSms::class, function () {
            $pilote = config('doc-and-i.sms.driver');

            // Le pilote « log » écrit les codes de connexion dans le journal :
            // en production, quiconque lit les journaux entrerait dans tous les comptes.
            if ($pilote === 'log' && $this->app->isProduction()) {
                throw new RuntimeException('SMS_DRIVER=log est interdit en production : configurer un vrai fournisseur SMS.');
            }

            return match ($pilote) {
                'log' => new EnvoyeurSmsJournal,
                null, '' => throw new InvalidArgumentException('SMS_DRIVER n\'est pas défini (voir .env.example).'),
                default => throw new InvalidArgumentException('Pilote SMS inconnu : '.$pilote.' (voir SMS_DRIVER).'),
            };
        });

        $this->app->singleton(FournisseurPaiement::class, function () {
            $pilote = config('doc-and-i.paiement.fournisseur');

            // La simulation confirme des paiements sans argent : hors du poste
            // du développeur et des tests (production, mais aussi recette ou
            // pilote ouverts à de vrais patients), ce serait des consultations
            // gratuites. Liste blanche, pas liste noire (revue de sécurité).
            if ($pilote === FournisseurSimulation::NOM && ! FournisseurSimulation::environnementAutorise()) {
                throw new RuntimeException('PAIEMENT_FOURNISSEUR=simulation est interdit en production et hors des environnements local et testing : configurer l\'agrégateur.');
            }

            return match ($pilote) {
                FournisseurSimulation::NOM => new FournisseurSimulation(new SignatureRappel(
                    (string) config('doc-and-i.paiement.secret_rappel'),
                    (int) config('doc-and-i.paiement.tolerance_signature_secondes'),
                )),
                null, '' => throw new InvalidArgumentException('PAIEMENT_FOURNISSEUR n\'est pas défini (voir .env.example).'),
                default => throw new InvalidArgumentException('Pilote de paiement inconnu : '.$pilote.' (voir PAIEMENT_FOURNISSEUR).'),
            };
        });
    }

    public function boot(): void
    {
        // Un compteur par route : sans nom, les trois limites partageaient le
        // même compteur par IP (revue de sécurité).
        $parIp = fn (string $nom, int $parMinute) => fn (Request $requete) => Limit::perMinute($parMinute)
            ->by($nom.'|'.CodeConnexionService::cleIp($requete->ip()));

        RateLimiter::for('connexion-demande', $parIp('demande', 30));
        RateLimiter::for('connexion-renvoi', $parIp('renvoi', 15));
        RateLimiter::for('connexion-verification', $parIp('verification', 60));

        // Personnel (CPT-06) : une limite par étape et par IP ; la limite par
        // courriel et le verrouillage du compte sont dans ConnexionPersonnelService.
        $parIpPersonnel = fn (string $nom) => fn (Request $requete) => Limit::perMinute(config('personnel.connexion.tentatives_par_ip_par_minute'))
            ->by('personnel-'.$nom.'|'.CodeConnexionService::cleIp($requete->ip()));

        RateLimiter::for('personnel-connexion', $parIpPersonnel('connexion'));
        RateLimiter::for('personnel-code', $parIpPersonnel('code'));
        RateLimiter::for('personnel-activation', $parIpPersonnel('activation'));

        // Espace administrateur : par compte (revue de sécurité ; le tableau de bord agrège).
        RateLimiter::for('administration', fn (Request $requete) => Limit::perMinute(120)->by('administration|'.$requete->user()?->id));

        // PAY-04 : rappels de l'agrégateur, large (ses serveurs sont peu
        // nombreux et rejouent leurs rappels) mais borné contre l'inondation.
        RateLimiter::for('paiement-rappel', $parIp('paiement-rappel', 300));

        // TLC-01 : signalisation de l'appel vidéo, par utilisateur. Envoi borné
        // (une offre, une réponse, quelques dizaines de candidats par appel) ;
        // lecture : une interrogation toutes les 1,5 seconde environ.
        RateLimiter::for('signaux-consultation', fn (Request $requete) => Limit::perMinute((int) config('doc-and-i.teleconsultation.signaux_par_minute'))
            ->by('signaux-envoi|'.($requete->user()?->id ?? CodeConnexionService::cleIp($requete->ip()))));
        RateLimiter::for('signaux-consultation-lecture', fn (Request $requete) => Limit::perMinute(120)
            ->by('signaux-lecture|'.($requete->user()?->id ?? CodeConnexionService::cleIp($requete->ip()))));
        // Battement de présence : un toutes les presence_intervalle_secondes (15 s), avec de la marge.
        RateLimiter::for('presence-consultation', fn (Request $requete) => Limit::perMinute(12)
            ->by('presence|'.($requete->user()?->id ?? CodeConnexionService::cleIp($requete->ip()))));
        // Page de la salle : l'entrée écrit sous verrou (arrivée, reconnexion).
        RateLimiter::for('salle-consultation', fn (Request $requete) => Limit::perMinute(20)
            ->by('salle|'.($requete->user()?->id ?? CodeConnexionService::cleIp($requete->ip()))));
    }
}
