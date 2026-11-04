<?php

namespace Tests\Support;

use App\Enums\StatutRendezVous;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\Specialite;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Services\RendezVous\QuestionnaireTri;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Données fictives du parcours de rendez-vous. Le « maintenant » des tests
 * est fixé par travelTo() : lundi 7 décembre 2026, heure de Kinshasa.
 */
trait ParcoursRendezVous
{
    protected function lundi(string $heure = '07:00'): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-12-07 '.$heure, 'Africa/Kinshasa');
    }

    protected function voyagerAuLundi(string $heure = '07:00'): CarbonImmutable
    {
        $this->travelTo($instant = $this->lundi($heure));

        return $instant;
    }

    /** Patient au profil complet, ayant donné son consentement. */
    protected function patient(): User
    {
        $patient = User::factory()->create();
        $patient->consentements()->create([
            'version' => config('doc-and-i.consentement.version'),
            'accepte_le' => now(),
            'ip' => '127.0.0.1',
        ]);

        return $patient;
    }

    /**
     * Médecin vérifié, avec des plages hebdomadaires [jour ISO, début, fin].
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $plages
     */
    protected function medecin(array $plages = [[1, '09:00', '10:00']], ?Specialite $specialite = null, array $attributs = []): Medecin
    {
        $medecin = Medecin::factory()->create(array_merge(
            $specialite ? ['specialite_id' => $specialite->id] : [],
            $attributs,
        ));

        foreach ($plages as [$jour, $debut, $fin]) {
            $medecin->plages()->create(['jour_semaine' => $jour, 'heure_debut' => $debut, 'heure_fin' => $fin]);
        }

        return $medecin;
    }

    protected function typeConsultation(Medecin|Specialite $pour, ?string $prix = '10.00', int $duree = 20, string $devise = 'USD', bool $actif = true): TypeConsultation
    {
        return TypeConsultation::create([
            'specialite_id' => $pour instanceof Medecin ? $pour->specialite_id : $pour->id,
            'nom' => 'Consultation '.Str::random(6),
            'duree_minutes' => $duree,
            'prix' => $prix,
            'devise' => $devise,
            'actif' => $actif,
        ]);
    }

    protected function demande(User $patient, string $statut = 'ouverte'): DemandeConsultation
    {
        $demande = new DemandeConsultation([
            'motif' => 'Toux depuis une semaine',
            'tri_reponses' => [],
            'tri_alerte' => $statut === 'orientee_urgence',
        ]);
        $demande->patient_id = $patient->id;
        $demande->statut = $statut;
        $demande->save();

        return $demande;
    }

    /** Rendez-vous posé directement en base, sans passer par le service. */
    protected function rendezVousExistant(
        Medecin $medecin,
        User $patient,
        CarbonImmutable $debut,
        int $duree = 20,
        StatutRendezVous $statut = StatutRendezVous::Confirme,
        ?\DateTimeInterface $expireLe = null,
        ?TypeConsultation $type = null,
    ): RendezVous {
        $type ??= $this->typeConsultation($medecin, '10.00', $duree);
        $demande = $this->demande($patient, 'reservee');

        $rdv = new RendezVous;
        $rdv->forceFill([
            'demande_id' => $demande->id,
            'patient_id' => $patient->id,
            'medecin_id' => $medecin->id,
            'type_consultation_id' => $type->id,
            'debut' => $debut,
            'fin' => $debut->addMinutes($duree),
            'statut' => $statut,
            'expire_le' => $statut === StatutRendezVous::EnAttentePaiement ? ($expireLe ?? now()->addMinutes(15)) : $expireLe,
            'prix' => $type->prix,
            'devise' => $type->devise,
        ])->save();

        return $rdv;
    }

    /**
     * Réponses au tri : « non » partout sauf les questions citées.
     *
     * @param  list<string>  $oui
     * @return array<string, string>
     */
    protected function reponsesTri(array $oui = []): array
    {
        $reponses = [];
        foreach (array_keys(QuestionnaireTri::QUESTIONS) as $cle) {
            $reponses[$cle] = in_array($cle, $oui, true) ? 'oui' : 'non';
        }

        return $reponses;
    }

    /** @param  list<CarbonImmutable>  $creneaux */
    protected function heures(array $creneaux): array
    {
        return array_map(fn (CarbonImmutable $c) => $c->format('Y-m-d H:i'), $creneaux);
    }
}
