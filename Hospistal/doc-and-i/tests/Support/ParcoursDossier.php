<?php

namespace Tests\Support;

use App\Models\AvenantNote;
use App\Models\LigneOrdonnance;
use App\Models\NoteConsultation;
use App\Models\Ordonnance;
use App\Models\RendezVous;
use App\Support\Empreinte;
use Illuminate\Support\Str;

/**
 * Notes, avenants et ordonnances posés en base pour les tests du dossier
 * (le lot de la note les écrit ; le dossier ne fait que les afficher).
 * À utiliser avec ParcoursRendezVous.
 */
trait ParcoursDossier
{
    protected function noteExistante(RendezVous $rdv, bool $signee = true, string $motif = 'Toux sèche'): NoteConsultation
    {
        $note = new NoteConsultation;
        $note->forceFill([
            'rendez_vous_id' => $rdv->id, 'medecin_id' => $rdv->medecin_id, 'patient_id' => $rdv->patient_id,
            'motif' => $motif, 'conduite' => 'Repos et hydratation',
            'signee_le' => $signee ? now() : null,
            'empreinte' => $signee ? Empreinte::de(['motif' => $motif]) : null,
        ])->save();

        return $note;
    }

    protected function avenantExistant(NoteConsultation $note, string $contenu = 'Précision sur la dose'): AvenantNote
    {
        $avenant = new AvenantNote;
        $avenant->forceFill([
            'note_id' => $note->id, 'auteur_id' => RendezVous::find($note->rendez_vous_id)->medecin->user_id,
            'contenu' => $contenu, 'signe_le' => now(), 'empreinte' => str_repeat('c', 64),
        ])->save();

        return $avenant;
    }

    protected function ordonnanceExistante(RendezVous $rdv, string $medicament = 'Paracétamol'): Ordonnance
    {
        $ordonnance = new Ordonnance;
        $ordonnance->forceFill([
            'numero' => 'OR-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'rendez_vous_id' => $rdv->id, 'medecin_id' => $rdv->medecin_id, 'patient_id' => $rdv->patient_id,
            'signee_le' => now(), 'empreinte' => str_repeat('a', 64), 'code_verification' => Str::random(32),
        ])->save();
        $ligne = new LigneOrdonnance;
        $ligne->forceFill([
            'ordonnance_id' => $ordonnance->id, 'rang' => 1, 'medicament' => $medicament, 'dosage' => '500 mg',
            'voie' => 'orale', 'posologie' => '1 comprimé 3 fois par jour', 'duree' => '3 jours',
        ])->save();

        return $ordonnance;
    }
}
