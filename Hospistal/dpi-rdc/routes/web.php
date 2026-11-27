<?php

use App\Http\Controllers\AcompteController;
use App\Http\Controllers\ActeCliniqueController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AmbulanceController;
use App\Http\Controllers\ApparenceController;
use App\Http\Controllers\AssuranceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AvoirController;
use App\Http\Controllers\BanqueSangController;
use App\Http\Controllers\BilanHydriqueController;
use App\Http\Controllers\BlocOperatoireController;
use App\Http\Controllers\BonPriseEnChargeController;
use App\Http\Controllers\CaisseController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CompteController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\ConventionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DialyseController;
use App\Http\Controllers\DieteMenageController;
use App\Http\Controllers\DisponibiliteController;
use App\Http\Controllers\DossierInfirmierController;
use App\Http\Controllers\DossierMedicalController;
use App\Http\Controllers\EquipementController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ForfaitController;
use App\Http\Controllers\GrandDepotController;
use App\Http\Controllers\LaboratoireController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MessagerieController;
use App\Http\Controllers\MethodePaiementController;
use App\Http\Controllers\MaterniteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NutritionController;
use App\Http\Controllers\OfficineController;
use App\Http\Controllers\ParametreController;
use App\Http\Controllers\ParcoursController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientIdentiteController;
use App\Http\Controllers\PharmacieController;
use App\Http\Controllers\PlanAdministrationController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\PreventionController;
use App\Http\Controllers\RapportSnisController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\RechercheController;
use App\Http\Controllers\RecouvrementController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ServiceHospitalierController;
use App\Http\Controllers\TacheController;
use App\Http\Controllers\StatistiqueController;
use App\Http\Controllers\TarifController;
use App\Http\Controllers\TarificationController;
use App\Http\Controllers\TransfertServiceController;
use App\Http\Controllers\UrgenceController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

/*
 * La page servie par le service worker quand le réseau est coupé. Elle est
 * ouverte à tous : hors connexion, il n'y a plus de session à vérifier — et
 * elle ne montre rien de plus que le nom de l'application.
 */
