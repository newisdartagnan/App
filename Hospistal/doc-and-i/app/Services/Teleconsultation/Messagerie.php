<?php

namespace App\Services\Teleconsultation;

use App\Models\Consultation;
use App\Models\MessageConsultation;
use App\Models\RendezVous;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Messagerie de la consultation (TLC-03) : texte ou photo, entre le patient
 * du rendez-vous et son médecin, personne d'autre.
 *
 * - Chaque appel vérifie la relation de soin (AccesConsultation::role).
 * - Écriture : pendant la fenêtre de la salle, salle ouverte, débit borné.
 * - Lecture du fil : aussi après la consultation (salle close), par les deux mêmes.
 * - Photo : JPEG, PNG ou WebP, type lu dans le CONTENU (finfo) et image
 *   réellement décodable, taille bornée ; disque privé, nom aléatoire,
 *   servie seulement par une route qui revérifie l'accès.
 * - Un message est une donnée du dossier : ni modification ni suppression.
 */
final class Messagerie
{
    public const DISQUE = 'local';

    public const TEXTE_MAX = 2000;

    public const TYPES_PHOTO = ['image/jpeg', 'image/png', 'image/webp'];

    /** Messages par participant, par consultation et par minute. */
    public const MESSAGES_PAR_MINUTE = 20;

    /** Au plus ce nombre de messages par lecture (le client redemande la suite). */
    private const PAGE = 100;

    public function __construct(private readonly AccesConsultation $acces) {}

    /**
     * Fil de la consultation, après le message $apres (polling) ou depuis le début.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AccesRefuse
     */
    public function lister(User $user, RendezVous $rdv, ?string $apres = null): array
    {
        [$consultation, $role] = $this->lisible($user, $rdv);

        // Le rang, posé par la base, fixe l'ordre (l'horodatage est à la seconde).
        $requete = MessageConsultation::where('consultation_id', $consultation->id)->orderBy('rang');
        if ($apres !== null && ($repere = MessageConsultation::where('consultation_id', $consultation->id)->find($apres)) !== null) {
            $requete->where('rang', '>', $repere->rang);
        }

        return $requete->limit(self::PAGE)->get()
            ->map(fn (MessageConsultation $m) => $this->presenter($m, $user, $rdv))->all();
    }

    /** @throws AccesRefuse */
    public function envoyerTexte(User $user, RendezVous $rdv, string $texte): array
    {
        $texte = trim($texte);
        if ($texte === '' || mb_strlen($texte) > self::TEXTE_MAX) {
            throw new AccesRefuse('Le message doit faire entre 1 et '.self::TEXTE_MAX.' caractères.');
        }

        $consultation = $this->inscriptible($user, $rdv);

        return $this->presenter($this->creer($consultation, $user, ['texte' => $texte]), $user, $rdv);
    }

    /** @throws AccesRefuse */
    public function envoyerPhoto(User $user, RendezVous $rdv, UploadedFile $photo): array
    {
        $type = self::verifierPhoto($photo);
        $consultation = $this->inscriptible($user, $rdv);

        // Nom aléatoire, sans extension ni nom d'origine : rien à deviner.
        $chemin = $photo->storeAs('consultations/'.$consultation->id, Str::random(40), self::DISQUE);
        if ($chemin === false) {
            throw new AccesRefuse('La photo n\'a pas pu être enregistrée. Réessayez.');
        }

        try {
            $message = $this->creer($consultation, $user, ['piece_chemin' => $chemin, 'piece_type' => $type, 'piece_taille' => $photo->getSize()]);
        } catch (\Throwable $e) {
            Storage::disk(self::DISQUE)->delete($chemin);
            throw $e;
        }

        return $this->presenter($message, $user, $rdv);
    }

