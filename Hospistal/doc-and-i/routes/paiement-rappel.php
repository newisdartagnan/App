<?php

/*
| Rappel serveur de l'agrégateur de paiement (PAY-04). Inclus depuis
| routes/web.php HORS des groupes du patient : l'opérateur n'a ni session
| ni jeton CSRF (exemption dans bootstrap/app.php). La signature HMAC du
| rappel fait foi ; limite de débit nommée « paiement-rappel ».
*/

use App\Http\Controllers\Paiement\RappelPaiementController;
use Illuminate\Support\Facades\Route;

Route::post('/paiement/rappel/{fournisseur}', RappelPaiementController::class)
    ->where('fournisseur', '[a-z0-9_-]{1,30}')
    ->middleware('throttle:paiement-rappel')
    ->name('paiement.rappel');
