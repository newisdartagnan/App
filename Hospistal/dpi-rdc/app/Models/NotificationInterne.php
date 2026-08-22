<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationInterne extends Model
{
    use HasUuids;

    protected $table = 'notifications_internes';

    protected $fillable = [
        'service', 'type', 'reference_type', 'reference_id', 'code_reference',
        'titre', 'message', 'destinataire_id', 'groupe_destinataire',
        'priorite', 'lu', 'read_at', 'archive',
    ];

    protected function casts(): array
    {
        return [
            'lu' => 'boolean',
            'archive' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_id');
    }

    /**
     * Services qui émettent des notifications, et leur onglet.
     *
     * La liste vit ici et non dans l'écran : un service oublié, et ses
     * notifications n'ont plus ni filtre ni compteur — elles se noient dans
     * « toutes » sans que personne ne s'en aperçoive.
     */
    public const SERVICES = [
        'toutes' => 'Toutes',
        'labo' => '🔬 Labo',
        'imagerie' => '📷 Imagerie',
        'pharmacie' => '💊 Pharmacie',
        'hospitalisation' => '🛏️ Hospitalisation',
        'banque_sang' => '🩸 Banque de sang',
    ];

    /** Couleur d'accentuation de chaque service. */
    public const COULEURS = [
        'labo' => ['border-purple-500', 'bg-purple-100 text-purple-800'],
        'imagerie' => ['border-cyan-500', 'bg-cyan-100 text-cyan-800'],
        'pharmacie' => ['border-green-600', 'bg-green-100 text-green-800'],
        'hospitalisation' => ['border-blue-500', 'bg-blue-100 text-blue-800'],
        'banque_sang' => ['border-red-500', 'bg-red-100 text-red-800'],
    ];

    public static function estUnService(?string $onglet): bool
    {
        return $onglet !== null
            && $onglet !== 'toutes'
            && array_key_exists($onglet, self::SERVICES);
    }

    public function libelleService(): string
    {
        return str_replace('_', ' ', $this->service);
    }

    /**
     * Visibilité : l'administration voit tout, chacun voit les siennes et
     * celles adressées à son rôle (modèle CSK getNotifications).
     */
    public function scopePourUtilisateur(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(['super_admin', 'directeur'])) {
            return $query;
        }

        $roles = $user->getRoleNames()->all();

        return $query->where(function (Builder $q) use ($user, $roles) {
            $q->where('destinataire_id', $user->id)
                ->orWhereIn('groupe_destinataire', array_merge($roles, ['tous']));
        });
    }

    public function scopeNonLues(Builder $query): Builder
    {
        return $query->where('lu', false);
    }

    public function scopeActives(Builder $query): Builder
    {
        return $query->where('archive', false);
    }

    /**
     * Page concernée par la notification.
     */
    /**
     * Où mène la notification.
     *
     * Un résultat d'examen mène au document PDF, et non à l'écran du plateau
     * technique : le médecin qui a prescrit n'a pas forcément accès au
     * laboratoire ni à l'imagerie, et ce qu'il attend c'est son compte rendu.
     * Les demandes de travail, elles, mènent bien à l'écran de production.
     */
    public function lien(): ?string
    {
        if ($this->reference_type === 'examen') {
            return $this->type === 'resultat_pret'
                ? route('examens.pdf', $this->reference_id)
                : route('labo.show', $this->reference_id);
        }

        return match ($this->reference_type) {
            'prescription' => route('pharmacie.prescription', $this->reference_id),
            // Une poche délivrée ou une demande refusée renvoie à la demande :
            // c'est là que le prescripteur clôture sa transfusion ou relance.
            'demande_sang' => route('banque-sang.demande', $this->reference_id),
            // Un incident transfusionnel mène au registre : c'est là que se
            // lisent la poche, son donneur et les autres poches du même don,
            // qu'il faudra peut-être bloquer.
            'transfusion' => route('banque-sang.registre'),
            // Les alertes de soins nomment le pansement ou l'évaluation, pas
            // le séjour : on remonte au dossier du patient, seul endroit où le
            // médecin peut faire quelque chose de l'information.
            'pansement' => $this->lienVersLeSejour(SoinPansement::class),
            'gavage' => $this->lienVersLeSejour(SoinGavage::class),
            'evaluation_neuro' => $this->lienVersLeSejour(EvaluationNeuro::class),
            'transfert_service' => $this->lienVersLeSejour(TransfertService::class),
            default => null,
        };
    }

    /**
     * Dossier du séjour auquel se rattache un soin.
     *
     * Le dossier de service quand le patient est dans un service, la fiche de
     * séjour sinon : un transfert enregistré aux urgences n'a pas encore de
     * service d'accueil.
     *
     * @param  class-string<Model>  $modele
     */
    private function lienVersLeSejour(string $modele): ?string
    {
        if (! $this->reference_id) {
            return null;
        }

        $visiteId = $modele::whereKey($this->reference_id)->value('visit_id');

        if (! $visiteId) {
            return null;
        }

        $serviceId = Visit::whereKey($visiteId)->value('service_id');

        return $serviceId
            ? route('services.dossier', [$serviceId, $visiteId])
            : route('visites.show', $visiteId);
    }

    /** Le lien ouvre-t-il un document plutôt qu'un écran de l'application ? */
    public function lienEstDocument(): bool
    {
        return $this->reference_type === 'examen' && $this->type === 'resultat_pret';
    }
}