    /** La photo d'un message, à qui peut lire le fil, jamais en cache. @throws AccesRefuse */
    public function photo(User $user, RendezVous $rdv, string $messageId): StreamedResponse
    {
        [$consultation] = $this->lisible($user, $rdv);
        $message = MessageConsultation::where('consultation_id', $consultation->id)->whereNotNull('piece_chemin')->find($messageId)
            ?? throw new AccesRefuse('Photo introuvable.');

        $disque = Storage::disk(self::DISQUE);
        if (! $disque->exists($message->piece_chemin)) {
            throw new AccesRefuse('Photo introuvable.');
        }

        return $disque->response($message->piece_chemin, 'photo', [
            'Content-Type' => $message->piece_type,
            'Content-Disposition' => 'inline; filename="photo"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Type réel de la photo, lu dans son contenu ; refusée si ce n'est pas
     * une image JPEG, PNG ou WebP décodable, ou si elle est trop lourde.
     *
     * @throws AccesRefuse
     */
    public static function verifierPhoto(UploadedFile $photo): string
    {
        if (! $photo->isValid()) {
            throw new AccesRefuse('La photo n\'a pas été reçue en entier. Réessayez.');
        }

        if ($photo->getSize() > (int) config('doc-and-i.teleconsultation.photo_octets_max')) {
            throw new AccesRefuse('Photo trop lourde : '.intdiv((int) config('doc-and-i.teleconsultation.photo_octets_max'), 1024 * 1024).' Mo au plus.');
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($photo->getRealPath());
        $image = @getimagesize($photo->getRealPath());
        if (! in_array($type, self::TYPES_PHOTO, true) || $image === false || ($image['mime'] ?? null) !== $type) {
            throw new AccesRefuse('Seules les photos JPEG, PNG ou WebP sont acceptées.');
        }

        return $type;
    }

    /** @return array{0: Consultation, 1: string} @throws AccesRefuse */
    private function lisible(User $user, RendezVous $rdv): array
    {
        $role = $this->acces->role($user, $rdv) ?? throw new AccesRefuse('Cette consultation ne vous concerne pas.');
        $consultation = Consultation::where('rendez_vous_id', $rdv->id)->first()
            ?? throw new AccesRefuse('La salle de cette consultation n\'est pas encore ouverte.');

        return [$consultation, $role];
    }

    /** @throws AccesRefuse */
    private function inscriptible(User $user, RendezVous $rdv): Consultation
    {
        [$consultation] = $this->lisible($user, $rdv);

        if (! $consultation->statut->estOuverte()) {
            throw new AccesRefuse('Cette consultation est close : le fil reste lisible, mais on n\'y écrit plus.');
        }

        if (! $this->acces->dansLaFenetre($rdv)) {
            throw new AccesRefuse('La salle de cette consultation est fermée.');
        }

        $cle = 'tlc-messages:'.$consultation->id.'|'.$user->id;
        if (RateLimiter::tooManyAttempts($cle, self::MESSAGES_PAR_MINUTE)) {
            throw new AccesRefuse('Trop de messages en peu de temps : patientez quelques secondes.');
        }
        RateLimiter::hit($cle, 60);

        return $consultation;
    }

    private function creer(Consultation $consultation, User $auteur, array $contenu): MessageConsultation
    {
        $message = new MessageConsultation;
        $message->forceFill($contenu + [
            'id' => (string) Str::uuid(),
            'consultation_id' => $consultation->id,
            'auteur_id' => $auteur->id,
            'cree_le' => CarbonImmutable::now(),
        ])->save();

        return $message;
    }

    private function presenter(MessageConsultation $m, User $lecteur, RendezVous $rdv): array
    {
        $auteurEstPatient = $m->auteur_id === $rdv->patient_id;

        return [
            'id' => $m->id,
            'auteur' => $auteurEstPatient ? AccesConsultation::PATIENT : AccesConsultation::MEDECIN,
            'moi' => $m->auteur_id === $lecteur->id,
            'texte' => $m->texte,
            'photo' => $m->piece_chemin !== null,
            'heure' => $m->cree_le->setTimezone('Africa/Kinshasa')->format('H:i'),
        ];
    }
}