Route::view('/hors-ligne', 'hors-ligne')->name('hors-ligne');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Mes tâches : le travail de chaque poste, rafraîchi seul (avec bip).
    Route::get('/taches', [TacheController::class, 'index'])->name('taches.index');
    Route::get('/taches/fragment', [TacheController::class, 'fragment'])->name('taches.fragment');
    Route::post('/taches/resultats/{resultat}', [TacheController::class, 'resultat'])->name('taches.resultat');

    // La recherche, depuis n'importe quel écran : un geste gagné à chaque fois.
    Route::get('/recherche', [RechercheController::class, 'index'])->name('recherche');

    // Patients
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::get('/patients-recherche', [PatientController::class, 'recherche'])->name('patients.recherche');
    Route::get('/patients/nouveau', [PatientController::class, 'create'])->name('patients.create');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
    Route::post('/patients/{patient}/envoyer-caisse', [PatientController::class, 'envoyerCaisse'])->name('patients.envoyer-caisse');
    Route::post('/patients/{patient}/assurance', [PatientController::class, 'majAssurance'])->name('patients.assurance');

    // Partie administrative du dossier : identité, carte, photo, empreintes,
    // relations familiales, méthodes de paiement, carte de vaccination.
    Route::get('/patients/{patient}/modifier', [PatientIdentiteController::class, 'edit'])->name('patients.edit');
    Route::put('/patients/{patient}', [PatientIdentiteController::class, 'update'])->name('patients.update');
    Route::get('/patients/{patient}/carte', [PatientIdentiteController::class, 'carte'])->name('patients.carte');
    Route::post('/patients/{patient}/photo', [PatientIdentiteController::class, 'photo'])->name('patients.photo.store');
    Route::get('/photos/patients/{patient}', [PatientIdentiteController::class, 'voirPhoto'])->name('patients.photo');
    Route::get('/patients/{patient}/empreintes', [PatientIdentiteController::class, 'empreintes'])->name('patients.empreintes');
    Route::post('/patients/{patient}/empreintes', [PatientIdentiteController::class, 'enregistrerEmpreinte'])->name('patients.empreintes.store');
    Route::delete('/empreintes/{empreinte}', [PatientIdentiteController::class, 'supprimerEmpreinte'])->name('patients.empreintes.destroy');
    Route::get('/empreintes/{empreinte}/image', [PatientIdentiteController::class, 'imageEmpreinte'])->name('patients.empreintes.image');
    Route::post('/patients/{patient}/relations', [PatientIdentiteController::class, 'ajouterRelation'])->name('patients.relations.store');
    Route::delete('/relations-familiales/{relation}', [PatientIdentiteController::class, 'supprimerRelation'])->name('patients.relations.destroy');
    Route::post('/patients/{patient}/methodes-paiement', [PatientIdentiteController::class, 'ajouterMethode'])->name('patients.methodes.store');
    Route::delete('/patient-methodes-paiement/{lien}', [PatientIdentiteController::class, 'retirerMethode'])->name('patients.methodes.destroy');
    Route::get('/patients/{patient}/carte-vaccination', [PatientIdentiteController::class, 'carteVaccination'])->name('patients.carte-vaccination');

    // Catalogue de l'établissement
    Route::get('/catalogue/examens', [CatalogueController::class, 'examens'])->name('catalogue.examens.index');
    Route::get('/catalogue/examens/nouveau', [CatalogueController::class, 'creerExamen'])->name('catalogue.examens.create');
    Route::get('/catalogue/imagerie', [CatalogueController::class, 'imagerie'])->name('catalogue.imagerie.index');
    Route::get('/catalogue/imagerie/nouveau', [CatalogueController::class, 'creerImagerie'])->name('catalogue.imagerie.create');
    Route::post('/catalogue/examens', [CatalogueController::class, 'enregistrerExamen'])->name('catalogue.examens.store');
    Route::get('/catalogue/examens/{type}/modifier', [CatalogueController::class, 'modifierExamen'])->name('catalogue.examens.edit');
    Route::post('/catalogue/examens/{type}', [CatalogueController::class, 'enregistrerExamen'])->name('catalogue.examens.update');
    Route::post('/catalogue/examens/{type}/basculer', [CatalogueController::class, 'basculerExamen'])->name('catalogue.examens.basculer');
    Route::get('/catalogue/services', [CatalogueController::class, 'services'])->name('catalogue.services.index');
    Route::get('/catalogue/services/nouveau', [CatalogueController::class, 'creerService'])->name('catalogue.services.create');
    Route::post('/catalogue/services', [CatalogueController::class, 'enregistrerService'])->name('catalogue.services.store');
    Route::get('/catalogue/services/{service}/modifier', [CatalogueController::class, 'modifierService'])->name('catalogue.services.edit');
    Route::post('/catalogue/services/{service}', [CatalogueController::class, 'enregistrerService'])->name('catalogue.services.update');
    Route::post('/catalogue/services/{service}/basculer', [CatalogueController::class, 'basculerService'])->name('catalogue.services.basculer');
    Route::get('/catalogue/locaux', [CatalogueController::class, 'locaux'])->name('catalogue.locaux.index');
    Route::get('/catalogue/locaux/nouveau', [CatalogueController::class, 'creerLocal'])->name('catalogue.locaux.create');
    Route::post('/catalogue/locaux', [CatalogueController::class, 'enregistrerLocal'])->name('catalogue.locaux.store');
    Route::get('/catalogue/locaux/{local}/modifier', [CatalogueController::class, 'modifierLocal'])->name('catalogue.locaux.edit');
    Route::post('/catalogue/locaux/{local}', [CatalogueController::class, 'enregistrerLocal'])->name('catalogue.locaux.update');
    Route::post('/catalogue/locaux/{local}/basculer', [CatalogueController::class, 'basculerLocal'])->name('catalogue.locaux.basculer');
    Route::get('/catalogue/lits', [CatalogueController::class, 'lits'])->name('catalogue.lits.index');
    Route::get('/catalogue/lits/nouveau', [CatalogueController::class, 'creerLit'])->name('catalogue.lits.create');
    Route::post('/catalogue/lits', [CatalogueController::class, 'enregistrerLit'])->name('catalogue.lits.store');
    Route::get('/catalogue/lits/{lit}/modifier', [CatalogueController::class, 'modifierLit'])->name('catalogue.lits.edit');
    Route::post('/catalogue/lits/{lit}', [CatalogueController::class, 'enregistrerLit'])->name('catalogue.lits.update');
    Route::post('/catalogue/lits/{lit}/basculer', [CatalogueController::class, 'basculerLit'])->name('catalogue.lits.basculer');
    Route::get('/catalogue/methodes-paiement', [MethodePaiementController::class, 'index'])->name('methodes-paiement.index');
    Route::post('/catalogue/methodes-paiement', [MethodePaiementController::class, 'enregistrer'])->name('methodes-paiement.store');
    Route::post('/catalogue/methodes-paiement/{methode}/basculer', [MethodePaiementController::class, 'basculer'])->name('methodes-paiement.basculer');

    // Tarification : catégories de convention × fonctions tarifaires, catalogue des actes
    Route::get('/tarification/categories', [TarificationController::class, 'categories'])->name('tarification.categories');
    Route::post('/tarification/categories', [TarificationController::class, 'enregistrerCategorie'])->name('tarification.categories.store');
    Route::post('/tarification/categories/{categorie}', [TarificationController::class, 'enregistrerCategorie'])->name('tarification.categories.update');
    Route::delete('/tarification/categories/{categorie}', [TarificationController::class, 'supprimerCategorie'])->name('tarification.categories.destroy');
    Route::post('/tarification/conventions/{assurance}/classer', [TarificationController::class, 'classerConvention'])->name('tarification.conventions.classer');
    Route::get('/tarification/actes', [TarificationController::class, 'actes'])->name('tarification.actes');
    Route::get('/tarification/actes/nouveau', [TarificationController::class, 'creerActe'])->name('tarification.actes.create');
    Route::post('/tarification/actes', [TarificationController::class, 'enregistrerActe'])->name('tarification.actes.store');
    Route::get('/tarification/actes/{acte}', [TarificationController::class, 'modifierActe'])->name('tarification.actes.edit');
    Route::post('/tarification/actes/{acte}', [TarificationController::class, 'enregistrerActe'])->name('tarification.actes.update');

    // Grand dépôt pharmaceutique
    Route::prefix('grand-depot')->name('grand-depot.')->group(function () {
        Route::get('/', [GrandDepotController::class, 'tableau'])->name('tableau');
        Route::get('/fournisseurs', [GrandDepotController::class, 'fournisseurs'])->name('fournisseurs');
        Route::post('/fournisseurs', [GrandDepotController::class, 'enregistrerFournisseur'])->name('fournisseurs.store');
        Route::post('/fournisseurs/{fournisseur}/basculer', [GrandDepotController::class, 'basculerFournisseur'])->name('fournisseurs.basculer');
        Route::get('/commandes', [GrandDepotController::class, 'commandes'])->name('commandes');
        Route::get('/commandes/nouvelle', [GrandDepotController::class, 'creerCommande'])->name('commandes.create');
        Route::post('/commandes', [GrandDepotController::class, 'enregistrerCommande'])->name('commandes.store');
        Route::get('/commandes/{commande}', [GrandDepotController::class, 'voirCommande'])->name('commandes.show');
        Route::post('/commandes/{commande}/envoyer', [GrandDepotController::class, 'envoyerCommande'])->name('commandes.envoyer');
        Route::post('/commandes/{commande}/annuler', [GrandDepotController::class, 'annulerCommande'])->name('commandes.annuler');
        Route::post('/commandes/{commande}/recevoir', [GrandDepotController::class, 'recevoirCommande'])->name('commandes.recevoir');
        Route::get('/receptions', [GrandDepotController::class, 'receptions'])->name('receptions');
        Route::get('/receptions/nouvelle', [GrandDepotController::class, 'creerReception'])->name('receptions.create');
        Route::post('/receptions', [GrandDepotController::class, 'enregistrerReception'])->name('receptions.store');
        Route::get('/receptions/{reception}', [GrandDepotController::class, 'voirReception'])->name('receptions.show');
        Route::get('/inventaires', [GrandDepotController::class, 'inventaires'])->name('inventaires');
        Route::post('/inventaires', [GrandDepotController::class, 'demarrerInventaire'])->name('inventaires.demarrer');
        Route::get('/inventaires/{inventaire}', [GrandDepotController::class, 'voirInventaire'])->name('inventaires.show');
        Route::post('/inventaires/{inventaire}', [GrandDepotController::class, 'saisirInventaire'])->name('inventaires.saisir');
        Route::get('/destructions', [GrandDepotController::class, 'destructions'])->name('destructions');
        Route::post('/destructions', [GrandDepotController::class, 'enregistrerDestruction'])->name('destructions.store');
        Route::get('/destructions/{destruction}', [GrandDepotController::class, 'voirDestruction'])->name('destructions.show');
        Route::get('/fiche-stock/{medicament}', [GrandDepotController::class, 'ficheStock'])->name('fiche');
    });

    // Gestion financière
    Route::prefix('finances')->name('finance.')->group(function () {
        Route::get('/', [FinanceController::class, 'tableau'])->name('tableau');
        Route::get('/depenses', [FinanceController::class, 'depenses'])->name('depenses');
        Route::get('/depenses/nouvelle', [FinanceController::class, 'creerDepense'])->name('depenses.create');
        Route::post('/depenses', [FinanceController::class, 'enregistrerDepense'])->name('depenses.store');
        Route::post('/depenses/{depense}/approuver', [FinanceController::class, 'approuver'])->name('depenses.approuver');
        Route::post('/depenses/{depense}/rejeter', [FinanceController::class, 'rejeter'])->name('depenses.rejeter');
        Route::post('/depenses/{depense}/payer', [FinanceController::class, 'payer'])->name('depenses.payer');
        Route::get('/depenses/{depense}/piece', [FinanceController::class, 'piece'])->name('depenses.piece');
        Route::get('/tresorerie', [FinanceController::class, 'comptes'])->name('comptes');
        Route::post('/tresorerie/comptes', [FinanceController::class, 'enregistrerCompte'])->name('comptes.store');
        Route::post('/tresorerie/transferts', [FinanceController::class, 'transferer'])->name('transferts.store');
        Route::get('/tresorerie/{compte}/journal', [FinanceController::class, 'journal'])->name('journal');
        Route::get('/budget', [FinanceController::class, 'budget'])->name('budget');
        Route::post('/budget', [FinanceController::class, 'enregistrerBudget'])->name('budget.store');
        Route::post('/rubriques', [FinanceController::class, 'enregistrerRubrique'])->name('rubriques.store');
        Route::get('/resultat', [FinanceController::class, 'resultat'])->name('resultat');
        Route::post('/cloture', [FinanceController::class, 'cloturer'])->name('cloturer');
    });

    // Ambulances
    Route::get('/ambulances', [AmbulanceController::class, 'index'])->name('ambulances.index');
    Route::post('/ambulances', [AmbulanceController::class, 'enregistrerAmbulance'])->name('ambulances.store');
    Route::post('/ambulances/{ambulance}/basculer', [AmbulanceController::class, 'basculerAmbulance'])->name('ambulances.basculer');
    Route::post('/ambulances/sorties', [AmbulanceController::class, 'enregistrerSortie'])->name('ambulances.sorties.store');
    Route::post('/ambulances/sorties/{sortie}/retour', [AmbulanceController::class, 'retour'])->name('ambulances.sorties.retour');

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
    // Le temps du parcours : ce que le séjour a coûté au patient, en heures.
    Route::get('/visites/{visit}/chronologie', [ParcoursController::class, 'chronologie'])->name('parcours.chronologie');
    Route::post('/visites/{visit}/hospitaliser', [VisitController::class, 'hospitaliser'])->name('visites.hospitaliser');
    Route::post('/visites/{visit}/facturer-sejour', [VisitController::class, 'facturerSejour'])->name('visites.facturer-sejour');
    Route::post('/visites/{visit}/sortir', [VisitController::class, 'sortir'])->name('visites.sortir');
    Route::get('/visites/{visit}/bulletin', [VisitController::class, 'bulletin'])->name('visites.bulletin');

    // Laboratoire
    Route::get('/labo', [LaboratoireController::class, 'index'])->name('labo.index');
    Route::get('/labo/prescrire', [LaboratoireController::class, 'create'])->name('labo.create');
    Route::post('/labo', [LaboratoireController::class, 'store'])->name('labo.store');
    Route::get('/labo/{examen}', [LaboratoireController::class, 'show'])->name('labo.show');
    Route::get('/labo/{examen}/bon', [LaboratoireController::class, 'bon'])->name('labo.bon');
    Route::get('/labo/{examen}/bulletin', [LaboratoireController::class, 'bulletin'])->name('labo.bulletin');
    // Résultats en PDF pour le prescripteur : il lit sans entrer au plateau technique.
    Route::get('/examens/{examen}/resultat.pdf', [LaboratoireController::class, 'pdfResultat'])->name('examens.pdf');
    // Les pièces jointes passent par l'application : une radiographie
    // nominative n'est pas servie comme une feuille de style.
    Route::get('/examens/{examen}/pieces/{fichier}', [LaboratoireController::class, 'piece'])->name('examens.piece');
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
    Route::get('/banque-sang/registre', [BanqueSangController::class, 'registre'])->name('banque-sang.registre');
    Route::get('/banque-sang/reseau', [BanqueSangController::class, 'reseau'])->name('banque-sang.reseau');
    Route::post('/banque-sang/reseau/partage', [BanqueSangController::class, 'reglerPartage'])->name('banque-sang.partage');
    Route::post('/banque-sang/reseau/rafraichir', [BanqueSangController::class, 'rafraichirReseau'])->name('banque-sang.reseau.rafraichir');
    Route::get('/banque-sang/donneurs', [BanqueSangController::class, 'donneurs'])->name('banque-sang.donneurs');
    Route::post('/banque-sang/donneurs', [BanqueSangController::class, 'enregistrerDonneur'])->name('banque-sang.donneurs.store');
    Route::post('/banque-sang/donneurs/{donneur}/don', [BanqueSangController::class, 'enregistrerDon'])->name('banque-sang.don');
    Route::post('/banque-sang/donneurs/{donneur}/eligibilite', [BanqueSangController::class, 'reglerEligibilite'])->name('banque-sang.eligibilite');
    Route::post('/banque-sang/transfusions/{transfusion}/cloturer', [BanqueSangController::class, 'cloturerTransfusion'])->name('banque-sang.cloturer');
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
    Route::post('/parametres/remise', [ParametreController::class, 'plafondRemise'])->name('parametres.remise');

    // Tarifs : ce que coûte chaque prestation, révisable sans développeur
    Route::get('/parametres/tarifs', [TarifController::class, 'index'])->name('tarifs.index');
    Route::post('/parametres/tarifs/consultation/{type}', [TarifController::class, 'consultation'])->name('tarifs.consultation');
    Route::post('/parametres/tarifs/examen/{type}', [TarifController::class, 'examen'])->name('tarifs.examen');
    Route::post('/parametres/tarifs/medicament/{medicament}', [TarifController::class, 'medicament'])->name('tarifs.medicament');
    Route::post('/parametres/tarifs/diete/{type}', [TarifController::class, 'diete'])->name('tarifs.diete');
    Route::post('/parametres/tarifs/{famille}/{id}/basculer', [TarifController::class, 'basculer'])->name('tarifs.basculer');

    // Comptes du personnel et profils d'utilisation
    Route::get('/utilisateurs', [UtilisateurController::class, 'index'])->name('utilisateurs.index');
    Route::post('/utilisateurs', [UtilisateurController::class, 'store'])->name('utilisateurs.store');
    Route::post('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'update'])->name('utilisateurs.update');
    Route::post('/utilisateurs/{utilisateur}/basculer', [UtilisateurController::class, 'basculer'])->name('utilisateurs.basculer');
    Route::post('/utilisateurs/{utilisateur}/mot-de-passe', [UtilisateurController::class, 'motDePasse'])->name('utilisateurs.mot-de-passe');
    Route::get('/utilisateurs/{utilisateur}/modifier', [UtilisateurController::class, 'edit'])->name('utilisateurs.edit');
    Route::post('/utilisateurs/{utilisateur}/identite', [UtilisateurController::class, 'identite'])->name('utilisateurs.identite');
    Route::delete('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'destroy'])->name('utilisateurs.destroy');
    Route::get('/utilisateurs/{utilisateur}/activites', [UtilisateurController::class, 'activites'])->name('utilisateurs.activites.agent');
    Route::get('/activites', [UtilisateurController::class, 'activites'])->name('utilisateurs.activites');

    // Rôles des utilisateurs et leurs permissions
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/nouveau', [RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}/modifier', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    // Rapports d'activité
    Route::get('/rapports', [RapportController::class, 'index'])->name('rapports.index');
    Route::get('/rapports/caisse/{jour}', [RapportController::class, 'caisseJour'])->name('rapports.caisse.jour');
    Route::get('/rapports/{rapport}', [RapportController::class, 'afficher'])->name('rapports.afficher');

    // Chat — messagerie interne
    Route::get('/chat', [MessagerieController::class, 'index'])->name('messagerie.index');
    Route::get('/chat/non-lus', [MessagerieController::class, 'nonLus'])->name('messagerie.non-lus');
    Route::get('/chat/{avec}', [MessagerieController::class, 'index'])->name('messagerie.conversation');
    Route::get('/chat/{avec}/fil', [MessagerieController::class, 'fil'])->name('messagerie.fil');
    Route::post('/chat/{avec}', [MessagerieController::class, 'envoyer'])->name('messagerie.envoyer');
    Route::delete('/messages/{message}', [MessagerieController::class, 'supprimer'])->name('messagerie.supprimer');

    // Mon compte
    Route::get('/mon-compte', [CompteController::class, 'show'])->name('compte.index');
    Route::post('/mon-compte/photo', [CompteController::class, 'photo'])->name('compte.photo.store');
    Route::post('/mon-compte/informations', [CompteController::class, 'informations'])->name('compte.informations');
    Route::post('/mon-compte/mot-de-passe', [CompteController::class, 'motDePasse'])->name('compte.mot-de-passe');
    Route::get('/photos/agents/{user}', [CompteController::class, 'voirPhoto'])->name('compte.photo');

    // Maintenance du serveur
    Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance/deconnecter', [MaintenanceController::class, 'deconnecterTous'])->name('maintenance.deconnecter');
    Route::post('/maintenance/sauvegarder', [MaintenanceController::class, 'sauvegarder'])->name('maintenance.sauvegarder');
    Route::get('/maintenance/sauvegardes/{nom}', [MaintenanceController::class, 'telecharger'])->name('maintenance.telecharger');
    Route::post('/maintenance/rafraichir', [MaintenanceController::class, 'rafraichir'])->name('maintenance.rafraichir');
    Route::post('/maintenance/vider', [MaintenanceController::class, 'vider'])->name('maintenance.vider');
    // L'attente à l'échelle de l'hôpital : quel poste, quel jour, quelle heure.
    Route::get('/attente', [ParcoursController::class, 'attente'])->name('parcours.attente');
    Route::get('/mon-temps', [ParcoursController::class, 'profil'])->name('parcours.moi');
    Route::get('/utilisateurs/{utilisateur}/temps', [ParcoursController::class, 'profil'])->name('parcours.profil');

    // Sociétés conventionnées : contrat, modalités et règles de couverture
    Route::get('/assurances', [AssuranceController::class, 'index'])->name('assurances.index');
    Route::post('/assurances', [AssuranceController::class, 'store'])->name('assurances.store');
    Route::get('/assurances/{assurance}', [AssuranceController::class, 'show'])->name('assurances.show');
    Route::post('/assurances/{assurance}', [AssuranceController::class, 'update'])->name('assurances.update');
    Route::post('/assurances/{assurance}/basculer', [AssuranceController::class, 'basculer'])->name('assurances.basculer');
    Route::post('/assurances/{assurance}/couvertures', [AssuranceController::class, 'ajouterCouverture'])->name('assurances.couvertures');
    Route::delete('/couvertures/{couverture}', [AssuranceController::class, 'supprimerCouverture'])->name('assurances.couvertures.destroy');
    Route::post('/assurances/{assurance}/tarifs', [AssuranceController::class, 'ajouterTarif'])->name('assurances.tarifs');
    Route::delete('/tarifs-convention/{tarif}', [AssuranceController::class, 'supprimerTarif'])->name('assurances.tarifs.destroy');

    // Bons de prise en charge : saisis avant la facture, ils ouvrent le tiers payant.
    Route::get('/patients/{patient}/bons-prise-en-charge', [BonPriseEnChargeController::class, 'index'])->name('bpc.index');
    Route::post('/patients/{patient}/bons-prise-en-charge', [BonPriseEnChargeController::class, 'store'])->name('bpc.store');
    Route::post('/bons-prise-en-charge/{bon}/annuler', [BonPriseEnChargeController::class, 'annuler'])->name('bpc.annuler');

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
    // Le service place le patient que le médecin lui a orienté : la décision
    // médicale nomme le service, la logistique choisit le lit.
    Route::post('/services/{service}/admissions/{visit}', [ServiceHospitalierController::class, 'admettre'])->name('services.admettre');
    Route::post('/lits/{lit}/statut', [ServiceHospitalierController::class, 'statutLit'])->name('lits.statut');

    // Notifications internes (labo / imagerie / pharmacie ↔ médecins)
    // Le thème de chacun : « trop de blanc », disaient ceux qui passent la
    // journée devant l'écran.
    Route::get('/apparence', [ApparenceController::class, 'index'])->name('apparence.index');
    Route::post('/apparence', [ApparenceController::class, 'enregistrer'])->name('apparence.enregistrer');

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

    // Recouvrement : tableau de bord, dépôt, rejets et relances des bordereaux
    Route::get('/recouvrement', [RecouvrementController::class, 'index'])->name('recouvrement.index');
    Route::post('/conventions/{facture}/depot', [RecouvrementController::class, 'depot'])->name('recouvrement.depot');
    Route::post('/conventions/{facture}/rejets', [RecouvrementController::class, 'rejet'])->name('recouvrement.rejet');
    Route::post('/rejets-convention/{rejet}/traiter', [RecouvrementController::class, 'traiterRejet'])->name('recouvrement.rejet.traiter');
    Route::post('/conventions/{facture}/relances', [RecouvrementController::class, 'relance'])->name('recouvrement.relance');
    Route::get('/billetage', [ConventionController::class, 'billetage'])->name('caisse.billetage');
    Route::post('/billetage', [ConventionController::class, 'storeBilletage'])->name('caisse.billetage.store');

    // Statistiques de pilotage
    Route::get('/statistiques', [StatistiqueController::class, 'index'])->name('statistiques.index');

    // Le registre nutritionnel : UNTA, UNTI et UNS. Il tenait sur des fiches
    // cartonnées et se recomptait à la main le 30 du mois.
    Route::get('/nutrition', [NutritionController::class, 'index'])->name('nutrition.index');
    Route::get('/patients/{patient}/nutrition', [NutritionController::class, 'patient'])->name('nutrition.patient');
    Route::post('/patients/{patient}/nutrition/mesure', [NutritionController::class, 'storeMesure'])->name('nutrition.mesure');
    Route::post('/patients/{patient}/nutrition/admission', [NutritionController::class, 'storeAdmission'])->name('nutrition.admission');
    Route::post('/nutrition/{suivi}/decharger', [NutritionController::class, 'decharger'])->name('nutrition.decharger');

    // Les registres préventifs : planification familiale et PEV. Ils
    // vivaient entièrement sur papier.
    Route::get('/planification-familiale', [PreventionController::class, 'pf'])->name('prevention.pf');
    Route::get('/vaccination', [PreventionController::class, 'pev'])->name('prevention.pev');
    Route::get('/patients/{patient}/prevention', [PreventionController::class, 'patient'])->name('prevention.patient');
    Route::post('/patients/{patient}/planification-familiale', [PreventionController::class, 'storePf'])->name('prevention.pf.store');
    Route::post('/patients/{patient}/vaccination', [PreventionController::class, 'storeVaccination'])->name('prevention.vaccination.store');

    // Rapport mensuel remonté à la zone de santé : il se remplissait à la main.
    Route::get('/snis', [RapportSnisController::class, 'index'])->name('snis.index');
    Route::get('/snis/csv', [RapportSnisController::class, 'csv'])->name('snis.csv');
    Route::get('/snis/imprimer', [RapportSnisController::class, 'imprimer'])->name('snis.imprimer');
    // Le canevas suivi par l'établissement : CS, HGR, HST ou BCZ.
    Route::post('/snis/systeme', [RapportSnisController::class, 'definirSysteme'])->name('snis.systeme');

    // Agenda des rendez-vous
    Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda.index');
    Route::post('/agenda', [AgendaController::class, 'store'])->name('agenda.store');
    Route::get('/agenda/{rendezVous}/convocation', [AgendaController::class, 'imprimer'])->name('agenda.convocation');
    Route::post('/agenda/bloquer', [AgendaController::class, 'bloquer'])->name('agenda.bloquer');
    Route::post('/agenda/{rendezVous}/statut', [AgendaController::class, 'statut'])->name('agenda.statut');
    Route::delete('/agenda/{rendezVous}', [AgendaController::class, 'destroy'])->name('agenda.destroy');

    // Bilan hydrique (dossier infirmier)
    Route::get('/visites/{visit}/bilan-hydrique', [BilanHydriqueController::class, 'index'])->name('bilan-hydrique.index');
    Route::post('/visites/{visit}/bilan-hydrique', [BilanHydriqueController::class, 'store'])->name('bilan-hydrique.store');

    // Dossier infirmier : pansement, gavage, évaluation neuro, transfusion
    Route::get('/visites/{visit}/dossier-infirmier', [DossierInfirmierController::class, 'index'])->name('infirmier.index');
    // Le registre des actes : tout ce que fait l'équipe et qui ne laissait
    // aucune trace — injection, perfusion, sondage, oxygène, nursing.
    Route::post('/visites/{visit}/dossier-infirmier/actes', [DossierInfirmierController::class, 'storeActe'])->name('infirmier.actes');
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

    // Avoirs et journal des pièces de caisse — une facture émise ne se
    // modifie plus : elle se corrige par un avoir.
    Route::get('/caisse/{facture}/journal', [AvoirController::class, 'journal'])->name('caisse.journal');
    Route::get('/caisse/{facture}/avoir', [AvoirController::class, 'create'])->name('avoirs.create');
    Route::post('/caisse/{facture}/avoir', [AvoirController::class, 'store'])->name('avoirs.store');
    Route::post('/caisse/{facture}/remise', [AvoirController::class, 'remise'])->name('caisse.remise');
    Route::post('/caisse/{facture}/remise/demande', [AvoirController::class, 'demanderRemise'])->name('caisse.remise.demande');
    Route::get('/avoirs', [AvoirController::class, 'index'])->name('avoirs.index');
    Route::get('/avoirs/{avoir}', [AvoirController::class, 'show'])->name('avoirs.show');
    Route::post('/avoirs/{avoir}/refacturer', [AvoirController::class, 'refacturer'])->name('avoirs.refacturer');
    Route::post('/avoirs/{avoir}/rembourser', [AvoirController::class, 'rembourser'])->name('avoirs.rembourser');
});
