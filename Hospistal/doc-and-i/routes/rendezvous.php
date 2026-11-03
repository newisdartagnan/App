<?php

/*
| Parcours de rendez-vous du patient (RDV-01 à RDV-07).
| Inclus depuis routes/web.php, à l'intérieur du groupe
| auth + role:patient + profil.complet + consentement : chaque route ici
| est déjà réservée à un patient connecté, au profil complet et consentant.
| Préfixer les noms de routes par « rdv. ».
*/

use Illuminate\Support\Facades\Route;
