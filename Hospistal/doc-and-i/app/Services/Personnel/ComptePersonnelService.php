<?php

namespace App\Services\Personnel;

use App\Enums\StatutRendezVous;
use App\Models\AuthentificationPersonnel;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\Specialite;
use App\Models\User;
use App\Services\Administration\JournalAdministration;
use App\Services\Paiement\PaiementRefuse;
use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use App\Sms\EnvoyeurSms;
use App\Support\Telephone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Comptes du personnel (CPT-05, CPT-06, ADM-01) : création par commande ou
 * depuis l'espace administrateur, vérification à l'Ordre, profil du
 * médecin, désactivation et réactivation.
 */
final class ComptePersonnelService
{
    /**
     * Crée le compte (rôle medecin, sans mot de passe) et le profil médecin
     * NON vérifié à l'Ordre : il n'est pas proposé aux patients.
     *
     * @param  array{email: ?string, telephone: ?string, nom: ?string, prenom: ?string, specialite: ?string, ordre: ?string}  $donnees
     *
     * @throws ValidationException
     */
    public function creerMedecin(array $donnees): Medecin
    {
        $donnees['ordre'] = trim((string) ($donnees['ordre'] ?? ''));

        $valides = $this->validerIdentite($donnees, [
            'specialite' => ['required', Rule::exists('specialites', 'slug')->where('active', true)],
            'ordre' => ['required', 'string', 'max:50', Rule::unique('medecins', 'numero_ordre')],
        ]);

        return DB::transaction(function () use ($valides) {
            $user = $this->creerUtilisateur($valides, 'medecin');

            $medecin = new Medecin([
                'specialite_id' => Specialite::where('slug', $valides['specialite'])->value('id'),
                'numero_ordre' => $valides['ordre'],
                'actif' => true,
            ]);
            $medecin->user_id = $user->id;
            $medecin->save();

            return $medecin;
        });
    }

