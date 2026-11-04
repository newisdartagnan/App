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

// RDV-06 : rappels SMS la veille et une heure avant.
Schedule::command('rdv:envoyer-rappels')->everyFiveMinutes()->withoutOverlapping();
