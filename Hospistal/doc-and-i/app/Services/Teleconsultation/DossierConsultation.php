<?php

namespace App\Services\Teleconsultation;

use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\PieceJointeDemande;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Dossier\JournalAccesDossier;
use App\Services\Dossier\LectureDossier;
use App\Services\RendezVous\Affichage;
use App\Services\RendezVous\DemandeConsultationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dossier du patient pendant l'appel (TLC-05), pour le MÉDECIN DE CE
 * RENDEZ-VOUS seulement : identité utile (âge, sexe, commune ; jamais le
 * téléphone), la demande et ses réponses au tri, les pièces jointes, les
 * rendez-vous passés du patient avec ce médecin, et l'essentiel du dossier
 * médical (DOS-01). Chaque lecture va au journal des accès au dossier.
 *
 * Ouvert seulement pendant l'appel : rendez-vous confirmé, salle créée,
 * dans sa fenêtre horaire. Ensuite, plus rien (le dossier complet relève
 * du lot dossier médical).
 */
final class DossierConsultation
{
    public function __construct(
        private readonly AccesConsultation $acces,
        private readonly JournalAccesDossier $journal,
        private readonly LectureDossier $lecture,
    ) {}

    /** @throws AccesRefuse */
    public function pour(User $medecin, RendezVous $rdv): array
    {
        $this->verifier($medecin, $rdv);
        // Journal des accès au dossier (revue de sécurité) : le patient voit cette lecture.
        $this->journal->noter($medecin, $rdv->patient_id, 'dossier_appel', $rdv->id);
        $rdv->loadMissing(['patient', 'demande.piecesJointes']);
        $patient = $rdv->patient;
        $demande = $rdv->demande;

        return [
            'patient' => [
                'prenom' => $patient->prenom,
                'nom' => mb_strtoupper((string) $patient->nom),
                'age' => $patient->date_naissance ? (int) $patient->date_naissance->diffInYears(CarbonImmutable::now()) : null,
                'sexe' => match ($patient->sexe) {
                    'F' => 'Femme', 'M' => 'Homme', default => null
                },
                'commune' => $patient->commune,
            ],
            'demande' => [
                'motif' => $demande->motif,
                'symptomes' => $demande->symptomes,
                'depuis' => DemandeConsultationService::DUREES[$demande->depuis] ?? null,
                'alerte' => (bool) $demande->tri_alerte,
                'tri' => array_map(fn (array $r) => ['question' => $r['question'], 'reponse' => $r['reponse']], $demande->tri_reponses['reponses'] ?? []),
            ],
            'piecesJointes' => $demande->piecesJointes->map(fn (PieceJointeDemande $p) => [
                'id' => $p->id,
                'nom' => $p->nom_original,
                'type' => $p->type_mime,
                'taille' => $p->taille_octets,
                'image' => str_starts_with((string) $p->type_mime, 'image/'),
            ])->values()->all(),
            'historique' => RendezVous::with('demande:id,motif')
                ->where('patient_id', $rdv->patient_id)->where('medecin_id', $rdv->medecin_id)
                ->whereKeyNot($rdv->id)->where('debut', '<', CarbonImmutable::now())
                // Relations de soin seulement : ni créneau non payé, ni annulation, ni absence.
                ->whereIn('statut', [StatutRendezVous::Confirme->value, StatutRendezVous::Termine->value])
                ->orderByDesc('debut')->limit(20)->get()
                ->map(fn (RendezVous $r) => [
                    'id' => $r->id,
                    'date' => Affichage::dateHeure($r->debut),
                    'statut' => $r->statut->libelle(),
                    'motif' => $r->demande?->motif,
                ])->all(),
            // Dossier médical (DOS-01) : allergies, traitements actifs, antécédents,
            // dernières constantes, groupe sanguin (vide = inconnu).
            'antecedents' => $this->lecture->resumePourAppel($patient),
        ];
    }

    /** Une pièce jointe de la demande de CE rendez-vous, au médecin de ce rendez-vous. @throws AccesRefuse */
    public function pieceJointe(User $medecin, RendezVous $rdv, string $pieceId): StreamedResponse
    {
        $this->verifier($medecin, $rdv);
        $piece = PieceJointeDemande::where('demande_id', $rdv->demande_id)->find($pieceId)
            ?? throw new AccesRefuse('Pièce jointe introuvable.');

        $disque = Storage::disk(DemandeConsultationService::DISQUE);
        if (! $disque->exists($piece->chemin)) {
            throw new AccesRefuse('Pièce jointe introuvable.');
        }
        $this->journal->noter($medecin, $rdv->patient_id, 'piece_jointe_appel', $piece->id);

        return $disque->download($piece->chemin, $piece->nom_original, [
            'Content-Type' => $piece->type_mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @throws AccesRefuse */
    private function verifier(User $medecin, RendezVous $rdv): void
    {
        if ($this->acces->role($medecin, $rdv) !== AccesConsultation::MEDECIN) {
            throw new AccesRefuse('Ce dossier ne vous est pas ouvert.');
        }

        // Revue de sécurité : le dossier n'est ouvert que PENDANT l'appel —
        // rendez-vous confirmé, salle créée, dans sa fenêtre horaire. Le dossier
        // complet hors appel relève du dossier médical (DOS-01), avec son propre contrôle.
        if ($rdv->statut !== StatutRendezVous::Confirme) {
            throw new AccesRefuse('Ce rendez-vous n\'ouvre pas le dossier ('.mb_strtolower($rdv->statut->libelle()).').');
        }

        if (! Consultation::where('rendez_vous_id', $rdv->id)->exists() || ! $this->acces->dansLaFenetre($rdv)) {
            throw new AccesRefuse('Le dossier s\'ouvre pendant la consultation, quand la salle est ouverte.');
        }
    }
}
