<?php

namespace App\Services\RendezVous;

use App\Models\DemandeConsultation;
use App\Models\PieceJointeDemande;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * RDV-01 et RDV-02 : enregistre ce que le patient décrit, ses réponses au tri
 * et ses pièces jointes. Une alerte au tri oriente la demande vers les
 * urgences : aucune réservation possible.
 */
class DemandeConsultationService
{
    /** Disque privé : jamais d'adresse publique, lecture par une route contrôlée. */
    public const DISQUE = 'local';

    public const PIECES_MAX = 5;

    public const TAILLE_MAX_KO = 5120;

    /** Types MIME lus dans le contenu du fichier, et extensions acceptées. */
    public const TYPES_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** « Depuis quand ? » : réponses proposées au patient. */
    public const DUREES = [
        'aujourd_hui' => 'Depuis aujourd\'hui',
        'quelques_jours' => 'Depuis quelques jours',
        'une_a_quatre_semaines' => 'Depuis 1 à 4 semaines',
        'plus_d_un_mois' => 'Depuis plus d\'un mois',
    ];

    /**
     * @param  array{motif: string, symptomes?: ?string, depuis?: ?string, tri: array<string, string>}  $donnees  validées
     * @param  list<UploadedFile>  $pieces  validées
     */
    public function creer(User $patient, array $donnees, array $pieces = []): DemandeConsultation
    {
        $alerte = QuestionnaireTri::alerte($donnees['tri']);
        $enregistres = [];

        try {
            return DB::transaction(function () use ($patient, $donnees, $pieces, $alerte, &$enregistres) {
                if ($pieces !== []) {
                    $this->verifierQuota($patient, $pieces);
                }

                $demande = new DemandeConsultation([
                    'motif' => trim($donnees['motif']),
                    'symptomes' => filled($donnees['symptomes'] ?? null) ? trim($donnees['symptomes']) : null,
                    'depuis' => $donnees['depuis'] ?? null,
                    'tri_reponses' => QuestionnaireTri::trace($donnees['tri']),
                    'tri_alerte' => $alerte,
                ]);
                $demande->patient_id = $patient->id;
                $demande->statut = $alerte ? 'orientee_urgence' : 'ouverte';
                $demande->save();

                foreach ($pieces as $fichier) {
                    // Nom aléatoire, sans extension ni nom d'origine : rien à deviner.
                    $chemin = $fichier->storeAs('pieces-jointes/'.$demande->id, Str::random(40), self::DISQUE);
                    if ($chemin === false) {
                        throw new \RuntimeException('Pièce jointe non enregistrée.');
                    }
                    $enregistres[] = $chemin;

                    $demande->piecesJointes()->create([
                        'chemin' => $chemin,
                        'nom_original' => $this->nomPropre($fichier->getClientOriginalName()),
                        'type_mime' => $fichier->getMimeType(),
                        'taille_octets' => $fichier->getSize(),
                    ]);
                }

                return $demande;
            });
        } catch (Throwable $e) {
            // Base annulée : on ne laisse pas de fichier orphelin sur le disque.
            Storage::disk(self::DISQUE)->delete($enregistres);
            throw $e;
        }
    }

    /**
     * Quota de stockage par patient sur 24 heures glissantes : nombre de
     * demandes portant des fichiers, et volume total. Sous verrou du patient :
     * deux envois simultanés ne dépassent pas le quota ensemble.
     *
     * @param  list<UploadedFile>  $pieces
     *
     * @throws ReservationRefusee
     */
    private function verifierQuota(User $patient, array $pieces): void
    {
        $patient->newQuery()->whereKey($patient->getKey())->lockForUpdate()->first();

        $recentes = PieceJointeDemande::query()
            ->join('demandes_consultation', 'demandes_consultation.id', '=', 'pieces_jointes_demande.demande_id')
            ->where('demandes_consultation.patient_id', $patient->id)
            ->where('pieces_jointes_demande.created_at', '>', CarbonImmutable::now()->subDay());

        $demandes = (clone $recentes)->distinct()->count('pieces_jointes_demande.demande_id');
        $octets = (int) (clone $recentes)->sum('pieces_jointes_demande.taille_octets');
        $nouveaux = array_sum(array_map(fn (UploadedFile $f) => (int) $f->getSize(), $pieces));

        if ($demandes >= (int) config('doc-and-i.pieces_jointes.demandes_par_jour')
            || $octets + $nouveaux > (int) config('doc-and-i.pieces_jointes.octets_par_jour')) {
            throw new ReservationRefusee('Vous avez déjà envoyé beaucoup de fichiers aujourd\'hui. Envoyez votre demande sans fichier, ou réessayez demain.');
        }
    }

    private function nomPropre(string $nom): string
    {
        // Ni séparateur de chemin, ni caractère de contrôle (retours à la ligne
        // compris) : le nom est réaffiché et renvoyé dans un en-tête HTTP.
        $nom = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', str_replace(['/', '\\'], '-', basename($nom))) ?? '');

        return mb_substr($nom === '' ? 'piece-jointe' : $nom, 0, 255);
    }
}
