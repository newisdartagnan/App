<?php

namespace App\Services\Teleconsultation;

use App\Enums\StatutConsultation;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\SignalConsultation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signalisation de l'appel vidéo (TLC-01, TLC-02, TLC-04) : le serveur relaie
 * entre les deux participants les messages de mise en relation WebRTC
 * (offre, réponse, candidats réseau…), par simple interrogation HTTP — pas de
 * WebSocket ni de service de plus, et rien n'est envoyé ailleurs (CSP
 * connect-src 'self'). Ni son ni image ne passent par ici.
 *
 * Chaque requête revérifie l'accès (AccesConsultation) : bon rôle dans CE
 * rendez-vous, rendez-vous confirmé, salle ouverte, dans la fenêtre horaire.
 * Chacun ne lit que les signaux adressés à son rôle. Taille et forme
 * validées ; débit borné par le limiteur « signaux-consultation » des routes.
 */
final class Signalisation
{
    public const TYPES = ['offre', 'reponse', 'candidat', 'raccroche', 'audio_seul', 'pret'];

    /** Signaux rendus par lecture : au-delà, le suivant les lit au tour d'après. */
    public const LIMITE_LECTURE = 100;

    /** Un signal plus vieux ne sert plus à rien (purge planifiée). */
    public const DUREE_VIE_MINUTES = 120;

    public function __construct(private readonly AccesConsultation $acces) {}

