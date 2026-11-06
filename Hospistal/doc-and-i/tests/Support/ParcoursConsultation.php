<?php

namespace Tests\Support;

use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Teleconsultation\AccesConsultation;

/**
 * Consultation fictive pour les tests : lundi 7 décembre 2026, 09:00–09:20,
 * heure de Kinshasa. À utiliser avec ParcoursRendezVous et PersonnelDeTest.
 */
trait ParcoursConsultation
{
    protected User $patientRdv;

    protected Medecin $medecinRdv;

    protected RendezVous $rdv;

    protected function preparerConsultation(string $maintenant = '08:55'): void
    {
        $this->voyagerAuLundi($maintenant);
        $this->patientRdv = $this->patient();
        $this->medecinRdv = $this->medecinActive();
        $this->rdv = $this->rendezVousExistant($this->medecinRdv, $this->patientRdv, $this->lundi('09:00'));
    }

    /** Le participant entre dans la salle (comme le fait la page de l'appel). */
    protected function entrer(User $user): Consultation
    {
        return app(AccesConsultation::class)->entrer($user, $this->rdv)[0];
    }

    protected function entrerTousLesDeux(): Consultation
    {
        $this->entrer($this->patientRdv);

        return $this->entrer($this->medecinRdv->user);
    }

    protected function commePatientRdv(): static
    {
        return $this->actingAs($this->patientRdv);
    }

    protected function commeMedecinRdv(): static
    {
        return $this->commePersonnel($this->medecinRdv->user);
    }

    /** Adresse d'un point d'accès patient (« consultation.… ») ou médecin (« personnel.medecin.consultation.… »). */
    protected function adresse(string $route, array $parametres = []): string
    {
        return route($route, ['rendezVous' => $this->rdv->id] + $parametres);
    }
}
