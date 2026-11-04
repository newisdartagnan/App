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

            // La simulation confirme des paiements sans argent : en production,
            // ce serait des consultations gratuites pour qui connaît le secret.
            if ($pilote === FournisseurSimulation::NOM && $this->app->isProduction()) {
                throw new RuntimeException('PAIEMENT_FOURNISSEUR=simulation est interdit en production : configurer l\'agrégateur.');
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
    }
}