    /**
     * Compte d'un autre rôle du personnel (caisse, support, coordination…),
     * sans mot de passe : la personne l'active par son lien. Le médecin a sa
     * propre création (profil, Ordre) ; le patient s'inscrit lui-même.
     *
     * Un compte « laboratoire » peut être rattaché à un laboratoire partenaire
     * actif (partenaire_id) ; l'écran d'administration l'exige.
     *
     * @param  array{email: ?string, telephone: ?string, nom: ?string, prenom: ?string, partenaire_id?: ?string}  $donnees
     *
     * @throws ValidationException
     */
    public function creerCompte(string $role, array $donnees): User
    {
        if (! in_array($role, self::rolesSansProfil(), true)) {
            throw ValidationException::withMessages([
                'role' => 'Rôle inconnu ou à créer autrement : « '.$role.' » (rôles possibles : '.implode(', ', self::rolesSansProfil()).' ; médecin : avec --specialite et --ordre).',
            ]);
        }

        $partenaire = $role === 'laboratoire' && filled($donnees['partenaire_id'] ?? null)
            ? ['partenaire_id' => ['uuid', Rule::exists('partenaires', 'id')->where('type', 'laboratoire')->where('actif', true)]]
            : [];
        $valides = $this->validerIdentite($donnees, $partenaire);

        return DB::transaction(function () use ($valides, $role) {
            $user = $this->creerUtilisateur($valides, $role);

            if (isset($valides['partenaire_id'])) {
                DB::table('comptes_laboratoire')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'partenaire_id' => $valides['partenaire_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $user;
        });
    }

    /** Rôles du personnel créés par creerCompte() : tous sauf patient et médecin. */
    public static function rolesSansProfil(): array
    {
        return array_values(array_diff(config('doc-and-i.roles'), ['patient', 'medecin']));
    }

    /** @throws ValidationException */
    private function validerIdentite(array $donnees, array $reglesEnPlus): array
    {
        $donnees['email'] = mb_strtolower(trim((string) ($donnees['email'] ?? '')));
        $donnees['telephone'] = Telephone::normaliser($donnees['telephone'] ?? null) ?? ($donnees['telephone'] ?? null);

        return Validator::make($donnees, [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'telephone' => ['required', 'regex:/^\+243[89]\d{8}$/', Rule::unique('users', 'telephone')],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
        ] + $reglesEnPlus, [
            'telephone.regex' => 'Le téléphone doit être un numéro mobile de RDC.',
            'telephone.unique' => 'Ce numéro appartient déjà à un compte.',
            'email.unique' => 'Ce courriel appartient déjà à un compte.',
            'ordre.unique' => 'Ce numéro d\'Ordre est déjà enregistré.',
            'specialite.exists' => 'Spécialité inconnue ou inactive.',
            'partenaire_id.exists' => 'Laboratoire partenaire inconnu ou inactif.',
        ])->validate();
    }

    private function creerUtilisateur(array $valides, string $role): User
    {
        $user = (new User)->forceFill([
            'email' => $valides['email'],
            'telephone' => $valides['telephone'],
            'nom' => mb_strtoupper(trim($valides['nom'])),
            'prenom' => trim($valides['prenom']),
        ]);
        $user->save();
        $user->assignRole($role);

        return $user;
    }

    /** Pose la date de vérification à l'Ordre ; une date déjà posée est gardée. */
    public function verifierOrdre(string $email): Medecin
    {
        $medecin = Medecin::whereHas('user', fn ($q) => $q->where('email', mb_strtolower(trim($email))))->first();

        if ($medecin === null || ! $medecin->user->hasRole('medecin')) {
            throw new InvalidArgumentException('Aucun médecin n\'a ce courriel.');
        }

        $medecin->ordre_verifie_le ??= now();
        $medecin->save();

        return $medecin;
    }

    // --- Espace administrateur (ADM-01, CPT-05, CPT-06) -------------------------------------
    //
    // Chaque écriture consigne une ligne au journal d'administration, dans la
    // même transaction ; jamais le lien d'activation.

    /**
     * Rôles qu'un administrateur crée depuis l'écran. Jamais un administrateur
     * (fonctionnel ou technique) : la commande seule, pour qu'un compte
     * administrateur ne puisse pas en fabriquer d'autres (élévation de privilèges).
     */
    public const ROLES_CREABLES_PAR_ECRAN = ['medecin', 'caisse', 'laboratoire', 'coordinateur', 'support'];

    /** Rôles que l'écran ne gère pas : seule la commande les crée et leur émet un lien. */
    public const ROLES_ADMINISTRATEUR = ['admin_fonctionnel', 'admin_technique'];

    /**
     * Crée le compte et son lien d'activation, à afficher UNE fois à l'administrateur.
     *
     * @return array{0: User, 1: string}
     *
     * @throws ValidationException
     */
    public function creerParAdministrateur(User $admin, string $role, array $donnees): array
    {
        if (! in_array($role, self::ROLES_CREABLES_PAR_ECRAN, true)) {
            throw ValidationException::withMessages(['role' => 'Ce rôle ne se crée pas depuis l\'écran d\'administration.']);
        }

        if ($role === 'laboratoire' && blank($donnees['partenaire_id'] ?? null)) {
            throw ValidationException::withMessages(['partenaire_id' => 'Choisissez le laboratoire partenaire de ce compte.']);
        }

        return DB::transaction(function () use ($admin, $role, $donnees) {
            $user = $role === 'medecin' ? $this->creerMedecin($donnees)->user : $this->creerCompte($role, $donnees);
            $this->journal()->consigner($admin, 'personnel.compte_cree', $user, ['role' => $role]);

            $lien = $this->activation()->emettreLien($user);
            $this->journal()->consigner($admin, 'personnel.lien_activation_emis', $user);

            return [$user, $lien];
        });
    }

    /**
     * Nouveau lien d'activation ; l'ancien ne vaut plus rien. Refusé pour un
     * compte administrateur (commande seule), activé ou désactivé.
     *
     * @throws ValidationException
     */
    public function emettreLienParAdministrateur(User $cible, User $admin): string
    {
        $this->refuserSiAdministrateur($cible, 'lien', 'Le lien d\'un compte administrateur s\'émet par la commande personnel:lien-activation.');

        return DB::transaction(function () use ($cible, $admin) {
            try {
                $lien = $this->activation()->emettreLien($cible);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['lien' => $e->getMessage()]);
            }
            $this->journal()->consigner($admin, 'personnel.lien_activation_emis', $cible);

            return $lien;
        });
    }

    /** CPT-05 : l'Ordre est vérifié, date et auteur au journal ; le médecin devient réservable. */
    public function verifierOrdreParAdministrateur(Medecin $medecin, User $admin): Medecin
    {
        return DB::transaction(function () use ($medecin, $admin) {
            $m = Medecin::whereKey($medecin->id)->lockForUpdate()->firstOrFail();
            if ($m->ordre_verifie_le !== null) {
                throw ValidationException::withMessages(['ordre' => 'L\'inscription à l\'Ordre de ce médecin est déjà vérifiée.']);
            }

            $m->ordre_verifie_le = now();
            $m->save();
            $this->journal()->consigner($admin, 'medecin.ordre_verifie', $m->user, ['numero_ordre' => $m->numero_ordre]);

            return $m;
        });
    }

    /** @throws ValidationException */
    public function modifierMedecin(Medecin $medecin, User $admin, array $donnees): Medecin
    {
        $valides = Validator::make($donnees, [
            'specialite' => ['required', Rule::exists('specialites', 'slug')->where('active', true)],
            'presentation' => ['nullable', 'string', 'max:2000'],
        ], ['specialite.exists' => 'Spécialité inconnue ou inactive.'])->validate();

        return DB::transaction(function () use ($medecin, $admin, $valides) {
            $m = Medecin::with('specialite')->whereKey($medecin->id)->lockForUpdate()->firstOrFail();
            $avant = $m->specialite->slug;

            $m->fill([
                'specialite_id' => Specialite::where('slug', $valides['specialite'])->value('id'),
                'presentation' => filled($valides['presentation'] ?? null) ? trim($valides['presentation']) : null,
            ])->save();

            $this->journal()->consigner($admin, 'medecin.profil_modifie', $m->user, [
                'specialite_avant' => $avant,
                'specialite' => $valides['specialite'],
                'presentation_modifiee' => $m->wasChanged('presentation'),
            ]);

            return $m;
        });
    }

    /**
     * Désactive un compte du personnel (jamais supprimé). Motif obligatoire ;
     * un administrateur ne se désactive pas lui-même. Un médecin qui a des
     * rendez-vous confirmés à venir est refusé, sauf si l'administrateur
     * demande de les annuler : chacun est alors annulé comme par le médecin
     * (RG-05, remboursement total) et le patient prévenu par SMS.
     * La session en cours du compte se ferme à sa prochaine requête.
     *
     * @return int nombre de rendez-vous annulés
     *
     * @throws ValidationException
     */
    public function desactiver(User $cible, User $admin, string $motif, bool $annulerRendezVous = false): int
    {
        $motif = trim($motif);
        if ($motif === '') {
            throw ValidationException::withMessages(['motif' => 'Le motif de la désactivation est obligatoire.']);
        }

        if ($cible->is($admin)) {
            throw ValidationException::withMessages(['motif' => 'Vous ne pouvez pas désactiver votre propre compte.']);
        }

        $annules = DB::transaction(function () use ($cible, $admin, $motif, $annulerRendezVous) {
            $user = User::whereKey($cible->id)->lockForUpdate()->firstOrFail();
            if ($user->estDesactive()) {
                throw ValidationException::withMessages(['motif' => 'Ce compte est déjà désactivé.']);
            }

            $annules = [];
            if ($medecin = $user->medecin) {
                $annules = $this->libererAgenda($medecin, $admin, $motif, $annulerRendezVous);
                $medecin->forceFill(['actif' => false])->save();
            }

            $user->forceFill(['desactive_le' => now(), 'motif_desactivation' => mb_substr($motif, 0, 255)])->save();
            DB::table('jetons_activation_personnel')->where('user_id', $user->id)
                ->whereNull('utilise_le')->whereNull('invalide_le')->update(['invalide_le' => now()]);

            $this->journal()->consigner($admin, 'personnel.compte_desactive', $user, [
                'motif' => $motif,
                'rendez_vous_annules' => count($annules),
            ]);

            return $annules;
        });

        $this->prevenirPatients($annules);

        return count($annules);
    }

    /**
     * Réactive un compte (motif obligatoire). S'il n'avait jamais été activé,
     * un nouveau lien est émis et rendu, à afficher une fois (sauf compte
     * administrateur : lien par la commande).
     *
     * @throws ValidationException
     */
    public function reactiver(User $cible, User $admin, string $motif): ?string
    {
        $motif = trim($motif);
        if ($motif === '') {
            throw ValidationException::withMessages(['motif_reactivation' => 'Le motif de la réactivation est obligatoire.']);
        }

        return DB::transaction(function () use ($cible, $admin, $motif) {
            $user = User::whereKey($cible->id)->lockForUpdate()->firstOrFail();
            if (! $user->estDesactive()) {
                throw ValidationException::withMessages(['motif_reactivation' => 'Ce compte n\'est pas désactivé.']);
            }

            $user->forceFill(['desactive_le' => null, 'motif_desactivation' => null])->save();
            $user->medecin?->forceFill(['actif' => true])->save();
            $this->journal()->consigner($admin, 'personnel.compte_reactive', $user, ['motif' => $motif]);

            $jamaisActive = ! AuthentificationPersonnel::pour($user)?->estActive();
            if (! $jamaisActive || $user->hasAnyRole(self::ROLES_ADMINISTRATEUR)) {
                return null;
            }

            $lien = $this->activation()->emettreLien($user);
            $this->journal()->consigner($admin, 'personnel.lien_activation_emis', $user);

            return $lien;
        });
    }

    /**
     * Rendez-vous qui bloquent la désactivation d'un médecin : confirmés à
     * venir, et en attente de paiement (quelques minutes au plus).
     *
     * @return array{confirmes: int, en_attente_paiement: int}
     */
    public function rendezVousBloquants(Medecin $medecin): array
    {
        $avenir = $medecin->rendezVous()->where('debut', '>', now());

        return [
            'confirmes' => (clone $avenir)->where('statut', StatutRendezVous::Confirme->value)->count(),
            'en_attente_paiement' => (clone $avenir)->where('statut', StatutRendezVous::EnAttentePaiement->value)->count(),
        ];
    }

    /** @return list<RendezVous> rendez-vous annulés */
    private function libererAgenda(Medecin $medecin, User $admin, string $motif, bool $annuler): array
    {
        $bloquants = $this->rendezVousBloquants($medecin);

        if ($bloquants['en_attente_paiement'] > 0) {
            throw ValidationException::withMessages(['motif' => sprintf(
                '%d rendez-vous de ce médecin sont en cours de paiement : réessayez dans quelques minutes.', $bloquants['en_attente_paiement'])]);
        }

        if ($bloquants['confirmes'] === 0) {
            return [];
        }

        if (! $annuler) {
            throw ValidationException::withMessages(['motif' => sprintf(
                'Ce médecin a %d rendez-vous confirmé(s) à venir : annulez-les d\'abord (depuis son espace, ou en cochant l\'annulation avec remboursement intégral).', $bloquants['confirmes'])]);
        }

        $annules = [];
        $rendezVous = $medecin->rendezVous()->where('debut', '>', now())->where('statut', StatutRendezVous::Confirme->value)->orderBy('debut')->get();
        foreach ($rendezVous as $rdv) {
            try {
                $annules[] = app(ReservationService::class)->annulerParMedecin($rdv, 'Médecin indisponible : '.$motif);
            } catch (ReservationRefusee|PaiementRefuse $refus) {
                // Tout ou rien : la transaction englobante est annulée.
                throw ValidationException::withMessages(['motif' => 'Annulation impossible : '.$refus->getMessage()]);
            }
            $this->journal()->consigner($admin, 'rendez_vous.annule_par_administration', $rdv, ['taux_remboursement' => 100]);
        }

        return $annules;
    }

    /**
     * Après validation de la transaction : un SMS par patient, comme une
     * annulation par le médecin (ni motif ni spécialité, NOT-02). Une panne
     * SMS ne défait rien ; journal sans numéro.
     *
     * @param  list<RendezVous>  $annules
     */
    private function prevenirPatients(array $annules): void
    {
        foreach ($annules as $rdv) {
            $debut = $rdv->debut->setTimezone('Africa/Kinshasa');

            try {
                app(EnvoyeurSms::class)->envoyer($rdv->patient->telephone, sprintf(
                    'MEDIHOME : votre consultation du %s a %s est annulee par le medecin. Vous serez rembourse ; ouvrez l\'application pour choisir un autre creneau.',
                    $debut->format('d/m'), $debut->format('H:i')));
            } catch (Throwable $erreur) {
                Log::warning('SMS d\'annulation par l\'administration non envoyé', ['rendez_vous' => $rdv->id, 'erreur' => $erreur::class]);
            }
        }
    }

    /** @throws ValidationException */
    private function refuserSiAdministrateur(User $cible, string $champ, string $message): void
    {
        if ($cible->hasAnyRole(self::ROLES_ADMINISTRATEUR)) {
            throw ValidationException::withMessages([$champ => $message]);
        }
    }

    private function journal(): JournalAdministration
    {
        return app(JournalAdministration::class);
    }

    private function activation(): ActivationPersonnelService
    {
        return app(ActivationPersonnelService::class);
    }
}