    /**
     * Relaie un signal vers l'autre participant. Une même clé renvoyée (requête
     * répétée après un délai dépassé) rend le signal déjà enregistré.
     *
     * @throws AccesRefuse
     * @throws ValidationException
     */
    public function envoyer(Consultation $consultation, User $user, string $type, mixed $charge, ?string $cle = null): SignalConsultation
    {
        $role = $this->verifier($consultation, $user);

        if ($cle !== null && ! Str::isUuid($cle)) {
            throw ValidationException::withMessages(['cle' => 'Clé de signal invalide.']);
        }

        $charge = $this->valider($type, $charge);
        if (strlen(json_encode($charge, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > (int) config('doc-and-i.teleconsultation.signal_octets_max')) {
            throw ValidationException::withMessages(['charge' => 'Signal trop volumineux.']);
        }

        if ($cle !== null && ($existant = $this->parCle($consultation, $user, $cle))) {
            return $existant;
        }

        $signal = new SignalConsultation;
        $signal->forceFill([
            'consultation_id' => $consultation->id,
            'emetteur_id' => $user->id,
            'destinataire' => AccesConsultation::autreRole($role),
            'type' => $type,
            'charge' => $charge,
            'cle' => $cle,
        ]);

        try {
            $signal->save();
        } catch (UniqueConstraintViolationException $e) {
            // Deux envois simultanés de la même clé : l'autre l'a enregistré.
            return $this->parCle($consultation, $user, (string) $cle) ?? throw $e;
        }

        return $signal;
    }

    /**
     * Signaux adressés au rôle de $user, après $apresId.
     *
     * @return array{role: string, statut: string, signaux: list<array{id: int, type: string, charge: array}>}
     *
     * @throws AccesRefuse
     */
    public function recevoir(Consultation $consultation, User $user, int $apresId = 0): array
    {
        $role = $this->verifier($consultation, $user);

        $signaux = SignalConsultation::where('consultation_id', $consultation->id)
            ->where('destinataire', $role)
            ->where('id', '>', $apresId)
            ->orderBy('id')
            ->limit(self::LIMITE_LECTURE)
            ->get(['id', 'type', 'charge'])
            ->map(fn (SignalConsultation $s) => ['id' => $s->id, 'type' => $s->type, 'charge' => $s->charge ?? []])
            ->all();

        return ['role' => $role, 'statut' => $consultation->statut->value, 'signaux' => $signaux];
    }

    /** Dernier signal adressé à $role : un participant qui (re)vient ne relit pas l'historique. */
    public function dernierId(Consultation $consultation, string $role): int
    {
        return (int) SignalConsultation::where('consultation_id', $consultation->id)->where('destinataire', $role)->max('id');
    }

    /** Salle close : ses signaux ne servent plus. */
    public function purger(Consultation $consultation): int
    {
        return SignalConsultation::where('consultation_id', $consultation->id)->delete();
    }

    /** Purge planifiée : signaux des salles closes, des salles dont l'heure est passée, et signaux trop anciens. */
    public function purgerAnciens(): int
    {
        $maintenant = CarbonImmutable::now();
        $apresFin = (int) config('doc-and-i.teleconsultation.fermeture_minutes_apres');

        $consultationsCloses = DB::table('consultations AS c')
            ->join('rendez_vous AS r', 'r.id', '=', 'c.rendez_vous_id')
            ->where(fn ($q) => $q
                ->whereNotIn('c.statut', [StatutConsultation::EnAttente->value, StatutConsultation::EnCours->value])
                ->orWhere('r.fin', '<', $maintenant->subMinutes($apresFin)))
            ->select('c.id');

        return SignalConsultation::whereIn('consultation_id', $consultationsCloses)
            ->orWhere('cree_le', '<', $maintenant->subMinutes(self::DUREE_VIE_MINUTES))
            ->delete();
    }

    /**
     * Accès à la signalisation de cette salle, revérifié à chaque requête.
     *
     * @throws AccesRefuse
     */
    private function verifier(Consultation $consultation, User $user): string
    {
        $consultation->refresh();
        $rdv = $consultation->rendezVous()->firstOrFail();
        $role = $this->acces->role($user, $rdv) ?? throw new AccesRefuse('Cette consultation ne vous concerne pas.');

        if (! $consultation->statut->estOuverte()) {
            throw new AccesRefuse('Cette consultation est close ('.mb_strtolower($consultation->statut->libelle()).').');
        }

        if ($rdv->statut !== StatutRendezVous::Confirme || ! $this->acces->dansLaFenetre($rdv)) {
            throw new AccesRefuse('La salle de consultation est fermée à cette heure.');
        }

        return $role;
    }

    /** Forme de chaque type ; seuls les champs connus sont gardés. */
    private function valider(string $type, mixed $charge): array
    {
        $refus = fn (string $message) => ValidationException::withMessages(['charge' => $message]);
        $charge = is_array($charge) ? $charge : ($charge === null ? [] : throw $refus('Signal mal formé.'));

        return match ($type) {
            'offre', 'reponse' => $this->description($charge, $type === 'offre' ? 'offer' : 'answer', $refus),
            'candidat' => $this->candidat($charge, $refus),
            'audio_seul' => isset($charge['actif'], $charge['raison']) && is_bool($charge['actif']) && in_array($charge['raison'], ['manuel', 'debit'], true)
                ? ['actif' => $charge['actif'], 'raison' => $charge['raison']]
                : throw $refus('Signal « audio seul » mal formé.'),
            'raccroche', 'pret' => $charge === [] ? [] : throw $refus('Ce signal ne porte pas de données.'),
            default => throw ValidationException::withMessages(['type' => 'Type de signal inconnu.']),
        };
    }

    private function description(array $charge, string $attendu, \Closure $refus): array
    {
        if (($charge['type'] ?? null) !== $attendu || ! is_string($charge['sdp'] ?? null) || ! str_starts_with($charge['sdp'], 'v=0')) {
            throw $refus('Description de session mal formée.');
        }

        return ['type' => $attendu, 'sdp' => $charge['sdp']];
    }

    private function candidat(array $charge, \Closure $refus): array
    {
        $candidate = $charge['candidate'] ?? null;
        $mid = $charge['sdpMid'] ?? null;
        $index = $charge['sdpMLineIndex'] ?? null;
        $ufrag = $charge['usernameFragment'] ?? null;

        $valide = is_string($candidate) && ($candidate === '' || str_starts_with($candidate, 'candidate:')) && strlen($candidate) <= 1024
            && ($mid === null || (is_string($mid) && strlen($mid) <= 64))
            && ($index === null || (is_int($index) && $index >= 0 && $index < 64))
            && ($ufrag === null || (is_string($ufrag) && strlen($ufrag) <= 256))
            && ($mid !== null || $index !== null);

        if (! $valide) {
            throw $refus('Candidat réseau mal formé.');
        }

        return ['candidate' => $candidate, 'sdpMid' => $mid, 'sdpMLineIndex' => $index, 'usernameFragment' => $ufrag];
    }

    private function parCle(Consultation $consultation, User $user, string $cle): ?SignalConsultation
    {
        return SignalConsultation::where('consultation_id', $consultation->id)->where('emetteur_id', $user->id)->where('cle', $cle)->first();
    }
}
