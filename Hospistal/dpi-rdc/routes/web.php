<?php

use App\Http\Controllers\AcompteController;
use App\Http\Controllers\ActeCliniqueController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AssuranceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BanqueSangController;
use App\Http\Controllers\BilanHydriqueController;
use App\Http\Controllers\BlocOperatoireController;
use App\Http\Controllers\CaisseController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\ConventionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DialyseController;
use App\Http\Controllers\DieteMenageController;
use App\Http\Controllers\DisponibiliteController;
use App\Http\Controllers\DossierInfirmierController;
use App\Http\Controllers\DossierMedicalController;
use App\Http\Controllers\EquipementController;
use App\Http\Controllers\ForfaitController;
use App\Http\Controllers\LaboratoireController;
use App\Http\Controllers\MaterniteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficineController;
use App\Http\Controllers\ParametreController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PharmacieController;
use App\Http\Controllers\PlanAdministrationController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\ServiceHospitalierController;
use App\Http\Controllers\StatistiqueController;
use App\Http\Controllers\TransfertServiceController;
use App\Http\Controllers\UrgenceController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Patients
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::get('/patients-recherche', [PatientController::class, 'recherche'])->name('patients.recherche');
    Route::get('/patients/nouveau', [PatientController::class, 'create'])->name('patients.create');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
    Route::post('/patients/{patient}/envoyer-caisse', [PatientController::class, 'envoyerCaisse'])->name('patients.envoyer-caisse');
    Route::post('/patients/{patient}/assurance', [PatientController::class, 'majAssurance'])->name('patients.assurance');

    // Consultations — workflow caisse-first : le médecin consulte une visite payée
    Route::get('/patients/{patient}/consultation', [ConsultationController::class, 'create'])->name('consultations.create');
    Route::get('/visites/{visit}/consulter', [ConsultationController::class, 'consulter'])->name('visites.consulter');
    Route::post('/visites/{visit}/consultation', [ConsultationController::class, 'store'])->name('visites.consultation.store');
    Route::get('/visites/{visit}/triage', [VisitController::class, 'triage'])->name('visites.triage');
    Route::post('/visites/{visit}/triage', [VisitController::class, 'triageStore'])->name('visites.triage.store');
    Route::get('/consultations', [ConsultationController::class, 'index'])->name('consultations.index');
    Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
    Route::post('/consultations/{consultation}/facturer', [ConsultationController::class, 'facturer'])->name('consultations.facturer');

    // Visites / hospitalisation
    Route::get('/visites', [VisitController::class, 'index'])->name('visites.index');
    Route::get('/visites/{visit}', [VisitController::class, 'show'])->name('visites.show');
    Route::post('/visites/{visit}/hospitaliser', [VisitController::class, 'hospitaliser'])->name('visites.hospitaliser');
    Route::post('/visites/{visit}/facturer-sejour', [VisitController::class, 'facturerSejour'])->name('visites.facturer-sejour');
    Route::post('/visites/{visit}/sortir', [VisitController::class, 'sortir'])->name('visites.sortir');

    // Laboratoire
    Route::get('/labo', [LaboratoireController::class, 'index'])->name('labo.index');
    Route::get('/labo/prescrire', [LaboratoireController::class, 'create'])->name('labo.create');
    Route::post('/labo', [LaboratoireController::class, 'store'])->name('labo.store');
    Route::get('/labo/{examen}', [LaboratoireController::class, 'show'])->name('labo.show');
    Route::get('/labo/{examen}/bon', [LaboratoireController::class, 'bon'])->name('labo.bon');
    Route::get('/labo/{examen}/bulletin', [LaboratoireController::class, 'bulletin'])->name('labo.bulletin');
    // Résultats en PDF pour le prescripteur : il lit sans entrer au plateau technique.
    Route::get('/examens/{examen}/resultat.pdf', [LaboratoireController::class, 'pdfResultat'])->name('examens.pdf');
    Route::post('/labo/{examen}/resultats', [LaboratoireController::class, 'saisirResultats'])->name('labo.resultats');
    Route::post('/labo/{examen}/valider', [LaboratoireController::class, 'valider'])->name('labo.valider');
    Route::post('/labo/{examen}/rouvrir', [LaboratoireController::class, 'rouvrir'])->name('labo.rouvrir');
    Route::post('/labo/{examen}/fichiers', [LaboratoireController::class, 'ajouterFichier'])->name('labo.fichiers');
    Route::get('/labo-rapport', [LaboratoireController::class, 'rapport'])->name('labo.rapport');
    Route::get('/patients/{patient}/bulletin-jour', [LaboratoireController::class, 'bulletinJour'])->name('patients.bulletin-jour');

    // Imagerie (même contrôleur, domaine imagerie)
    Route::get('/imagerie', fn () => app(LaboratoireController::class)->index(request()->merge(['domaine' => 'imagerie'])))->name('imagerie.index');
    Route::get('/imagerie/prescrire', fn () => app(LaboratoireController::class)->create(request()->merge(['domaine' => 'imagerie'])))->name('imagerie.create');

    // Bloc opératoire
    Route::get('/bloc', fn () => app(ActeCliniqueController::class)->index(request()->merge(['domaine' => 'chirurgie'])))->name('bloc.index');
    Route::get('/bloc/nouveau', fn () => app(ActeCliniqueController::class)->create(request()->merge(['domaine' => 'chirurgie'])))->name('bloc.create');
    Route::post('/bloc', [ActeCliniqueController::class, 'store'])->name('bloc.store');

    // Maternité
    // Maternité : fiche obstétricale, consultations prénatales, accouchements
    Route::get('/maternite', [MaterniteController::class, 'index'])->name('maternite.index');
    Route::post('/maternite/grossesses', [MaterniteController::class, 'store'])->name('maternite.grossesses.store');
    Route::get('/maternite/registre', [MaterniteController::class, 'registre'])->name('maternite.registre');
    Route::get('/maternite/grossesses/{grossesse}', [MaterniteController::class, 'show'])->name('maternite.show');
    Route::get('/maternite/grossesses/{grossesse}/fiche', [MaterniteController::class, 'fiche'])->name('maternite.fiche');
    Route::post('/maternite/grossesses/{grossesse}/cpn', [MaterniteController::class, 'consultation'])->name('maternite.cpn');
    Route::post('/maternite/grossesses/{grossesse}/accouchement', [MaterniteController::class, 'accouchement'])->name('maternite.accouchement');
    // Actes de maternité facturables (hors accouchement) : ils passent par le bloc
    Route::get('/maternite/actes', fn () => app(ActeCliniqueController::class)->index(request()->merge(['domaine' => 'maternite'])))->name('maternite.actes');
    Route::get('/maternite/actes/nouveau', fn () => app(ActeCliniqueController::class)->create(request()->merge(['domaine' => 'maternite'])))->name('maternite.create');
    Route::post('/maternite/actes', [ActeCliniqueController::class, 'store'])->name('maternite.store');

    // Examens spécialisés (dentisterie, ORL, ophtalmo…) prescrits depuis le parcours
    Route::get('/examens-specialises', fn () => app(ActeCliniqueController::class)->index(request()->merge(['domaine' => 'examen_specialise'])))->name('examens-specialises.index');
    Route::get('/examens-specialises/nouveau', fn () => app(ActeCliniqueController::class)->create(request()->merge(['domaine' => 'examen_specialise'])))->name('examens-specialises.create');
    Route::post('/examens-specialises', [ActeCliniqueController::class, 'store'])->name('examens-specialises.store');

    // Dialyse / néphrologie
    // Dialyse : calendrier des générateurs, séances, registre
    Route::get('/dialyse', [DialyseController::class, 'calendrier'])->name('dialyse.index');
    Route::post('/dialyse/seances', [DialyseController::class, 'planifier'])->name('dialyse.planifier');
    Route::post('/dialyse/recurrence', [DialyseController::class, 'recurrence'])->name('dialyse.recurrence');
    Route::get('/dialyse/seances', [DialyseController::class, 'seances'])->name('dialyse.seances');
    Route::post('/dialyse/seances/{seance}/realiser', [DialyseController::class, 'realiser'])->name('dialyse.realiser');
    Route::post('/dialyse/seances/{seance}/absence', [DialyseController::class, 'absence'])->name('dialyse.absence');
    Route::get('/dialyse/registre', [DialyseController::class, 'registre'])->name('dialyse.registre');
    Route::get('/dialyse/actes', fn () => app(ActeCliniqueController::class)->index(request()->merge(['domaine' => 'dialyse'])))->name('dialyse.actes');
    Route::get('/dialyse/actes/nouveau', fn () => app(ActeCliniqueController::class)->create(request()->merge(['domaine' => 'dialyse'])))->name('dialyse.create');
    Route::post('/dialyse/actes', [ActeCliniqueController::class, 'store'])->name('dialyse.store');

    // Banque de sang : stock, donneurs, demandes, délivrance
    Route::get('/banque-sang', [BanqueSangController::class, 'index'])->name('banque-sang.index');
    Route::get('/banque-sang/donneurs', [BanqueSangController::class, 'donneurs'])->name('banque-sang.donneurs');
    Route::post('/banque-sang/donneurs', [BanqueSangController::class, 'enregistrerDonneur'])->name('banque-sang.donneurs.store');
    Route::post('/banque-sang/donneurs/{donneur}/don', [BanqueSangController::class, 'enregistrerDon'])->name('banque-sang.don');
    Route::post('/banque-sang/poches/{poche}/depister', [BanqueSangController::class, 'depister'])->name('banque-sang.depister');
    Route::post('/banque-sang/demandes', [BanqueSangController::class, 'demander'])->name('banque-sang.demander');
    Route::get('/banque-sang/demandes/{demande}', [BanqueSangController::class, 'demande'])->name('banque-sang.demande');
    Route::post('/banque-sang/demandes/{demande}/delivrer', [BanqueSangController::class, 'delivrer'])->name('banque-sang.delivrer');
    Route::post('/banque-sang/demandes/{demande}/refuser', [BanqueSangController::class, 'refuser'])->name('banque-sang.refuser');

    // Acomptes de soins (urgences et hospitalisation)
    Route::get('/acomptes', [AcompteController::class, 'index'])->name('acomptes.index');
    Route::get('/visites/{visit}/acomptes', [AcompteController::class, 'show'])->name('acomptes.show');
    Route::post('/visites/{visit}/acomptes', [AcompteController::class, 'store'])->name('acomptes.store');
    Route::post('/visites/{visit}/acomptes/rembourser', [AcompteController::class, 'rembourser'])->name('acomptes.rembourser');

    // Forfaits : référentiel et application à un séjour
    Route::get('/forfaits', [ForfaitController::class, 'index'])->name('forfaits.index');
    Route::post('/forfaits', [ForfaitController::class, 'store'])->name('forfaits.store');
    Route::post('/forfaits/{forfait}/basculer', [ForfaitController::class, 'basculer'])->name('forfaits.basculer');
    Route::post('/visites/{visit}/forfait', [ForfaitController::class, 'appliquer'])->name('forfaits.appliquer');
    Route::post('/visites/{visit}/forfait/retirer', [ForfaitController::class, 'retirer'])->name('forfaits.retirer');

    // Disponibilité des médecins par spécialité
    Route::get('/disponibilites', [DisponibiliteController::class, 'index'])->name('disponibilites.index');
    Route::post('/disponibilites', [DisponibiliteController::class, 'store'])->name('disponibilites.store');
    Route::delete('/disponibilites/{disponibilite}', [DisponibiliteController::class, 'destroy'])->name('disponibilites.destroy');
    Route::post('/disponibilites/absence', [DisponibiliteController::class, 'absence'])->name('disponibilites.absence');
    Route::delete('/disponibilites/absence/{absence}', [DisponibiliteController::class, 'supprimerAbsence'])->name('disponibilites.absence.destroy');

    // ══════════════════════════════════════════════════════════════
    // Paramétrage de l'établissement
    // ══════════════════════════════════════════════════════════════
    Route::get('/parametres', [ParametreController::class, 'index'])->name('parametres.index');
    Route::post('/parametres/taux', [ParametreController::class, 'reviserTaux'])->name('parametres.taux');

    // Comptes du personnel et profils d'utilisation
    Route::get('/utilisateurs', [UtilisateurController::class, 'index'])->name('utilisateurs.index');
    Route::post('/utilisateurs', [UtilisateurController::class, 'store'])->name('utilisateurs.store');
    Route::post('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'update'])->name('utilisateurs.update');
    Route::post('/utilisateurs/{utilisateur}/basculer', [UtilisateurController::class, 'basculer'])->name('utilisateurs.basculer');
    Route::post('/utilisateurs/{utilisateur}/mot-de-passe', [UtilisateurController::class, 'motDePasse'])->name('utilisateurs.mot-de-passe');

    // Sociétés conventionnées : contrat, modalités et règles de couverture
    Route::get('/assurances', [AssuranceController::class, 'index'])->name('assurances.index');
    Route::post('/assurances', [AssuranceController::class, 'store'])->name('assurances.store');
    Route::get('/assurances/{assurance}', [AssuranceController::class, 'show'])->name('assurances.show');
    Route::post('/assurances/{assurance}', [AssuranceController::class, 'update'])->name('assurances.update');
    Route::post('/assurances/{assurance}/basculer', [AssuranceController::class, 'basculer'])->name('assurances.basculer');
    Route::post('/assurances/{assurance}/couvertures', [AssuranceController::class, 'ajouterCouverture'])->name('assurances.couvertures');
    Route::delete('/couvertures/{couverture}', [AssuranceController::class, 'supprimerCouverture'])->name('assurances.couvertures.destroy');

    // Transfert d'un service à un autre, sans clore le séjour
    Route::post('/visites/{visit}/transfert-service', [TransfertServiceController::class, 'store'])->name('transferts.store');

    // Le médecin rend la main : le patient revient dans la file d'attente
    Route::post('/visites/{visit}/liberer', [ConsultationController::class, 'liberer'])->name('visites.liberer');

    // Programmation simple, hors bloc opératoire (examens spécialisés).
    Route::post('/actes/{acte}/planifier', [ActeCliniqueController::class, 'planifier'])->name('actes.planifier');
    Route::post('/actes/{acte}/realiser', [ActeCliniqueController::class, 'realiser'])->name('actes.realiser');
    Route::post('/actes/{acte}/facturer', [ActeCliniqueController::class, 'facturer'])->name('actes.facturer');

    // Prescriptions
    Route::get('/consultations/{consultation}/prescrire', [PrescriptionController::class, 'create'])->name('prescriptions.create');
    Route::post('/consultations/{consultation}/prescrire', [PrescriptionController::class, 'store'])->name('prescriptions.store');

    // Services d'hospitalisation (réa, médecine interne, néonatologie…)
    Route::get('/services', [ServiceHospitalierController::class, 'index'])->name('services.index');
    Route::get('/services/{service}', [ServiceHospitalierController::class, 'show'])->name('services.show');
    Route::get('/services/{service}/patient/{visit}', [ServiceHospitalierController::class, 'dossier'])->name('services.dossier');
    Route::post('/visites/{visit}/evolution', [ServiceHospitalierController::class, 'storeNote'])->name('visites.evolution');
    Route::post('/visites/{visit}/signes-vitaux', [ServiceHospitalierController::class, 'storeSignesVitaux'])->name('visites.signes-vitaux');
    Route::post('/lits/{lit}/statut', [ServiceHospitalierController::class, 'statutLit'])->name('lits.statut');

    // Notifications internes (labo / imagerie / pharmacie ↔ médecins)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/tout-lu', [NotificationController::class, 'toutMarquerLues'])->name('notifications.tout-lu');
    Route::post('/notifications/{notification}/lue', [NotificationController::class, 'marquerLue'])->name('notifications.lue');
    Route::post('/notifications/{notification}/archiver', [NotificationController::class, 'archiver'])->name('notifications.archiver');

    // Bloc opératoire : demande → planification → intervention → registre
    Route::get('/bloc/programme', [BlocOperatoireController::class, 'programme'])->name('bloc.programme');
    Route::get('/bloc/horaire', [BlocOperatoireController::class, 'horaire'])->name('bloc.horaire');
    Route::get('/bloc/interventions', [BlocOperatoireController::class, 'interventions'])->name('bloc.interventions');
    Route::get('/bloc/registre', [BlocOperatoireController::class, 'registre'])->name('bloc.registre');
    Route::get('/bloc/{acte}/feuille', [BlocOperatoireController::class, 'feuille'])->name('bloc.feuille');
    Route::post('/bloc/{acte}/planifier', [BlocOperatoireController::class, 'planifier'])->name('bloc.planifier');
    Route::post('/bloc/{acte}/cloturer', [BlocOperatoireController::class, 'cloturer'])->name('bloc.cloturer');

    // Urgences — triage structuré puis prise en charge
    Route::get('/urgences', [UrgenceController::class, 'index'])->name('urgences.index');
    Route::get('/urgences/registre', [UrgenceController::class, 'registre'])->name('urgences.registre');
    Route::get('/urgences/{visit}/triage', [UrgenceController::class, 'triage'])->name('urgences.triage');
    Route::post('/urgences/{visit}/triage', [UrgenceController::class, 'storeTriage'])->name('urgences.triage.store');

    // Dossier médical : antécédents, allergies, documents cliniques
    Route::get('/patients/{patient}/dossier', [DossierMedicalController::class, 'show'])->name('dossier.show');
    Route::post('/patients/{patient}/dossier/referentiel', [DossierMedicalController::class, 'storeReferentiel'])->name('dossier.referentiel.store');
    Route::delete('/dossier/referentiel/{entree}', [DossierMedicalController::class, 'destroyReferentiel'])->name('dossier.referentiel.destroy');
    Route::post('/patients/{patient}/dossier/document', [DossierMedicalController::class, 'storeDocument'])->name('dossier.document.store');
    Route::post('/dossier/document/{document}/valider', [DossierMedicalController::class, 'validerDocument'])->name('dossier.document.valider');
    Route::get('/dossier/document/{document}/imprimer', [DossierMedicalController::class, 'imprimerDocument'])->name('dossier.document.imprimer');

    // Pharmacie à deux niveaux : officines et dépôt central
    // Vue d'ensemble : stocks, ruptures, réquisitions et sorties de chaque officine.
    Route::get('/officines/tableau', [OfficineController::class, 'tableau'])->name('officines.tableau');
    Route::get('/officines', [OfficineController::class, 'index'])->name('officines.index');
    Route::post('/officines/{officine}/activer', [OfficineController::class, 'activer'])->name('officines.activer');
    Route::get('/officines/stock', [OfficineController::class, 'stock'])->name('officines.stock');
    Route::post('/officines/requisition', [OfficineController::class, 'storeRequisition'])->name('officines.requisition.store');
    Route::get('/depot-central', [OfficineController::class, 'depot'])->name('officines.depot');
    Route::post('/depot-central/entree', [OfficineController::class, 'entree'])->name('officines.entree');
    Route::post('/requisitions/{requisition}/servir', [OfficineController::class, 'servir'])->name('requisitions.servir');
    Route::post('/requisitions/{requisition}/refuser', [OfficineController::class, 'refuser'])->name('requisitions.refuser');

    // Plan d'administration des traitements (grille 24 h)
    Route::get('/visites/{visit}/traitements', [PlanAdministrationController::class, 'index'])->name('mar.index');
    Route::post('/visites/{visit}/traitements', [PlanAdministrationController::class, 'store'])->name('mar.store');
    Route::post('/visites/{visit}/traitements/copier', [PlanAdministrationController::class, 'copierJourSuivant'])->name('mar.copier');
    Route::post('/traitements/{plan}/basculer', [PlanAdministrationController::class, 'basculer'])->name('mar.basculer');
    Route::delete('/traitements/{plan}', [PlanAdministrationController::class, 'destroy'])->name('mar.destroy');

    // Facturation société / convention et contrôle de caisse
    Route::get('/conventions', [ConventionController::class, 'index'])->name('conventions.index');
    Route::post('/conventions/emettre', [ConventionController::class, 'emettre'])->name('conventions.emettre');
    Route::get('/conventions/dettes', [ConventionController::class, 'dettes'])->name('conventions.dettes');
    Route::get('/conventions/{facture}', [ConventionController::class, 'show'])->name('conventions.show');
    Route::get('/conventions/{facture}/imprimer', [ConventionController::class, 'imprimer'])->name('conventions.imprimer');
    Route::post('/conventions/{facture}/regler', [ConventionController::class, 'regler'])->name('conventions.regler');
    Route::get('/billetage', [ConventionController::class, 'billetage'])->name('caisse.billetage');
    Route::post('/billetage', [ConventionController::class, 'storeBilletage'])->name('caisse.billetage.store');

    // Statistiques de pilotage
    Route::get('/statistiques', [StatistiqueController::class, 'index'])->name('statistiques.index');

    // Agenda des rendez-vous
    Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda.index');
    Route::post('/agenda', [AgendaController::class, 'store'])->name('agenda.store');
    Route::post('/agenda/bloquer', [AgendaController::class, 'bloquer'])->name('agenda.bloquer');
    Route::post('/agenda/{rendezVous}/statut', [AgendaController::class, 'statut'])->name('agenda.statut');
    Route::delete('/agenda/{rendezVous}', [AgendaController::class, 'destroy'])->name('agenda.destroy');

    // Bilan hydrique (dossier infirmier)
    Route::get('/visites/{visit}/bilan-hydrique', [BilanHydriqueController::class, 'index'])->name('bilan-hydrique.index');
    Route::post('/visites/{visit}/bilan-hydrique', [BilanHydriqueController::class, 'store'])->name('bilan-hydrique.store');

    // Dossier infirmier : pansement, gavage, évaluation neuro, transfusion
    Route::get('/visites/{visit}/dossier-infirmier', [DossierInfirmierController::class, 'index'])->name('infirmier.index');
    Route::post('/visites/{visit}/dossier-infirmier/pansement', [DossierInfirmierController::class, 'storePansement'])->name('infirmier.pansement');
    Route::post('/visites/{visit}/dossier-infirmier/gavage', [DossierInfirmierController::class, 'storeGavage'])->name('infirmier.gavage');
    Route::post('/visites/{visit}/dossier-infirmier/neuro', [DossierInfirmierController::class, 'storeNeuro'])->name('infirmier.neuro');
    Route::post('/visites/{visit}/dossier-infirmier/transfusion', [DossierInfirmierController::class, 'storeTransfusion'])->name('infirmier.transfusion');
    Route::post('/transfusions/{transfusion}/terminer', [DossierInfirmierController::class, 'terminerTransfusion'])->name('infirmier.transfusion.terminer');

    // Diète et ménage des patients hospitalisés
    Route::get('/diete-menage', [DieteMenageController::class, 'index'])->name('diete.index');
    Route::get('/diete-menage/imprimer', [DieteMenageController::class, 'imprimer'])->name('diete.imprimer');
    Route::post('/visites/{visit}/diete', [DieteMenageController::class, 'prescrire'])->name('diete.prescrire');
    Route::post('/visites/{visit}/diete/arreter', [DieteMenageController::class, 'arreter'])->name('diete.arreter');
    Route::post('/visites/{visit}/menage', [DieteMenageController::class, 'menage'])->name('diete.menage');

    // Équipements (machines labo / imagerie)
    Route::get('/equipements', [EquipementController::class, 'index'])->name('equipements.index');
    Route::post('/equipements', [EquipementController::class, 'store'])->name('equipements.store');

    // Pharmacie
    Route::get('/pharmacie', [PharmacieController::class, 'dashboard'])->name('pharmacie.dashboard');
    Route::get('/pharmacie/stock', [PharmacieController::class, 'stock'])->name('pharmacie.stock');
    Route::get('/pharmacie/prescriptions', [PharmacieController::class, 'prescriptions'])->name('pharmacie.prescriptions');
    Route::get('/pharmacie/prescriptions/{prescription}', [PharmacieController::class, 'showPrescription'])->name('pharmacie.prescription');
    // Ordonnance imprimable ; ?type=externe pour les produits achetés en ville, sans prix.
    Route::get('/prescriptions/{prescription}/ordonnance', [PharmacieController::class, 'ordonnance'])->name('prescriptions.ordonnance');
    Route::get('/pharmacie/medicaments', [PharmacieController::class, 'medicaments'])->name('pharmacie.medicaments');
    Route::post('/pharmacie/medicaments', [PharmacieController::class, 'storeMedicament'])->name('pharmacie.medicaments.store');
    Route::post('/pharmacie/prescriptions/{prescription}/dispenser', [PharmacieController::class, 'dispenser'])->name('pharmacie.dispenser');
    Route::post('/pharmacie/stock/{medicament}/mouvement', [PharmacieController::class, 'mouvementStock'])->name('pharmacie.stock.mouvement');

    // Caisse
    Route::get('/caisse', [CaisseController::class, 'index'])->name('caisse.index');
    Route::get('/caisse/{facture}', [CaisseController::class, 'show'])->name('caisse.show');
    Route::get('/caisse/{facture}/imprimer', [CaisseController::class, 'imprimer'])->name('caisse.imprimer');
    Route::post('/caisse/{facture}/encaisser', [CaisseController::class, 'encaisser'])->name('caisse.encaisser');
    Route::post('/caisse/{facture}/utiliser-acompte', [CaisseController::class, 'utiliserAcompte'])->name('caisse.acompte');
    Route::post('/caisse/facturer/{prescription}', [CaisseController::class, 'facturer'])->name('caisse.facturer');
    Route::post('/caisse/prescription/{prescription}', [CaisseController::class, 'creerDepuisPrescription'])->name('caisse.prescription');
});
