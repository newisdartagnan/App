<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Tâches planifiées (service docker « scheduler » : php artisan schedule:work).
*/

// RDV-05 : créneaux non payés à temps libérés.
Schedule::command('rdv:expirer-attentes')->everyMinute()->withoutOverlapping();

// PAY-04 : tentatives de paiement restées sans réponse de l'opérateur.
Schedule::command('paiement:expirer-tentatives')->everyMinute()->withoutOverlapping();

// RDV-06 : rappels SMS la veille et une heure avant.
Schedule::command('rdv:envoyer-rappels')->everyFiveMinutes()->withoutOverlapping();

// PAY-07 : remboursements validés envoyés au prestataire (reprise sans risque : idempotent).
Schedule::command('paiement:executer-remboursements')->everyMinute()->withoutOverlapping();

// PAY-06 : rapprochement de la veille avec le relevé de l'agrégateur, la nuit, heure de Kinshasa.
Schedule::command('paiement:rapprocher')->dailyAt('02:17')->timezone('Africa/Kinshasa')->withoutOverlapping();

// TLC-01 : signaux WebRTC des salles closes ou passées, purgés.
Schedule::command('teleconsultation:purger-signaux')->everyTenMinutes()->withoutOverlapping();

// RG-06, RG-07 : salles fermées sans conclusion (consultation terminée ou absence constatée).
Schedule::command('teleconsultation:constater-salles-fermees')->everyFiveMinutes()->withoutOverlapping();
