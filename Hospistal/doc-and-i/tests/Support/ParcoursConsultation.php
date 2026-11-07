<?php

namespace Tests\Support;

use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\Presence;

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

    /**
     * Les deux navigateurs connectés ensemble (battements de présence, comme
     * l'appel vidéo les envoie), pendant $secondes de temps réel partagé.
     * Les deux participants doivent être entrés.
     */
    protected function connectesEnsemble(int $secondes = 0, ?RendezVous $rdv = null): Consultation
    {
        $rdv ??= $this->rdv;
        $presence = app(Presence::class);
        $consultation = Consultation::where('rendez_vous_id', $rdv->id)->firstOrFail();
        $medecin = $rdv->medecin->user;

        $presence->battement($consultation, $medecin);
        $presence->battement($consultation, $rdv->patient);
        for ($restant = $secondes; $restant > 0; $restant -= 15) {
            $this->travel(min(15, $restant))->seconds();
            $presence->battement($consultation, $medecin);
            $presence->battement($consultation, $rdv->patient);
        }

        return $consultation->fresh();
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
