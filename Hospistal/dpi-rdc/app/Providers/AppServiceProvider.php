<?php

namespace App\Providers;

use App\Models\Avoir;
use App\Models\BonSortie;
use App\Models\Caution;
use App\Models\Consultation;
use App\Models\Facture;
use App\Models\FactureConvention;
use App\Models\FactureTiersPayant;
use App\Models\ImputationAcompte;
use App\Models\LigneAvoir;
use App\Models\LigneFacture;
use App\Models\LigneFactureConvention;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\ReglementConvention;
use App\Models\Visit;
use App\Observers\AuditObserver;
use App\Observers\JournalFinancierObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Le journal d'activité : chaque geste clinique garde son auteur.
        // C'est lui que lit la page « Activités » d'un utilisateur.
        foreach ([
            Patient::class, Visit::class, Consultation::class,
            \App\Models\Prescription::class, \App\Models\ExamenLaboratoire::class,
            \App\Models\Dispensation::class, \App\Models\SigneVital::class,
            \App\Models\DocumentClinique::class, \App\Models\AdministrationTraitement::class,
            \App\Models\Vaccination::class, \App\Models\RendezVous::class,
            \App\Models\NoteEvolution::class, \App\Models\ActeClinique::class,
            \App\Models\TransfertService::class,
        ] as $modele) {
            $modele::observe(AuditObserver::class);
        }

        // Les pièces de caisse ont leur propre journal, écrit sans délai.
        foreach ([
            Facture::class, LigneFacture::class, FactureTiersPayant::class,
            Paiement::class, Caution::class, ImputationAcompte::class,
            Avoir::class, LigneAvoir::class, BonSortie::class,
            FactureConvention::class, LigneFactureConvention::class,
            ReglementConvention::class,
        ] as $modele) {
            $modele::observe(JournalFinancierObserver::class);
        }
    }
}
