<?php

namespace App\Console\Commands;

use App\Enums\MotifRemboursement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutConsultation;
use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\Antecedent;
use App\Models\AuthentificationPersonnel;
use App\Models\Consultation;
use App\Models\DemandeConsultation;
use App\Models\LigneOrdonnance;
use App\Models\Medecin;
use App\Models\NoteConsultation;
use App\Models\Ordonnance;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\TauxChange;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Services\Dossier\NoteService;
use App\Services\Dossier\OrdonnanceService;
use App\Services\Paiement\FacturationService;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\JournalCaisse;
use App\Services\Paiement\RemboursementService;
use App\Support\Empreinte;
use App\Support\Montant;
use App\Support\Totp;
use Carbon\CarbonImmutable;
use Database\Seeders\DemonstrationLocaleSeeder;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Démonstration prête à l'emploi, sur le poste du développeur SEULEMENT
 * (APP_ENV=local) : tous les comptes du personnel de démonstration activés
 * avec un mot de passe et un secret TOTP connus, des patients fictifs et
 * des rendez-vous à chaque étape (payé et terminé avec note signée et
 * ordonnance, consultation qui commence dans quelques minutes, à venir,
 * annulé avec un remboursement à valider par la caisse).
 *
 * Refusée hors APP_ENV=local : des mots de passe et un secret partagés
 * connus de tous n'ont rien à faire ailleurs. Données entièrement fictives.
 * Rejouable : ce qui existe est gardé ; la consultation « en direct » est
 * recréée si elle est passée.
 */
class PreparerDemonstration extends Command
{
    public const MOT_DE_PASSE = 'MediHome-demo-2026';

    /** Secret TOTP commun aux comptes de démonstration (base32). */
    public const SECRET_TOTP = 'MEDIHOMEDEMOMEDIHOMEDEMOMEDIHOME';

    protected $signature = 'demo:preparer';

