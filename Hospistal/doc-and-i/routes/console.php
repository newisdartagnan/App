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