    protected $description = 'Prépare une démonstration complète (local seulement) : comptes activés, patients, rendez-vous, paiements, note, ordonnance';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Démonstration refusée : APP_ENV doit valoir « local » (mots de passe et secret de démonstration connus de tous).');

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => ReferentielMedicalSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => DemonstrationLocaleSeeder::class, '--force' => true]);

        $personnel = $this->activerPersonnel();
        $patients = $this->patients();
        $this->tauxDuJour($personnel['caisse1.demo@doc-and-i.local']);

        $generaliste = Medecin::whereHas('user', fn ($q) => $q->where('email', 'medecin.demo@doc-and-i.local'))->firstOrFail();
        $pediatre = Medecin::whereHas('user', fn ($q) => $q->where('email', 'pediatre.demo@doc-and-i.local'))->firstOrFail();
        $generaliste->forceFill(['ordre_verifie_le' => $generaliste->ordre_verifie_le ?? now(), 'actif' => true])->save();
        $pediatre->forceFill(['ordre_verifie_le' => $pediatre->ordre_verifie_le ?? now(), 'actif' => true])->save();

        [$grace, $jeanPaul, $esther] = $patients;
        $this->historiqueTermine($grace, $generaliste);
        $direct = $this->consultationEnDirect($grace, $generaliste);
        $this->rendezVousAVenir($esther, $pediatre);
        $this->remboursementAValider($jeanPaul, $generaliste);

        $this->resume($direct);

        return self::SUCCESS;
    }

    /** @return array<string, User> */
    private function activerPersonnel(): array
    {
        // Second administrateur : la vérification à l'Ordre exige quatre yeux.
        $admin2 = User::where('email', 'admin2.demo@doc-and-i.local')->first() ?? tap(new User)->forceFill([
            'email' => 'admin2.demo@doc-and-i.local', 'telephone' => '+243810000022', 'telephone_verifie_le' => now(),
            'nom' => 'DÉMO', 'prenom' => 'Administratrice',
        ]);
        $admin2->save();
        $admin2->syncRoles([Role::findOrCreate('admin_fonctionnel', 'web')]);

        $comptes = [];
        foreach (['admin.demo@doc-and-i.local', 'admin2.demo@doc-and-i.local', 'medecin.demo@doc-and-i.local', 'pediatre.demo@doc-and-i.local',
            'caisse1.demo@doc-and-i.local', 'caisse2.demo@doc-and-i.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $user->forceFill(['password' => Hash::make(self::MOT_DE_PASSE), 'desactive_le' => null])->save();

            $auth = AuthentificationPersonnel::where('user_id', $user->id)->first() ?? (new AuthentificationPersonnel)->forceFill(['user_id' => $user->id]);
            $auth->forceFill([
                'totp_secret' => self::SECRET_TOTP,
                'active_le' => $auth->active_le ?? now(),
                'echecs_consecutifs' => 0,
                'verrouille_jusqu_au' => null,
            ])->save();
            DB::table('jetons_activation_personnel')->where('user_id', $user->id)->whereNull('utilise_le')->whereNull('invalide_le')
                ->update(['invalide_le' => now()]);

            $comptes[$email] = $user;
        }

        return $comptes;
    }

    /** @return list<User> */
    private function patients(): array
    {
        $fiches = [
            ['+243810000101', 'KAPINGA', 'Grâce', 'F', '1988-03-14', 'Limete'],
            ['+243810000102', 'MBALA', 'Jean-Paul', 'M', '1975-11-02', 'Gombe'],
            ['+243810000103', 'NSIMBA', 'Esther', 'F', '2016-06-21', 'Ngaliema'],
        ];

        return array_map(function (array $f) {
            [$telephone, $nom, $prenom, $sexe, $naissance, $commune] = $f;
            $patient = User::where('telephone', $telephone)->first() ?? tap(new User)->forceFill([
                'telephone' => $telephone, 'telephone_verifie_le' => now(),
                'nom' => $nom, 'prenom' => $prenom, 'sexe' => $sexe, 'date_naissance' => $naissance, 'commune' => $commune, 'langue' => 'fr',
            ]);
            $patient->save();
            $patient->syncRoles([Role::findOrCreate('patient', 'web')]);

            if (! $patient->consentements()->where('version', config('doc-and-i.consentement.version'))->whereNull('retire_le')->exists()) {
                $patient->consentements()->create(['version' => config('doc-and-i.consentement.version'), 'accepte_le' => now(), 'ip' => '127.0.0.1']);
            }

            return $patient;
        }, $fiches);
    }

    private function tauxDuJour(User $caisse): void
    {
        $jour = CarbonImmutable::now('Africa/Kinshasa')->toDateString();
        if (TauxChange::where('jour', $jour)->exists()) {
            return;
        }

        $taux = new TauxChange;
        $taux->forceFill(['jour' => $jour, 'cdf_par_usd' => '2850.0000', 'saisi_par' => $caisse->id, 'statut' => 'actif'])->save();
    }

    /** Grâce : une consultation d'hier, payée, terminée, note signée, ordonnance, plus une allergie et un traitement. */
    private function historiqueTermine(User $patient, Medecin $medecin): void
    {
        if (RendezVous::where('patient_id', $patient->id)->where('statut', StatutRendezVous::Termine->value)->exists()) {
            return;
        }

        $this->antecedent($patient, 'allergie', 'Pénicilline', 'Urticaire en 2019');
        $this->antecedent($patient, 'traitement', 'Metformine 500 mg', 'Matin et soir');
        $this->antecedent($patient, 'medical', 'Diabète de type 2', 'Depuis 2021');

        $debut = CarbonImmutable::now('Africa/Kinshasa')->subDay()->setTime(10, 0);
        $rdv = $this->rendezVous($patient, $medecin, $debut, StatutRendezVous::Termine, 'Fièvre et maux de tête depuis deux jours');
        $this->paiementEncaisse($rdv);

        $consultation = new Consultation;
        $consultation->forceFill([
            'rendez_vous_id' => $rdv->id, 'statut' => StatutConsultation::Terminee,
            'patient_rejoint_le' => $debut, 'medecin_rejoint_le' => $debut, 'ensemble_le' => $debut, 'debut_le' => $debut,
            'fin_le' => $debut->addMinutes(18), 'secondes_ensemble' => 18 * 60,
        ])->save();

        $note = new NoteConsultation;
        $note->forceFill([
            'rendez_vous_id' => $rdv->id, 'medecin_id' => $medecin->id, 'patient_id' => $patient->id,
            'motif' => 'Fièvre à 38,5 °C et céphalées depuis 48 heures.',
            'examen' => 'Patiente consciente, pas de raideur de nuque. Gorge érythémateuse.',
            'hypotheses' => 'Pharyngite virale probable.',
            'diagnostic_code' => 'CA02', 'diagnostic_libelle' => 'Pharyngite aiguë',
            'conduite' => 'Paracétamol, hydratation, repos. Revoir si fièvre au-delà de 72 heures.',
            'signee_le' => $debut->addMinutes(20)->startOfSecond(), 'signature_version' => Empreinte::VERSION,
        ]);
        $note->empreinte = Empreinte::de(NoteService::contenuSigne($note));
        $note->save();

        $ordonnance = new Ordonnance;
        $ordonnance->forceFill([
            'numero' => app(FacturationService::class)->numeroSuivant('OR'),
            'rendez_vous_id' => $rdv->id, 'medecin_id' => $medecin->id, 'patient_id' => $patient->id,
            'consignes' => 'Boire au moins 1,5 litre d\'eau par jour.',
            'patient_nom' => $patient->prenom.' '.$patient->nom, 'patient_naissance' => $patient->date_naissance?->format('Y-m-d'),
            'medecin_nom' => 'Dr '.$medecin->user->prenom.' '.$medecin->user->nom, 'medecin_numero_ordre' => $medecin->numero_ordre,
            'signee_le' => $debut->addMinutes(22)->startOfSecond(), 'signature_version' => Empreinte::VERSION,
            'code_verification' => Str::random(32), 'cle_idempotence' => (string) Str::uuid(), 'empreinte' => str_repeat('0', 64),
        ]);
        DB::transaction(function () use ($ordonnance) {
            // Lignes d'abord en mémoire : le sceau couvre les lignes, puis tout est écrit d'un coup.
            $lignes = [
                ['medicament' => 'Paracétamol', 'dosage' => '500 mg', 'voie' => 'orale', 'posologie' => '1 comprimé toutes les 6 heures si fièvre', 'duree' => '3 jours', 'consignes' => 'Maximum 4 comprimés par jour'],
                ['medicament' => 'Sérum physiologique', 'dosage' => '5 ml', 'voie' => 'nasale', 'posologie' => '2 fois par jour', 'duree' => '5 jours', 'consignes' => null],
            ];
            $ordonnance->setRelation('lignes', collect($lignes)->values()->map(fn ($l, $i) => (new LigneOrdonnance)->forceFill($l + ['rang' => $i + 1])));
            $ordonnance->empreinte = Empreinte::de(OrdonnanceService::contenuSigne($ordonnance));
            $ordonnance->save();
            foreach ($ordonnance->lignes as $ligne) {
                $ligne->ordonnance_id = $ordonnance->id;
                $ligne->save();
            }
        });

    }

    /** Consultation qui commence dans 5 minutes : salle ouverte tout de suite, pour essayer l'appel vidéo. */
    private function consultationEnDirect(User $patient, Medecin $medecin): RendezVous
    {
        $existante = RendezVous::where('patient_id', $patient->id)->where('medecin_id', $medecin->id)
            ->where('statut', StatutRendezVous::Confirme->value)->where('fin', '>', now()->addMinutes(10))
            ->where('debut', '<', now()->addMinutes(10))->first();
        if ($existante) {
            return $existante;
        }

        $debut = CarbonImmutable::now('Africa/Kinshasa')->addMinutes(5)->startOfMinute();
        $rdv = $this->rendezVous($patient, $medecin, $debut, StatutRendezVous::Confirme, 'Contrôle de la glycémie et renouvellement du traitement', 30);
        $this->paiementEncaisse($rdv);

        return $rdv;
    }

    /** Esther (enfant) : rendez-vous de pédiatrie demain, payé. */
    private function rendezVousAVenir(User $patient, Medecin $medecin): void
    {
        if (RendezVous::where('patient_id', $patient->id)->where('debut', '>', now())->exists()) {
            return;
        }

        $rdv = $this->rendezVous($patient, $medecin, CarbonImmutable::now('Africa/Kinshasa')->addDay()->setTime(10, 0), StatutRendezVous::Confirme, 'Toux chez l\'enfant depuis une semaine');
        $this->paiementEncaisse($rdv, 'CDF');
    }

    /** Jean-Paul : rendez-vous payé puis annulé plus de 24 h avant : remboursement intégral à valider par la caisse. */
    private function remboursementAValider(User $patient, Medecin $medecin): void
    {
        if (RendezVous::where('patient_id', $patient->id)->where('statut', StatutRendezVous::AnnuleParPatient->value)->exists()) {
            return;
        }

        $rdv = $this->rendezVous($patient, $medecin, CarbonImmutable::now('Africa/Kinshasa')->addDays(3)->setTime(15, 0), StatutRendezVous::AnnuleParPatient, 'Douleurs lombaires');
        $rdv->forceFill(['annule_le' => now(), 'motif_annulation' => 'Empêchement', 'taux_remboursement' => 100])->save();
        $paiement = $this->paiementEncaisse($rdv);
        app(RemboursementService::class)->demander($paiement, (string) $paiement->montant, MotifRemboursement::AnnulationPatient);
    }

    private function rendezVous(User $patient, Medecin $medecin, CarbonImmutable $debut, StatutRendezVous $statut, string $motif, int $duree = 20): RendezVous
    {
        $type = TypeConsultation::where('specialite_id', $medecin->specialite_id)->where('actif', true)->whereNotNull('prix')->orderBy('duree_minutes')->first()
            ?? TypeConsultation::where('specialite_id', $medecin->specialite_id)->firstOrFail();

        $demande = new DemandeConsultation(['motif' => $motif, 'symptomes' => null, 'tri_reponses' => [], 'tri_alerte' => false]);
        $demande->patient_id = $patient->id;
        $demande->statut = in_array($statut, [StatutRendezVous::AnnuleParPatient], true) ? 'annulee' : 'reservee';
        $demande->specialite_id = $medecin->specialite_id;
        $demande->save();

        $rdv = new RendezVous;
        $rdv->forceFill([
            'demande_id' => $demande->id, 'patient_id' => $patient->id, 'medecin_id' => $medecin->id, 'type_consultation_id' => $type->id,
            'debut' => $debut, 'fin' => $debut->addMinutes($duree), 'statut' => $statut,
            'prix' => $type->prix ?? '10.00', 'devise' => $type->devise ?? 'USD',
        ])->save();

        return $rdv;
    }

    private function paiementEncaisse(RendezVous $rdv, string $devise = 'USD'): Paiement
    {
        // Francs congolais au taux de démonstration (2 850 CDF pour 1 USD), en centimes entiers.
        $montant = $devise === 'CDF'
            ? Montant::depuisCentimes(Montant::enCentimes((string) $rdv->prix) * 2850)
            : (string) $rdv->prix;

        $paiement = new Paiement;
        $paiement->forceFill([
            'patient_id' => $rdv->patient_id, 'rendez_vous_id' => $rdv->id, 'objet' => 'consultation',
            'moyen' => MoyenPaiement::MPesa, 'telephone_payeur' => $rdv->patient->telephone,
            'montant' => $montant, 'devise' => $devise, 'montant_reference' => (string) $rdv->prix, 'devise_reference' => $rdv->devise,
            'statut' => StatutPaiement::Reussi, 'cle_idempotence' => (string) Str::uuid(),
            'fournisseur' => FournisseurSimulation::NOM, 'reference_fournisseur' => 'SIM-DEMO-'.Str::upper(Str::random(12)),
            'confirme_le' => now(),
        ])->save();

        app(FacturationService::class)->emettreFacture($paiement);
        app(JournalCaisse::class)->encaissement($paiement);

        return $paiement;
    }

    private function antecedent(User $patient, string $categorie, string $libelle, ?string $precision): void
    {
        if (Antecedent::where('patient_id', $patient->id)->where('libelle', $libelle)->exists()) {
            return;
        }

        $a = new Antecedent;
        $a->forceFill(['patient_id' => $patient->id, 'categorie' => $categorie, 'libelle' => $libelle, 'precision' => $precision,
            'saisi_par' => $patient->id, 'source' => 'patient'])->save();
    }

    private function resume(RendezVous $direct): void
    {
        $this->newLine();
        $this->info('Démonstration prête (données fictives).');
        $this->newLine();
        $this->line('PERSONNEL : http://localhost:8097/personnel/connexion');
        $this->line('  Mot de passe commun : '.self::MOT_DE_PASSE);
        $this->line('  Code à 6 chiffres   : php artisan demo:code   (ou clé '.self::SECRET_TOTP.' dans une application d\'authentification)');
        $this->table(['Rôle', 'Courriel'], [
            ['Administrateur', 'admin.demo@doc-and-i.local'],
            ['Administratrice (2e, quatre yeux)', 'admin2.demo@doc-and-i.local'],
            ['Médecin généraliste', 'medecin.demo@doc-and-i.local'],
            ['Pédiatre', 'pediatre.demo@doc-and-i.local'],
            ['Caisse 1', 'caisse1.demo@doc-and-i.local'],
            ['Caisse 2', 'caisse2.demo@doc-and-i.local'],
        ]);
        $this->line('PATIENTS : http://localhost:8097/connexion (code SMS dans storage/logs/laravel.log)');
        $this->table(['Patient', 'Téléphone', 'Ce qu\'il y a à voir'], [
            ['Grâce KAPINGA', '0810000101', 'Consultation terminée hier (note, ordonnance), consultation à '.$direct->debut->setTimezone('Africa/Kinshasa')->format('H:i').' avec le Dr MUKENDI'],
            ['Jean-Paul MBALA', '0810000102', 'Rendez-vous annulé : remboursement à valider par la caisse'],
            ['Esther NSIMBA', '0810000103', 'Pédiatrie demain à 10:00, payée en francs congolais'],
        ]);
    }
}
