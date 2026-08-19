# Documentation Fonctionnelle — GPS-Monkole (DMI)
## Analyse de référence pour la complétion de DPI-RDC

**Source :** Logiciel GPS-Monkole (DMI v. 10.0.3.0), utilisé en production au Centre Hospitalier Monkole (CHME) et sites associés (CMMG, CSMKL2, Centre de Santé Monkole 2, Centre Médical Monkole Gombe).

**Objectif du document :** Capturer le fonctionnement métier réel d'un DMI congolais déjà déployé, pour servir de référence à Fable 5 dans la complétion et l'amélioration de DPI-RDC (Laravel 12 + Livewire 3 + PostgreSQL 16).

**Statut :** Document en construction — alimenté par lots de captures d'écran. Ce lot couvre le **Module Accueil / Réception & Gestion des lieux de travail** (captures 1 à 16).

---

## Sommaire

1. [Module Accueil / Réception & Lieux de travail](#module-1--accueil--réception--lieux-de-travail)
2. [Module Facturation](#module-2--facturation)
3. [Module Dossier Patient](#module-3--dossier-patient)
4. [Module Hospitalisation](#module-4--hospitalisation)
5. [Module Urgences](#module-5--urgences)
6. [Module Statistiques (transverses)](#module-6--statistiques-transverses)
7. [Module Imagerie Médicale](#module-7--imagerie-médicale)
8. [Module Laboratoire](#module-8--laboratoire)
9. [Module Pharmacie](#module-9--pharmacie)
10. [Module Bloc Opératoire](#module-10--bloc-opératoire)
11. [Module Autres](#module-11--autres)
12. [Module Système / Administration](#module-12--système--administration)
13. *(à venir)* Consultations
14. *(à venir)* Autres modules restants

---

## Module 1 : Accueil / Réception & Lieux de travail

### 1.1 Écran d'accueil (Menu principal)

**Captures :** 1, 4

**Objectif de l'écran**
Point d'entrée unique de l'application. Affiche l'identité de l'établissement, le lieu de travail courant, et donne accès à tous les modules via un menu latéral.

**Éléments visibles**
- En-tête de fenêtre : nom du logiciel + version (`GPS - Monkole | DMI v. 10.0.3.0`)
- Bandeau du lieu de travail courant (ex : `CHME - Réception-CHME`) avec bouton **Modifier Lieu**
- Bloc **Réception** (dépliable) avec 3 raccourcis principaux en icônes : **Recherche Patient**, **Information**, **Rapports**, + bouton **Situation Hospi**
- Liste de modules sous forme d'accordéon (chevrons dépliants) : Facturation, Dossier Patient, Hospitalisation, Urgences, Statistiques, Imagerie Médicale, Laboratoire, Pharmacie, Bloc Opératoire, Autres, Fiche Obstétricale, Endoscopie, Système, Messagerie, Covid Center
- Icônes de raccourcis en bas à gauche (ambulance, dossier/recherche, patient, médecin)
- Pied de page : lien site web, copyright, bouton **Quitter**

**Règles métier observées**
- Le menu est **organisé par accordéons dépliables** plutôt que par onglets — chaque grand module masque ses sous-fonctions jusqu'au clic.
- Le **lieu de travail** (site + poste) est une notion centrale affichée en permanence dans le bandeau supérieur : toute l'application se comporte différemment selon ce contexte (ex : filtrage des patients, des files d'attente, de la facturation par site).
- Présence d'un module **Covid Center** dédié — séquelle probable de la période COVID, avec ses propres sous-écrans (vaccination, hospitalisation Covid).

**Lien avec DPI-RDC**
DPI-RDC a déjà une architecture multi-établissement (3 niveaux : instance locale Docker par hôpital → nœuds régionaux → serveur central Kinshasa). Le concept de **« lieu de travail » actif** (site + poste précis, pas juste l'hôpital) est un niveau de granularité supplémentaire à considérer : ici, un utilisateur choisit non seulement son site (CHME/CMMG/CSMKL2) mais aussi son **poste exact** (ex : "Cabinet Médical 07-M2", "Salle de Soins-M2", "RH", "CPN-M2"). Ça vaut le coup de vérifier si Spatie RBAC + le modèle multi-tenant de DPI-RDC capturent déjà ce niveau (site + poste), ou seulement le site.

---

### 1.2 Sélection / modification du lieu de travail

**Captures :** 2, 3

**Objectif de l'écran**
Permet à l'utilisateur de changer son contexte de travail (site + poste) sans se reconnecter.

**Workflow**
1. Clic sur **Modifier Lieu** (écran principal) → ouverture d'une modale **Lieu de travail** affichant le lieu courant (capture 2)
2. Clic sur **Modifier** → ouverture d'une liste complète des lieux disponibles, groupés par site (capture 3) : colonnes **Site** et **Nom**, avec recherche par colonne (icône loupe)
3. Sélection d'une ligne → **Ok** pour valider, **Annuler** pour abandonner
4. Retour à la modale précédente → **Valider** (✓ vert) ou **Annuler** (✗ rouge)

**Données observées (liste des postes/lieux)**
| Site | Exemples de postes |
|---|---|
| CHME | Cabinet Médical 07-M2, Salle de Soins-M2, Admissions, RH, CPN-M2, Poste de Validation, Cabinet Médical Covid center 1/2/3, CTI-CHME, Covid-Vaccination, Covid-Hospitalisation |
| CMMG | Accueil, Imagerie Médicale, Pharmacie, Laboratoire, Prélèvement |

**Règles métier observées**
- Chaque **poste de travail** est rattaché à un site précis et probablement à un profil fonctionnel (ex : "Poste de Validation" suggère une étape de workflow de validation distincte de la saisie).
- Le changement de lieu est une action **fréquente et rapide** (un bouton toujours visible en haut de l'écran principal), ce qui suggère que le personnel peut être mobile entre postes dans une même journée.

**Lien avec DPI-RDC**
À vérifier : DPI-RDC gère-t-il déjà cette granularité "poste de travail" en plus du site/établissement ? Si non, c'est une amélioration à considérer pour la phase business modules (consultations/pharmacie/laboratoire), car le contexte du poste peut conditionner les menus visibles et les droits.

---

### 1.3 Recherche Patient (écran hub)

**Captures :** 5, 6, 7

**Objectif de l'écran**
Écran central d'accès aux patients et à un grand nombre de fonctions transverses (queue, RDV, conventions, cautions, rapports). C'est le véritable "tableau de bord opérationnel" de la réception, plus que le menu principal.

**Champs et données**
- **Recherche rapide** : Date de naissance, Début du nom, Première lettre du prénom → bouton **Recherche**
- Bouton **Nouveau Patient** (création directe depuis cet écran)
- Tableau de résultats : colonnes **Nom, Prénom, Sexe, ddn** — sélection par **double-clic**
- Bloc **Appels au Marquoir** : 4 boutons guichets **G1, G2, G3, G4** (avec icône +) + **Numéro Manuel**
- Bloc **Autres Infos** (boutons de navigation) : File d'Attente, Liste Hospitalisés, Horaire Consultations, Contacts, Infos Conventions, Tarif et Taux, Kilométrage, Lit Libre, Ajouter Quartier, RDV Encours, RDV Encours/Med, RDV Libre, Cautions reçues, RDV Libre/Med, Enregistrement Morgue
- Bloc **Registre / Rapport** : Mouvements Actes, Episodes Inscrits, Achèvement des Actes aux AMB/URG, Achèvement des Actes en Hospi

**Workflow — Appel au marquoir (gestion de file/guichet)**
1. Clic sur un bouton **G1-G4** → ouverture d'une fenêtre **Guichet** (capture 7)
2. Affichage : nom du guichet (ex. "Guichet 1"), **Numéro en Cours** (ex. 27)
3. Actions : **Sonner** (appeler ce numéro) / **Suivant** (passer au numéro suivant)

**Règles métier observées**
- Système de **ticket/numéro d'appel par guichet**, indépendant par poste (G1 à G4 = 4 guichets physiques probables), avec option de saisie manuelle du numéro.
- La recherche patient est volontairement **minimaliste** (3 champs) — optimisée pour la rapidité en contexte de forte affluence, pas pour une recherche complexe multi-critères. Ceci contraste avec la recherche floue via `pg_trgm` déjà implémentée dans DPI-RDC, qui est plus puissante — bon point pour DPI-RDC.
- Fonction **"Enregistrement Morgue"** intégrée directement dans le hub réception — à ne pas oublier dans le périmètre fonctionnel (gestion des décès).
- Fonction **"Lit Libre"** accessible depuis la réception — suggère que la réception a une visibilité temps réel sur la disponibilité des lits, utile pour orienter un patient à l'admission.
- Notion de **"Quartier"** (bouton "Ajouter Quartier") — probablement un référentiel géographique (quartier de résidence du patient) utilisé pour les statistiques ou l'adresse.

**Lien avec DPI-RDC**
Cet écran hub est plus riche fonctionnellement que ce qu'on retrouve typiquement dans un simple écran de recherche patient. Pour DPI-RDC, envisager un **tableau de bord réception** équivalent qui centralise : recherche patient + admission rapide + file d'attente + disponibilité lits + accès rapide aux conventions, plutôt que de disperser ces fonctions dans des menus séparés.

---

### 1.4 File d'Attente (vue globale multi-site)

**Capture :** 8

**Objectif de l'écran**
Vue consolidée de la file d'attente, filtrable par site.

**Champs et données**
- Filtre **Site** (dropdown) : Tous / CENTRE DE SANTE MONKOLE 2 / CENTRE HOSPITALIER MONKOLE / CENTRE MEDICAL MONKOLE GOMBE
- Tableau : **Site, Heure, Motif, NumS (numéro), Autres**

**Règles métier observées**
- Chaque entrée de file d'attente porte un **motif** — donc la file n'est pas juste "qui attend" mais "qui attend pour quoi" (ex : consultation, résultat labo, retrait pharmacie...).
- Cette fenêtre est **distincte** de l'écran "Recherche Patient" (fenêtre séparée, pas un onglet) — pattern récurrent dans ce logiciel : beaucoup de fonctions s'ouvrent en fenêtres indépendantes plutôt qu'en navigation intégrée. À noter comme différence probable avec l'approche Livewire de DPI-RDC (SPA-like), qui sera plus fluide.

---

### 1.5 Horaires de consultation

**Capture :** 9

**Objectif de l'écran**
Référentiel des horaires de consultation par service/spécialité et par médecin.

**Champs et données**
- Tableau : **Consultation** (nom du service, ex. "MEDECINE FAMILLE") × colonnes **Lundi à Dimanche**
- Chaque cellule jour contient : la plage horaire (ex. `08h00-17h00`) + les médecins assignés ce jour-là (ex. `Dr SHOMBA`, `Dr NGOMA`)

**Règles métier observées**
- Les horaires varient par jour (le samedi est raccourci : `08h00-13h00`, dimanche vide)
- **Plusieurs médecins peuvent être assignés au même créneau** le même jour (rotation ou couverture partagée)
- C'est un référentiel de planning **déclaratif** (grille hebdomadaire type), probablement utilisé pour informer la réception/RDV plutôt qu'un vrai planning nominatif jour par jour.

**Lien avec DPI-RDC**
Pertinent pour le futur module **consultations** : prévoir un référentiel horaires-service-médecin en grille hebdomadaire récurrente, distinct du système de RDV individuel (voir 1.9).

---

### 1.6 Conventions / Prise en charge Société (assurance)

**Captures :** 10, 11

**Objectif de l'écran**
Référentiel des sociétés/assurances/organismes ayant une convention de prise en charge avec l'hôpital.

**Champs et données par société**
- Nom de la société (ex. Advans Banque Congo S.A, Allianz Worldwide Care, Ambassade de France, Ambassade de Grande Bretagne, Ambassade d'Espagne, Amici dei Bambini...)
- Zone de **notes libres** (conditions particulières, instructions pour la réception)
- **Forfait** : `PARTIEL` / `ACTE` / `GLOBAL`
- **Mode d'accès** : `Liste` / `Carte / Document`
- **Catégorie** : `A`, `C`, `D` (typologie tarifaire probable)

**Exemples de règles métier capturées textuellement**
- Allianz Worldwide Care : le patient paie 20 % du montant total en ambulatoire ; en hospitalisation la convention couvre tout ; à la réception, il faut photocopier la carte de service.
- Amici dei Bambini : convention marquée **non active** dans les notes (incohérence potentielle entre statut affiché et note manuelle — point de vigilance UX).

**Règles métier observées**
- Le système de conventions combine **3 dimensions indépendantes** : type de forfait (comment ça se facture), mode d'accès (comment on vérifie l'éligibilité du patient), et catégorie (grille tarifaire). C'est plus riche que "taux de couverture unique".
- Le **mode d'accès "Liste"** suggère que certaines sociétés fonctionnent sur liste nominative pré-enregistrée des ayants droit (pas de carte physique), alors que d'autres nécessitent un document/carte physique à présenter et copier.
- Fonctionnalité transverse détectée (clic droit sur le tableau) : **export Excel / Word / XML**, impression, sélection de colonnes, couleur de fond — ce pattern d'export/impression semble disponible sur **toutes les grilles** de l'application (revu aussi en 1.7 Kilométrage).

**Lien avec DPI-RDC**
DPI-RDC a déjà un système d'assurance à 3 niveaux (taux de couverture, plafond annuel, liste des actes couverts). Le système Monkole introduit une notion complémentaire intéressante : **le "Forfait"** (PARTIEL/ACTE/GLOBAL) comme mode de calcul global, distinct du taux de couverture par acte. À évaluer : DPI-RDC devrait-il aussi distinguer explicitly un "mode de forfait" (tout est couvert / rien n'est couvert sauf ce qui est listé / coefficient uniforme) en plus du taux/plafond/liste d'actes ? Cela pourrait simplifier la saisie pour les conventions simples (ex: GLOBAL = tout pris en charge) sans devoir lister chaque acte.

---

### 1.7 Kilométrage (ambulance)

**Capture :** 12

**Objectif de l'écran**
Référentiel des distances entre l'hôpital et des destinations fréquentes, pour la facturation des courses d'ambulance.

**Champs et données**
- Tableau : **Origine** (toujours MONKOLE), **Destination**, **Kilométrage**
- Exemples : Aéroport de N'Djili (50 km), Aéroport de Ndolo (38 km), Cliniques Bondeko (30 km), Hôpital Biamba Marie Mutombo (32 km), Cliniques Universitaires (14 km), Cliniques Ngaliema (48 km)

**Règles métier observées**
- La facturation ambulance semble basée sur un **barème kilométrique pré-enregistré** par destination fréquente, plutôt qu'une saisie manuelle de distance à chaque course — plus rapide et cohérent pour la facturation.

**Lien avec DPI-RDC**
Si DPI-RDC prévoit un module ambulance/transport (non mentionné dans les modules actuels connus), ce référentiel destination→km est un modèle simple et efficace à reprendre.

---

### 1.8 RDV en cours par Médecin

**Capture :** 13

**Objectif de l'écran**
Vue des rendez-vous programmés, filtrable par médecin, site et période.

**Champs et données**
- **Médecin** (champ texte + bouton **Choisir Médecin**, suggère un sélecteur dédié plutôt qu'une saisie libre)
- **Sélectionner Site** (dropdown)
- **Date de début / Date de fin** (date pickers) + dropdown **Période prédéfinie** (raccourcis probables : aujourd'hui, semaine, mois...)
- Bouton **Appliquer**
- Tableau : **Date, PATIENT, Prestataire, Type de RDV, Observation, Statut**

**Règles métier observées**
- Un RDV a un **Type** et un **Statut** distincts (donc un cycle de vie : probablement confirmé/en attente/annulé/honoré) — à creuser avec plus de captures si disponibles.
- Distinction entre **"Prestataire"** et **"Médecin"** dans les filtres/colonnes suggère que le RDV n'est pas forcément médical (peut concerner un autre type de prestataire).

**Lien avec DPI-RDC**
Ce module RDV n'apparaît pas encore explicitement dans le périmètre confirmé de DPI-RDC (phase actuelle = consultations/pharmacie/laboratoire). À clarifier si la prise de RDV planifiée fait partie du périmètre cible ou si DPI-RDC reste centré sur le flux ambulatoire/hospitalisation sans agenda.

---

### 1.9 Gestion des utilisateurs (RBAC)

**Captures :** 14, 15

**Objectif de l'écran**
Recherche et gestion des comptes utilisateurs de l'application.

**Champs et données**
- Filtre rapide par type : **Tous / Médecin / Infirmier**
- Tableau : **Nom et prénom, Profil, Date d'expiration, Actif (case à cocher), Type d'utilisateur**

**Profils observés (valeur de la colonne "Profil")**
`INFORMATICIEN`, `INFIRMIER`, `LABORATOIRE CHEF`, `TECHNICIEN IMAGERIE`, `PHARMACIEN CHEF`, `INFIRMIER RDV`, `RECEPTION`

**Types d'utilisateur observés**
`Administratif`, `Infirmier(e)`

**Règles métier observées**
- Chaque compte a une **date d'expiration** individuelle (ex : `31/12/2050` pour les comptes "permanents", `28/02/2027` pour d'autres) — mécanisme de désactivation automatique programmée, utile pour le personnel temporaire/stagiaire.
- Le **Profil** (rôle métier fin, ex: "LABORATOIRE CHEF", "PHARMACIEN CHEF") est distinct du **Type d'utilisateur** (catégorie large : Administratif/Infirmier). C'est un modèle RBAC à **deux niveaux** : catégorie générale + rôle métier précis.
- Existence d'un rôle **"INFIRMIER RDV"** spécifiquement dédié à la gestion des rendez-vous — rôle transverse distinct du soin.

**Lien avec DPI-RDC**
DPI-RDC utilise déjà Spatie RBAC avec des rôles seedés. Comparer la granularité : le modèle Monkole a des rôles très précis par fonction (ex. "LABORATOIRE CHEF" vs simple "laborantin"), avec une notion de **hiérarchie/chef** intégrée au nom du rôle plutôt que comme un attribut séparé. À évaluer si DPI-RDC doit avoir des rôles "chef de service" distincts par département (labo, pharmacie...) ou gérer ça via permissions plutôt que rôles nommés.

---

### 1.10 Cautions (dépôts)

**Capture :** 16

**Objectif de l'écran**
Suivi des cautions (dépôts de garantie) reçues, filtrable par période et par site.

**Champs et données**
- **Date de début / Date de fin** + **Période prédéfinie**
- **Sélectionner Site** (dropdown) : `CHME`, `CMMG`, `CSMKL2`
- Bouton **Appliquer**
- Tableau : **Date, Noms, Adresse, Tel, Montant Fc, Montant Dol, Valider par**

**Règles métier observées**
- **Confirmation du double affichage monétaire** : `Montant Fc` (Francs congolais) et `Montant Dol` (Dollars) comme deux colonnes séparées — cohérent avec la logique dual-currency (CDF/USD) déjà implémentée dans DPI-RDC.
- Colonne **"Valider par"** confirme qu'il existe une étape de **validation/approbation** de la caution par un utilisateur nommé — logique de contrôle à 4 yeux ou de traçabilité.
- Rappel de la règle métier confirmée précédemment : le patient hospitalisé paie une **caution à l'entrée**, ce qui est cohérent avec cet écran de suivi des cautions.

**Lien avec DPI-RDC**
Confirme et valide le choix déjà fait dans DPI-RDC (dual-currency CDF/USD, caution à l'admission). Point d'attention : prévoir la colonne/notion **"Validé par"** dans le modèle de données de caution si pas déjà présente, pour la traçabilité.

---

### 1.11 Fiche Morgue (enregistrement des décès)

**Capture :** `17_fiche_morgue.png`

**Objectif de l'écran**
Gestion des entrées à la morgue, accessible comme un mini-module indépendant (fenêtre séparée, version copyright 2021 — plus ancienne que le reste de l'app en 2018/10.0.3.0, signe d'un module ajouté ou maintenu séparément).

**Éléments visibles**
- Onglet **Gestion des entrées** avec 2 actions en icônes : **Recherche corps**, **Visite**
- Même mécanisme de sélection de **Lieu de travail** que le reste de l'application (modale identique avec Modifier/Valider/Annuler)

**Règles métier observées**
- La morgue a sa propre notion de **"visite"** (probablement les proches venant identifier/voir le corps), distincte de la recherche du corps enregistré.
- Ce module semble être une **application quasi-autonome** greffée au système principal (fenêtre, versioning et copyright différents) — pattern à noter : le logiciel Monkole n'est pas un monolithe unique mais un assemblage de sous-applications partageant l'authentification/le contexte de lieu.

**Lien avec DPI-RDC**
Le flux "Enregistrement Morgue" avait déjà été repéré comme bouton dans le hub Recherche Patient (voir 1.3). Cette capture confirme qu'il mérite un mini-module dédié : recherche de corps + gestion des visites de proches, à prévoir même sommairement dans le périmètre DPI-RDC (état civil des décès).

---

### 1.12 Aperçu Lit (vue globale des lits)

**Capture :** `18_apercu_lit.png`

**Objectif de l'écran**
Vue d'ensemble de **tous les lits** de l'hôpital, toutes unités confondues, avec leur statut.

**Champs et données**
- Tableau : **Unite** (service, ex. Médecine interne, HOSPI AILE DROITE, HÔPITAL DU JOUR), **Chambre** (numéro ou code, ex. `111`, `CH. 4`, `CHAMBR 5`, `HDJ CMMG`), **Nro** (identifiant du lit, ex. `Lit3`), **Statut**
- Filtres par colonne (icône entonnoir) sur chaque en-tête

**Règles métier observées**
- La **numérotation des chambres n'est pas standardisée** (`111`, `CH. 4`, `CHAMBR 5` coexistent) — probablement une saisie historique peu homogène, à ne **pas reproduire tel quel** dans DPI-RDC : prévoir un format de numérotation de chambre/lit cohérent dès la conception.
- Cet écran est complémentaire à la vue "Lit occupé" par service (voir 1.16) : celui-ci donne une vue **transversale tous services**, l'autre une vue **détaillée par unité** avec actions.

**Lien avec DPI-RDC**
Confirme le besoin d'un référentiel **Unité → Chambre → Lit** à 3 niveaux avec statut (libre/occupé), déjà pressenti via le bouton "Lit Libre" du hub réception (1.3).

---

### 1.13 Tarifs (grille tarifaire par spécialité)

**Capture :** `19_tarifs_par_specialite.png`

**Objectif de l'écran**
Référentiel des tarifs des actes, organisé par spécialité et sous-spécialité.

**Champs et données**
- Bouton **Taux du Jour** (taux de change du jour, cohérent avec le dual-currency CDF/USD)
- Filtre **Spécialité** (liste déroulante très fournie — voir ci-dessous) et **Sous spécialité** (dépendante de la spécialité choisie)
- Tableau : **Acte**, puis colonnes **C, D, CD, DD** (probablement des catégories/modes de tarification liés à la "Catégorie" de patient A/B/C/D déjà repérée sur les conventions et les lits — **à confirmer avec une capture where ces colonnes sont remplies**, le sens exact de C/D/CD/DD reste à vérifier)

**Liste des spécialités observées**
Anesthésie, Cardiologie, Chirurgie, Covid Hospi, Covid Imagerie Médicale, Covid Isolement, Covid Laboratoire, Covid Médecine Interne, Covid Nurs, Covid Nurs EPI, Covid Pharmacie (consommables / médicaments), Covid Réanimation, Covid Service de Base, Covid Service Généraux, Covid URGENCE/ISOLEMENT, Dentisterie, Dépense Externe, Dermatologie, Endocrinologie, Endoscopie, Gastroentérologie, Gynéco, Hématologie, Hospitalisation, *(liste tronquée dans la capture, probablement plus longue)*

**Règles métier observées**
- Le référentiel tarifaire est **extrêmement granulaire par spécialité**, avec tout un bloc de spécialités dupliquées en version "Covid" — reflète une gestion de crise sanitaire séparée du reste (tarifs, actes et probablement facturation Covid isolés du circuit normal).
- Présence d'une spécialité **"Dépense Externe"** — suggère la possibilité de facturer des prestations sous-traitées à l'extérieur (ex : examen fait dans un autre labo) directement dans le dossier du patient.

**Lien avec DPI-RDC**
Le référentiel Acte × Spécialité × Catégorie tarifaire est un pilier pour le futur module **consultations/laboratoire/pharmacie**. Recommandation : prévoir dès la conception une table `actes` rattachée à une `specialite` et une matrice de prix par catégorie de patient/convention, plutôt qu'un prix unique par acte — sous peine de devoir tout refondre plus tard.

---

### 1.14 Achèvement des Actes — Ambulatoire et Urgences

**Capture :** `20_achevement_acte_ambulatoire.png`

**Objectif de l'écran**
Écran de **validation/réalisation** des actes prescrits pour les patients ambulatoires et des urgences — le prestataire y "achève" (coche comme fait) un acte qui a été prescrit.

**Champs et données**
- En-tête patient : nom, sexe, âge (`MARIAM YAKUTI LEA (F) 26 ans`), **Session ouverte par : [utilisateur]** (traçabilité de la session active)
- Convention affichée en évidence (`Conventionné(e): MSF BELGIQUE BAP COORDO`) + bouton **Details Facture**
- Filtres période (Date début/fin, Période prédéfinie, Appliquer, Actualiser) + **Sélectionner Site**
- Table gauche : **Service, Patient** (liste des patients ambulatoires du site/période)
- Table droite : **Acte, qte, Note demande, Date, DateV (date de validation), Prescrit par, Valider par**, bouton **Achever** par ligne

**Workflow observé**
1. Un acte est **prescrit** par un médecin (colonne "Prescrit par")
2. Il apparaît dans la liste des actes à réaliser, avec sa **quantité** et une **note de demande** éventuelle
3. Le prestataire qui réalise l'acte clique **Achever** → l'acte est marqué comme fait, avec la **Date de validation (DateV)** et le nom du **Validateur** enregistrés

**Règles métier observées**
- Cycle de vie clair d'un acte : **Prescription → Réalisation/Achèvement → Facturation**. Le "Prescrit par" et le "Valider par" peuvent être la **même personne** (cas observé : Dr MBALA ANGE des deux côtés) ou des personnes différentes selon le contexte.
- La convention du patient est affichée **en permanence** pendant la saisie des actes — logique, car elle conditionne la facturation immédiatement visible via "Details Facture".

**Lien avec DPI-RDC**
Ce pattern *Prescription → Achèvement → Facturation* est central et directement applicable aux 3 modules à venir de DPI-RDC (consultations, pharmacie, laboratoire) : chaque acte/médicament/examen prescrit devrait suivre ce même cycle avec traçabilité du prescripteur et du réalisateur.

---

### 1.15 Achèvement des Actes — Hospitalisation (par unité)

**Capture :** `21_achevement_acte_hospi.png`

**Objectif de l'écran**
Même logique que 1.14 (achèvement des actes), mais pour les patients **hospitalisés**, filtrés par **Unité** (service) plutôt que recherche libre de patient.

**Champs et données**
- Mêmes filtres période/site que 1.14, + dropdown **Unité** avec liste : Chirurgie, Gynéco-Obstétrique, Hôpital de jour, Médecine interne, Monkole 3, Néonatologie, Pédiatrie, URGENCES, Soins intensifs péd, REANIMATION, ENDOSCOPIE, COVID BAT A, COVID BAT B, HOSPI AILE DROITE
- Mêmes deux tables (Service/Patient à gauche, Acte/qte/Note/Dates/Validation à droite)
- Bouton **Details Facture** disponible également ici

**Règles métier observées**
- Confirme la liste des **unités d'hospitalisation** de l'établissement (utile comme référentiel `services` / `unites` pour DPI-RDC)
- Le fait que l'écran "Ambulatoire" (1.14) et l'écran "Hospi" (1.15) soient **deux fenêtres distinctes mais structurellement identiques** suggère que dans DPI-RDC, un seul écran "Achèvement des actes" avec un filtre Ambulatoire/Hospitalisation pourrait suffire — simplification possible par rapport au logiciel de référence.

---

### 1.16 Gestion de service — Lits occupés (vue par unité)

**Capture :** `24_service_chirurgie_gestion_lits.png`

**Objectif de l'écran**
Vue opérationnelle complète d'une unité d'hospitalisation (ici Chirurgie) : occupation des lits + actions rapides sur le patient hospitalisé.

**Champs et données (tableau)**
**ch-lit** (chambre-lit), **statut** (Occupé/Libre), **DS** (valeur numérique par patient — probablement durée de séjour en jours, à confirmer), **Nom**, **sexe**, **age**, **cat** (catégorie patient : A/B/C observées), **Convention**, **Sortie prévue** (date/heure), **Commentaire**

**Exemple de données observées**
| ch-lit | statut | Nom | age | cat | Convention | Sortie prévue |
|---|---|---|---|---|---|---|
| 101-Lit 1 | Occupé | LUZOLO KUTSHI JEAN PIERRE | 56 | C | SOCIR | 29/07/2026 15:00 |
| 102-Lit 2 | Occupé | MUHASA MIKUNDI SALVIA | 3 | A | KAYLA | 01/08/2026 12:00 |
| 104-Lit 2 | Occupé | ATOLAKI ISOSEHO ALPHONSE | 64 | C | MSF FORFAIT TERRAIN | *(vide)* |

**Actions disponibles (barre d'outils)**
- **D.E.M** (probablement "Dossier Électronique Médical" — accès au dossier du patient)
- **Détails Facture**
- **Imprimer bon**
- **Changer de Lit**
- **Messages**
- **Transférer vers service**
- **Actes et Produits**
- **Modifier sortie**
- **Sortie définitive**
- Boutons bas de fenêtre : **Rendez-vous**, **Liste Hospitalisés**, **Fermer**

**Règles métier observées**
- La **"Sortie prévue"** est une date/heure **estimée à l'avance**, distincte de la **"Sortie définitive"** (action de checkout réel) — permet une planification des lits (savoir quand un lit va se libérer).
- La colonne **DS** varie fortement patient par patient (1, 2, 7, 17...) — si c'est bien la durée de séjour en jours, c'est une donnée précieuse pour les statistiques d'occupation et pourrait aussi servir de déclencheur d'alerte (séjour anormalement long).
- La **Convention** est visible directement dans la liste des lits — permet un coup d'œil rapide sur qui est pris en charge par qui, utile pour la facturation.
- Le bouton **"Transférer vers service"** confirme qu'un patient hospitalisé peut changer d'unité médicale en cours de séjour (ex : Chirurgie → Réanimation), ce qui doit être tracé dans l'historique du séjour.

**Lien avec DPI-RDC**
Écran clé à prévoir pour le futur module **Hospitalisation** de DPI-RDC : une vue "lits de mon service" avec statut, patient, convention, sortie prévue, et actions rapides (transfert, sortie, facturation, changement de lit). C'est probablement l'écran le plus utilisé au quotidien par le personnel infirmier/médical hospitalier.

---

### 1.17 Détails de facture (impression)

**Capture :** `25_details_facture_impression.png`

**Objectif de l'écran**
Aperçu/impression du détail d'une facture liée à un patient et sa convention.

**Champs et données**
- En-tête : logo + nom de l'hôpital, **N° de facture** (ex. `N°281474977401054`), **Nom du patient**, **Convention** (ex. `SOCIR`)
- Tableau : **Date et Heure, Qté, Acte Facturé, Facturé, Payé, Prescrit Par**

**Exemple d'actes facturés (laboratoire)**
Hémogramme ; Prélèvement veineux (Acte + Kit seringue+aiguille) ; TP (VN:60-110% ; INR VN:0,88-1,12) ; TCA (Temps de Céphaline Activée : 24,6-39,9 sec, Ratio <1,2) ; Compatibilité sanguine (AB0, Rh) — tous à 0 Facturé / 0 Payé (entièrement pris en charge par la convention SOCIR).

**Règles métier observées**
- Le **libellé de l'acte de laboratoire inclut directement les valeurs de référence normales** (ex. "TP (VN:60-110%)") — c'est une pratique intéressante pour la lisibilité clinique de la facture/résultat, mais cela mélange **nom de l'acte** et **information clinique** dans un seul champ texte. Pour DPI-RDC, mieux vaut séparer proprement le nom de l'acte (catalogue) des valeurs de référence (métadonnées structurées de l'acte), pour rester exploitable en base de données.
- Confirme la logique déjà notée : quand la convention couvre à 100 %, le montant **Facturé/Payé** affiché au patient est à 0 — la charge réelle est supportée ailleurs (compte de la convention), pas visible sur ce document patient.
- Le **numéro de facture est long et non séquentiel visuellement** (`281474977401054`) — possible timestamp ou identifiant technique plutôt qu'un numéro de facture séquentiel classique.

---

### 1.18 Statistiques des épisodes enregistrés & Rapport

**Captures :** `27_modal_statistiques_episodes.png`, `28_rapport_episodes_inscrits.png`

**Objectif de l'écran**
Point d'entrée pour consulter les statistiques d'épisodes de soins, avec un rapport détaillé exportable.

**Workflow**
1. Depuis le hub réception, clic sur **Rapports** → modale **"STATISTIQUES DES EPISODES ENREGISTRES"** avec 3 choix : **Ambulatoire / Hospitalisation / Urgences**
2. Sélection d'un type → ouverture de **Rapports Episodes Inscrits** : filtres Site, Date de début/fin, Période prédéfinie, Appliquer
3. Tableau résultat : **Date, Noms, N° Ref, Sexe, Convention, Motif, Unité Médicale, Cat, Enregistré par**

**Règles métier observées**
- Chaque **épisode de soin** (ambulatoire, hospitalisation ou urgence) a un **numéro de référence (N° Ref)** propre, un **motif**, une **unité médicale**, une **catégorie**, et trace **qui l'a enregistré** — c'est le référentiel/journal central de tous les passages patients, tous types confondus.
- La distinction Ambulatoire/Hospitalisation/Urgences comme **3 types d'épisodes** structurants recoupe ce qui avait déjà été vu dans le hub réception (1.3 : "Achèvement des Actes aux AMB/URG" vs "en Hospi") — confirme que ce triptyque est une notion **fondamentale** du modèle de données de ce logiciel (probablement une table `episodes` avec un type énuméré).

**Lien avec DPI-RDC**
Recommandation forte : structurer un concept d'**« épisode de soin »** central dans DPI-RDC (type Ambulatoire/Hospitalisation/Urgences), auquel se rattachent tous les actes, prescriptions et facturations — plutôt que de gérer chaque contexte de façon indépendante. C'est le pivot autour duquel semble tourner tout GPS-Monkole.

---

## Constats transversaux — Module Accueil/Réception (Lots 1 et 2)

- **Pattern UI récurrent :** quasiment tous les tableaux/grilles supportent nativement export Excel/Word/XML + impression + personnalisation des colonnes via clic droit.
- **Fenêtres indépendantes plutôt qu'intégrées :** beaucoup de fonctions s'ouvrent en fenêtres Windows séparées plutôt qu'en navigation unique — DPI-RDC (Livewire) peut offrir une expérience plus fluide.
- **Filtrage par site omniprésent**, confirmant l'importance du multi-établissement.
- **Granularité "lieu de travail"** (site + poste précis) plus fine que juste "établissement".
- **Concept pivot d'« épisode de soin »** typé Ambulatoire/Hospitalisation/Urgences, avec numéro de référence, motif, convention et catégorie — semble être la colonne vertébrale du modèle de données de GPS-Monkole (voir 1.18).
- **Cycle Prescription → Achèvement → Facturation** appliqué systématiquement aux actes, avec traçabilité prescripteur/validateur (voir 1.14, 1.15) — pattern directement réutilisable pour consultations/pharmacie/laboratoire dans DPI-RDC.
- **Traçabilité de session utilisateur** affichée en permanence en haut des écrans opérationnels (`Session ouverte par : ...`) — bon réflexe d'audit à reprendre.
- ⚠️ **Note de classification :** certains écrans de ce lot (Achèvement des actes, Gestion de service/lits, Détails facture) débordent conceptuellement du seul périmètre "Accueil/Réception" et recoupent les futurs modules **Hospitalisation** et **Facturation**. Ils sont documentés ici car envoyés dans ce lot ; ils seront recroisés/complétés si d'autres captures dédiées à ces modules arrivent plus tard.

---

## Module 2 : Facturation

### 2.1 Accès au module

**Captures :** `01_menu_facturation.png`, `06_menu_facturation_v2.png`

Depuis le menu principal, le bloc **Facturation** se déplie en 3 raccourcis en icônes :
- **Générale** (icône pièces de monnaie) → facturation classique d'un patient
- **Conventionné** (icône carte type Visa) → facturation liée à une convention/société
- **Rapports** (icône camembert) → statistiques financières

---

### 2.2 Facturation — écran hub patient

**Capture :** `02_facturation_hub_patient.png`

**Objectif de l'écran**
Écran central de facturation, ouvert dans le contexte d'une convention (ici `Conventionné(e): SOCIR -`). Regroupe la recherche patient et un très grand nombre d'actions de facturation en un seul endroit.

**Champs et données**
- En-tête : convention active + **Session ouverte par : [utilisateur]**
- Actions principales : **Validation** ✓ et **Prise en Charge** ✎ (boutons mis en évidence en haut à droite)
- **Recherche rapide** (identique aux autres écrans : date de naissance, début du nom, première lettre du prénom)
- Bloc **Les Factures** : Facture Synthèse, Facture Détails, Observation Facture, Imprimer Actes, Récapitulatif des Factures, Pro Forma, Correction Facture, Détails Actes Facturés, Imprimer Actes Conv, Remboursement, Ajouter Caution, Billetage
- Bloc **Données Générales** : Mon Etat de Caisse, Liste Hospitalisés, Consommation Amb, Fusion Facture, Tarif et Taux, Consommation Hospi, Trace Facture, Paiements, Consommation Urg, Traitement Facture
- Deux tableaux de résultats en bas : patients (Nom/Prénom/Sexe/ddn) et épisodes (Date/Motif/Unité Médicale/Site)

**Règles métier observées**
- La facturation distingue clairement **Facture Synthèse** vs **Facture Détails** — donc au moins deux niveaux de granularité d'affichage/impression d'une même facture.
- **Correction Facture** et **Retouche Facture** (revu en 2.6) confirment que le processus de facturation admet des **corrections a posteriori**, avec probablement une trace d'audit (voir "Trace Facture").
- **"Fusion Facture"** suggère la possibilité de **regrouper plusieurs factures** en une seule (utile si un patient a plusieurs épisodes à facturer ensemble).
- **"Ajouter Caution"** et **"Billetage"** sont accessibles directement depuis cet écran de facturation — pas seulement depuis la réception (1.10 et 2.3) : cohérent, la caution/l'encaissement font partie du même flux financier.
- Distinction **Consommation Amb / Hospi / Urg** — encore une fois le triptyque Ambulatoire/Hospitalisation/Urgences (voir 1.18) appliqué ici à la consommation/facturation.
- **"Mon Etat de Caisse"** (au singulier, "Mon") suggère un état de caisse **personnel par utilisateur/session**, distinct d'un état de caisse global (voir 2.7 "Etat de Caisse Général").

**Lien avec DPI-RDC**
Ce hub confirme la nécessité, pour DPI-RDC, d'un écran de facturation centralisé par patient/épisode avec : génération de facture (synthèse + détail), correction/retouche tracée, gestion de caution, et vue de consommation par type d'épisode. Le concept de **fusion de factures** est une fonctionnalité avancée à considérer si plusieurs actes/épisodes doivent être regroupés en un seul paiement.

---

### 2.3 Billetage (comptage de caisse)

**Capture :** `03_billetage_caisse.png`

**Objectif de l'écran**
Comptage physique de caisse par dénomination de billets/pièces — outil de clôture/vérification de caisse.

**Champs et données**
- Bouton **Nouveau +**
- Tableau **Billetage** : colonne **Monnaie**, puis une colonne par coupure : `0.5, 1, 5, 10, 20, 50, 100, 200, 500, 1000, 5000, 10000, 20000`, puis **Total**
- Bloc **Obs** (zone d'observation libre)
- Bloc **Recherche** : "Rech par date_Heure" (dropdown, pour retrouver un billetage passé)
- Actions : **Imprimer**, **Fermer**

**Règles métier observées**
- Les coupures listées correspondent aux **billets/pièces du Franc congolais** (CDF) — confirme que la caisse physique est comptée en monnaie locale, même si la facturation admet plusieurs devises (voir 2.6).
- Le billetage est horodaté et **retrouvable par date/heure** — probablement un billetage réalisé à chaque fin de session/shift par l'utilisateur en caisse, avec traçabilité (`Session ouverte par`).

**Lien avec DPI-RDC**
Fonctionnalité de contrôle de caisse à considérer si DPI-RDC gère l'encaissement physique en espèces (particulièrement pertinent en RDC où le cash reste très utilisé) : un écran de comptage par coupure, horodaté, lié à la session utilisateur, exportable/imprimable.

---

### 2.4 Suivi des séjours facturés (patients hospitalisés / paiements)

**Capture :** `04_liste_facturation_sejours.png`

**Objectif de l'écran**
Rapport listant les patients (probablement hospitalisés) avec leur statut de paiement, filtrable par site et période. *(Titre de fenêtre non visible sur la capture — nom de fonction déduit du contenu, à confirmer.)*

**Champs et données**
- Filtres : **Sélectionner Site**, Date de début/fin, Période prédéfinie, Appliquer
- Tableau : **Patient, Sexe, Cat, Adresse, Phone, Type, Prix Facturé, Montant payé, Acompte, Type Séjour**

**Règles métier observées**
- Distinction entre **Prix Facturé**, **Montant payé** et **Acompte** — trois montants séparés, ce qui confirme un modèle de **paiement partiel/échelonné** possible (le patient peut avoir versé un acompte sans avoir soldé la facture).
- **Type Séjour** comme colonne dédiée — encore une classification du séjour (probablement lié à Ambulatoire/Hospitalisation/Urgences ou à une sous-catégorie comme "Hôpital de jour").

**Lien avec DPI-RDC**
Confirme le besoin de distinguer, dans le modèle de données de facturation : montant facturé total, montant payé cumulé, et acompte(s) — plutôt qu'un simple statut binaire payé/non payé.

---

### 2.5 Liste Factures Proforma

**Capture :** `05_liste_factures_proforma.png`

**Objectif de l'écran**
Gestion des **factures proforma** (devis/estimatifs avant facturation définitive), accessible depuis le bouton "Pro Forma" du hub facturation (2.2).

**Champs et données**
- Actions : **Nouveau +**, **Modifier ✎**, **Imprimer 🖶**, **Supprimer 🗑**
- Tableau : **Date Création, Numéro Facture, Motif, Utilisateur**

**Règles métier observées**
- Une facture proforma a son propre **numéro** et **motif** — probablement utilisée pour donner une estimation de coût au patient/à la convention avant réalisation des actes (utile pour les cas nécessitant une pré-autorisation, ex. chirurgie programmée).

**Lien avec DPI-RDC**
Fonctionnalité utile à prévoir si DPI-RDC doit gérer des devis/pré-autorisations avant traitement, en particulier pour les conventions qui exigent une validation préalable des coûts.

---

### 2.6 Gestion Facturation Conventions (Facturation Société)

**Capture :** `07_gestion_facturation_conventions.png`

**Objectif de l'écran**
Écran le plus riche du module Facturation — gestion complète de la facturation liée à une société/convention (assurance, entreprise, ambassade...), avec de multiples modes de génération de factures.

**Champs et données**
- **Société** (sélecteur), Date de début/fin, Période prédéfinie, **Sélectionner Site**
- Bloc **Facture Globale/Mutuelle** (gauche) : Facture Globale, Facture Mutuelle, puis déclinaisons **Collective** et **Individuelle**, chacune en **3 devises : FC (Franc congolais), $ (Dollar), € (Euro)**
- Bloc **Facture Patient** (centre) : Collective/en FC, Collective/en $, Collective/en €, puis 3 variantes **Forfaitaire/en $** : (Famille), (Bénéficiaire), (Fam_Bén)
- Bloc actions société (droite) : Fiche Société, Produit Société, Ayant Droits du Mois, Payement Facture, Dettes à recouvrer
- Bloc validation (haut droite) : **Fréquentation Achevée**, **Fréquentation validée**, **Liste des Hospitalisés**, **Retouche Facture** (avec indicateur visuel — pastille orange, probablement un compteur d'éléments en attente), **Résumé Conventions**, **Consommation**
- Note d'avertissement affichée : *« Les factures sont imprimées selon le site choisi ; veuillez choisir le bon site avant d'imprimer la facture. »*

**⚠️ Découverte importante — 3 devises et non 2**
Cette capture révèle que la facturation société gère **3 devises : Franc congolais (FC), Dollar ($), et Euro (€)** — pas seulement CDF/USD comme précédemment supposé dans DPI-RDC. Les ambassades européennes (Espagne, Grande-Bretagne, France — vues en 1.6) et organismes internationaux expliquent probablement ce besoin d'Euro. **Point à corriger/valider dans DPI-RDC** : le modèle dual-currency actuel (CDF/USD) devrait être reconsidéré pour supporter une **liste de devises configurable** plutôt que 2 devises fixes, si des conventions en Euro sont dans le périmètre réel.

**Règles métier observées**
- Le système distingue **Facture Globale** (toute la société en un document) de **Facture Mutuelle** (probablement spécifique aux mutuelles de santé), puis à l'intérieur de chaque type : **Collective** (plusieurs bénéficiaires groupés) vs **Individuelle** (un bénéficiaire par facture).
- Les factures **Forfaitaire** introduisent une notion de **regroupement familial** : par Famille, par Bénéficiaire seul, ou combinaison Famille+Bénéficiaire — donc le système modélise des liens de type **assuré principal / ayants droit**.
- **"Ayant Droits du Mois"** confirme que la liste des bénéficiaires éligibles à la prise en charge est **mise à jour mensuellement** — logique métier importante : l'éligibilité n'est pas figée mais réévaluée chaque mois (ex : un employé peut quitter l'entreprise, un ayant droit peut être retiré).
- **"Produit Société"** introduit une notion de **catalogue de produits/services négociés par société**, en plus des règles de "Forfait" déjà vues (1.6) — une société peut donc avoir des tarifs ou produits spécifiques différents du tarif standard.
- Workflow de validation en 2 étapes avant facturation : **Fréquentation Achevée → Fréquentation validée** — cohérent avec le pattern Prescription→Achèvement→Validation déjà repéré (1.14/1.15), appliqué ici au niveau de l'épisode complet avant facturation à la société.
- **"Dettes à recouvrer"** confirme le suivi des impayés par société/convention.

**Lien avec DPI-RDC**
Écran majeur à prévoir dans la refonte du module facturation de DPI-RDC. Recommandations concrètes :
1. Modéliser une relation **assuré principal ↔ ayants droit** (pas seulement patient ↔ convention).
2. Prévoir une **éligibilité datée/mensuelle** des bénéficiaires plutôt qu'un simple flag actif/inactif.
3. Supporter une **liste de devises extensible** (pas juste CDF/USD).
4. Distinguer **facturation collective vs individuelle** comme deux modes de sortie du même jeu de données, pas deux processus séparés.
5. Prévoir un **catalogue de produits/tarifs négociés par société**, en plus des règles générales de convention.

---

### 2.7 Statistiques Financières

**Capture :** `08_modal_statistiques_financieres.png`

**Objectif de l'écran**
Point d'entrée vers les rapports financiers, accessible via le bouton "Rapports" du menu Facturation (2.1).

**Options disponibles**
Registre Validation · Etat de Caisse Général · Liste des Transactions · Rapport Comptabilité · Dettes non autorisées · Dettes autorisées · Actes Honoraires · Actes Honoraires / Médecin · Actes Achevés · Actes Achevés / Médecin · Conventionné reçu

**Règles métier observées**
- Distinction **Dettes non autorisées** vs **Dettes autorisées** — suggère qu'une dette (patient qui ne paie pas immédiatement) peut être **explicitement autorisée** par quelqu'un (ex : un responsable), et que les dettes non autorisées sont probablement anormales/à surveiller.
- **Actes Honoraires** (à distinguer des actes "normaux") — suggère une distinction comptable entre le coût de l'acte technique et les **honoraires du médecin**, ce qui a du sens pour la rémunération des praticiens (surtout si certains sont indépendants/vacataires). Décliné aussi **par médecin**.
- **"Actes Achevés"** et **"Actes Achevés / Médecin"** relient à nouveau le rapport financier au cycle d'achèvement des actes (1.14/1.15) — les statistiques financières semblent construites sur les mêmes actes que les écrans opérationnels, ce qui est cohérent (un seul flux de données, plusieurs vues).

**Lien avec DPI-RDC**
Si DPI-RDC prévoit une rémunération différenciée des médecins (honoraires distincts du coût de l'acte), il faut prévoir dès le modèle de données un champ ou une relation "honoraires" séparé du "coût de l'acte" — actuellement pas confirmé si DPI-RDC gère cette distinction.

---

## Constats transversaux — Module Facturation

- **3 devises confirmées (FC/$/€)**, pas seulement CDF/USD — à corriger dans les hypothèses de DPI-RDC si le périmètre inclut des conventions internationales.
- **Workflow de validation en plusieurs étapes** avant facturation finale à une société (Fréquentation Achevée → validée), cohérent avec le pattern déjà observé pour les actes individuels.
- **Distinction Facturé / Payé / Acompte** — modèle de paiement partiel à prévoir, pas un simple statut payé/non payé.
- **Relation assuré principal / ayants droit** avec éligibilité mensuelle — notion à ajouter au modèle de conventions de DPI-RDC si pas déjà présente.
- **Billetage physique par coupure**, horodaté et lié à la session utilisateur — pertinent vu l'usage important du cash en RDC.
- Le module Facturation **réutilise systématiquement** les concepts déjà vus dans Accueil/Réception (épisode, convention, catégorie, session utilisateur) plutôt que de créer un modèle parallèle — bon signal de cohérence interne du logiciel de référence, à viser aussi pour DPI-RDC.

---

## Module 3 : Dossier Patient

*(Partie 1 — d'autres captures pourront compléter ce module plus tard)*

### 3.1 Accès au module

**Capture :** `01_menu_dossier_patient.png`

Depuis le menu principal, le bloc **Dossier Patient** se déplie en 4 raccourcis en icônes :
- **Salle des Soins**
- **Cabinet Médical**
- **Consultation Hospi**
- **Statistiques**

Les trois premiers ouvrent la même fenêtre technique (**"Recherche Episode Médical" / LISTE DE SEJOURS**) mais **paramétrée différemment selon le contexte d'entrée** (voir 3.2, 3.5, 3.8) — un même composant réutilisé plusieurs fois plutôt que 3 écrans développés séparément.

---

### 3.2 Salle des Soins — Liste de séjours (vue globale)

**Capture :** `02_salle_soins_liste_sejours.png`

**Objectif de l'écran**
File de travail globale de tous les épisodes de soins du jour (ou de la période choisie), tous types et toutes unités confondus. C'est probablement l'écran le plus consulté du logiciel : c'est le **tableau de bord opérationnel de la journée**.

**Champs et données**
- Filtres : **Sélectionner Site**, Date de début/fin, Période prédéfinie, **Appliquer**
- Tableau : **Noms, Prénom, Motif, Unité Médicale, Date** (horodatage précis à la seconde)
- Actions latérales : **File d'attente**, **Rendez-vous**, **Imprimer Bon**, **Affectation Médecin**
- Boutons bas de fenêtre : **Achevés (Moi)**, **Salle de Soins**, **Stock Officine**
- Compteur **Total Visite** affiché en évidence (ex. `132`), avec une icône de **synchronisation cloud** à côté — suggère un **rafraîchissement en temps réel/quasi temps réel**

**Exemples de "Motif" observés**
`Traitement`, `Résultat avant 1 semaine`, `Résultat après 1 semaine`, `Consultation`, `Soins infirmiers`, `Examen`

**Exemples d'"Unité Médicale" observées**
`Suivi Kinésithérapie`, `Nephrologie`, `Hématologie Pédiatrique`, `Pédiatrie`, `Nursing`, `Cardiologie`, `ECG`, `Echographie cardiaque Doppler`, `Dentisterie`, `Gynécologie`, `Visa` *(sic — probablement une unité liée aux visites médicales pour visa/expatriés)*, `Urologie`

**Règles métier observées**
- Le champ **"Motif"** fonctionne comme une **liste structurée d'états de suivi**, pas du texte libre : `Résultat avant/après 1 semaine` désigne probablement des rendez-vous de suivi post-traitement programmés automatiquement — pattern de rappel de suivi à noter.
- Bouton **"Affectation Médecin"** directement sur la liste globale — un épisode peut donc être créé **sans médecin assigné**, avec affectation a posteriori (utile en triage/urgence).
- **"Achevés (Moi)"** confirme (comme en 1.14/1.15) qu'un utilisateur peut filtrer sur **ses propres actes achevés** — traçabilité individuelle de charge de travail.
- **"Stock Officine"** accessible directement depuis cet écran de soins — raccourci pratique vers la pharmacie sans changer de module, cohérent avec un flux où le soignant prescrit et vérifie la dispo en pharmacie dans le même geste.
- Le compteur **Total Visite** en temps réel (avec icône sync) est un bon indicateur d'activité globale de l'hôpital pour la journée — utile en tableau de bord de direction.

**Lien avec DPI-RDC**
Cet écran est probablement **le plus important à répliquer correctement**. Recommandation : construire une vue "file de travail du jour" transverse (tous départements), avec filtre par motif/unité/site, actions rapides (recevoir, affecter médecin, imprimer bon), et un compteur d'activité en temps réel — plutôt que des files d'attente isolées par département.

---

### 3.3 Agenda (planification des rendez-vous)

**Capture :** `03_agenda_rdv.png`

**Objectif de l'écran**
Gestion complète de l'agenda des rendez-vous par médecin/prestataire — plus riche que le simple écran "RDV Encours par Médecin" vu en 1.8.

**Champs et données**
- 3 onglets : **Afficher agenda méd/jour**, **Rechercher RV libres/type**, **RV libres par médecin**
- Filtres : **Sélectionner Site**, **Prestataire**
- Actions : **Annuler RV**, **Bloquer RV**, **Débloquer RV**, **Fixer RV**, **Imprimer RV**
- Widget calendrier mensuel avec navigation (◄ ►) et raccourci **"aujourd'hui"**
- Tableau : **PATIENT, Contact, Prestataire, Type RDV, statut, Date, Tracage, Observation, Autres observation**

**Règles métier observées**
- **"Bloquer RV" / "Débloquer RV"** — un prestataire peut bloquer des créneaux (congé, indisponibilité, réunion) sans lien à un patient. C'est un besoin distinct de l'annulation d'un RDV existant.
- **"Rechercher RV libres/type"** et **"RV libres par médecin"** comme onglets dédiés — confirme un vrai moteur de recherche de créneaux libres (par type d'acte ou par médecin), pas une simple grille d'agenda statique.
- Colonne **"Tracage"** — probablement un historique/log des modifications du RDV (créé par, modifié par, quand).
- Le champ **"Contact"** distinct de "Patient" — suggère la possibilité d'enregistrer un numéro de contact pour rappel du RDV, potentiellement différent du dossier patient complet.

**Lien avec DPI-RDC**
Si un module agenda/RDV est dans le périmètre cible, ce triptyque **agenda par médecin + recherche de créneaux libres + blocage de disponibilité** est le modèle minimal à couvrir pour un vrai outil de planification, au-delà d'une simple liste de RDV.

---

### 3.4 Imprimer Bons

**Capture :** `04_imprimer_bons.png`

**Objectif de l'écran**
Sélection et impression des "bons" (bons de pharmacie, d'examen, etc.) liés à un patient/épisode.

**Champs et données**
- Tableau : **DateHeure, Service, Acte, DateV (date de validation), Action, Print (case à cocher)**
- Exemple de lignes : `Pharmacie - Médicaments / NEUTROSEC susp/tonic Flc 200 ml` ; `Médecine Générale / Résultats avant 1 semaine - Généraliste`
- Actions bas de fenêtre : **Ordonnance sans prix**, **Ordonnance avec prix**, **Facture Partielle**, **Fermer**

**Règles métier observées**
- Distinction essentielle **Ordonnance sans prix / Ordonnance avec prix** : la même prescription peut être imprimée en **version patient** (sans prix, pour aller acheter/faire l'acte) ou en **version interne/facturation** (avec prix visible) — un détail simple mais important pour l'expérience patient.
- **"Facture Partielle"** peut être générée directement depuis cet écran de bons, sans repasser par le module Facturation complet — raccourci pragmatique.
- Le "bon" regroupe des actes de **services différents** (Pharmacie ET Médecine Générale dans le même tableau) — confirme que le "bon à imprimer" est bien rattaché à l'épisode du patient, pas à un seul département.

**Lien avec DPI-RDC**
À prévoir pour les futurs modules pharmacie/laboratoire : une impression de "bon"/ordonnance avec un mode **prix caché** (remise au patient) et un mode **prix visible** (usage interne), déclenchable directement depuis la liste des actes prescrits de l'épisode en cours.

---

### 3.5 Cabinet Médical — Liste de séjours

**Capture :** `05_cabinet_medical_liste_sejours.png`

**Objectif de l'écran**
Variante de la liste de séjours (voir 3.2), ouverte cette fois depuis **"Cabinet Médical"** — même structure technique, données non anonymisées ici (utile pour observer des vrais motifs/unités).

**Données observées (exemples)**
Motifs : `Résultat avant 1 semaine`, `Consultation`, `Résultat après 1 semaine`, `Examen`
Unités médicales : `Cardiologie`, `Chirurgie Générale`, `Chirurgie Pédiatrique`, `Dentisterie`, `ECG`

**Boutons spécifiques à cette variante**
- **File d'attente**
- **Patients vus** (avec icône info) — probablement liste des patients déjà pris en charge aujourd'hui
- **Registre des actes achevés**

**Lien avec DPI-RDC**
Confirme que la même liste d'épisodes peut être filtrée pour donner des vues différentes selon le rôle : "à faire" (file d'attente), "déjà fait" (patients vus / actes achevés) — bon pattern de filtre à réutiliser plutôt que dupliquer les écrans.

---

### 3.6 Contrôle d'accès par code (action sensible)

**Capture :** `06_code_acces_modal.png`

**Objectif de l'écran**
Demande d'un **code d'accès supplémentaire** avant de réaliser une action, en surimpression de la liste de séjours.

**Élément observé**
Modale : *"Veuillez introduire votre code d'accès"* + champ de saisie + bouton **Annuler**

**Règles métier observées**
- Certaines actions (non identifiée précisément ici — probablement liée aux boutons "Patients vus" ou "Registre des actes achevés" vus juste avant) nécessitent une **ré-authentification par code**, même si une session est déjà ouverte. C'est une couche de sécurité supplémentaire pour des opérations sensibles (consultation de registres, actions irréversibles...).

**Lien avec DPI-RDC**
Si certaines actions dans DPI-RDC sont jugées sensibles (ex : accès à des informations confidentielles, suppression, correction de facture déjà validée...), prévoir la possibilité d'exiger une **confirmation par code/mot de passe** en plus de l'authentification de session — pattern de sécurité à considérer pour les actions à fort impact.

---

### 3.7 Réception du patient (workflow "Recevoir")

**Capture :** `07_recevoir_patient_modal.png`

**Objectif de l'écran**
Confirmation de prise en charge d'un patient depuis la liste de séjours/file d'attente.

**Élément observé**
Modale : *"Voulez-vous recevoir le patient ?"* avec boutons **Recevoir** / **Ne pas recevoir**

**Règles métier observées**
- Ce clic est le point de jonction entre la **file d'attente passive** (patient enregistré, en attente) et la **prise en charge active** (le soignant commence effectivement la consultation/le soin). C'est probablement le moment où le statut de l'épisode change (ex. "En attente" → "En cours").
- Le fait de pouvoir **"Ne pas recevoir"** suggère une gestion des cas où le patient ne se présente pas au bon moment ou doit être réorienté.

**Lien avec DPI-RDC**
Ce point de bascule "Recevoir / Ne pas recevoir" est un événement de workflow important à modéliser explicitement (avec horodatage) dans le cycle de vie d'un épisode DPI-RDC — utile aussi pour mesurer les temps d'attente réels (de l'enregistrement à la prise en charge effective).

---

### 3.8 Consultation en Hospi

**Capture :** `08_consultation_en_hospi.png`

**Objectif de l'écran**
Troisième variante de la liste de séjours (voir 3.2, 3.5), cette fois limitée aux **consultations réalisées dans le cadre d'une hospitalisation** (ex : un spécialiste appelé en interne pour un patient déjà admis).

**Champs et données**
- Filtres période habituels (Date début/fin, Période prédéfinie, Appliquer)
- Tableau : **Noms, Prénom, Motif, Acte, Date**
- Exemple observé : `Hospitalisation` / `Consultation Pneumologique` / `29/07/2026 09:01`
- Compteur **Total Visite : 1**

**Règles métier observées**
- Confirme la distinction entre une **consultation ambulatoire classique** (3.2/3.5) et une **consultation "in situ" pour un patient déjà hospitalisé** — le circuit de facturation/prise en charge est probablement différent (rattaché au séjour hospitalier plutôt qu'à un acte ambulatoire autonome).

**Lien avec DPI-RDC**
À garder en tête pour le module Hospitalisation : un patient hospitalisé peut recevoir des **consultations spécialisées ponctuelles** qui doivent être rattachées à son séjour, distinctes des actes de routine de son service d'hospitalisation.

---

### 3.9 Le Dossier Médical Patient — vue d'ensemble

**Captures :** `09_dossier_medical_paiement.png` à `22_dossier_medical_messagerie.png`

**Contexte de cette capture**
Ce lot documente l'écran le plus important du logiciel : le **Dossier Médical Patient** complet, ouvert ici sur le compte `IKULA NEWIS BORIS` — qui semble être un **compte de test/démonstration du personnel** (le patient est rattaché au `Service: INFORMATIQUE - Agent: Monkole`, et son nom correspond à l'utilisateur de la session `Ikula Boris` : probablement un agent informatique ayant créé sa propre fiche patient pour tester le système). Les données ne doivent donc pas être lues comme un cas clinique réel, mais la **structure de l'écran, elle, est pleinement représentative**.

**Structure générale**
- **En-tête permanent** (visible sur tous les onglets) : Nom complet + sexe + âge du patient, **Session ouverte par : [utilisateur]**, **Convention(e) : [nom]**, badge de mode de facturation (ex. `Conventionné(e) Forfait à l'ACTE`), et si le patient est lui-même un agent : `Service : ... - Agent : ...`
- **14 onglets** organisent l'intégralité du dossier : **Signalétique, Séjours, Rendez-vous, protocoles, Résultats, Paramètres, Antécédents, Allergies, Actes, Produits, Paiement, Trace accès, Hospitalisations, Messagerie**
- Bouton **Fermer** toujours accessible en haut à droite

**Constat majeur d'architecture**
Ce dossier patient est en réalité le **point d'agrégation central** de presque toutes les données déjà vues dans les modules précédents (épisodes/séjours, actes prescrits/achevés, produits/médicaments, paiements, rendez-vous...). Les écrans "de travail" vus jusqu'ici (Liste de séjours, Achèvement des actes, Facturation...) sont essentiellement des **vues filtrées multi-patients** de ces mêmes données ; le Dossier Médical Patient en est la **vue mono-patient complète**. C'est la confirmation la plus nette du modèle de données central à adopter pour DPI-RDC : quelques entités pivots (Patient, Épisode/Séjour, Acte, Produit, Paiement, Rendez-vous) consultées soit par patient (dossier), soit par file de travail (liste filtrée).

---

### 3.10 Onglet Signalétique (identité du patient)

**Capture :** `10_dossier_medical_signaletique.png`

**Champs et données**
- **Numéro Patient** : identifiant long non séquentiel visuellement (ex. `281,474,976,753,341`) — même format que les numéros de facture vus en 1.17, confirmant un **générateur d'ID unique commun** à toute l'application (probablement un timestamp/UUID numérique).
- Bouton **Activer les Modifications** : le formulaire est **en lecture seule par défaut**, il faut explicitement déverrouiller pour éditer.
- **IDENTITE** : Nom et Post Nom, Prénom, Date de Naissance, Matricule, Etat Civil (dropdown), Sexe (radio Masculin/Féminin), Profession (+ une pastille de couleur, usage non identifié — peut-être un code visuel de catégorie).
- **CONTACTS** : Commune (dropdown), Quartier (dropdown), Avenue (texte libre), Téléphone, Personne à contacter, E-Mail.
- **FACTURATION** : type privé(e)/Conventionné(e) (radio), **Convention 1** ET **Convention 2** (deux conventions possibles simultanément !), Catégorie (dropdown A/B/C/D).
- **AUTRES INFOS** : Religion, Ethnie, Groupe Sanguin (dropdowns), Date d'inscription, case **Confidentiel**, case **Est décédé**, Catégorie Ancienne (texte), **Message d'alerte** (zone de texte libre mise en évidence).
- Boutons **Valider** ✓ / **Annuler** ✗.

**Règles métier observées**
- **Adresse structurée en 3 niveaux administratifs congolais** : Commune → Quartier → Avenue, plutôt qu'un simple champ "adresse" libre — reflète l'organisation administrative réelle de Kinshasa/RDC.
- **Un patient peut avoir 2 conventions actives simultanément** (Convention 1 + Convention 2) — cas non couvert par le modèle "une convention par patient" habituellement supposé (ex : un employé assuré par son entreprise ET par une mutuelle personnelle).
- **"Catégorie" vs "Catégorie Ancienne"** : la catégorie tarifaire d'un patient peut changer dans le temps, avec conservation de l'ancienne valeur — utile pour l'historique de facturation rétroactive.
- **"Est décédé"** est une simple case à cocher sur la fiche patient elle-même — c'est probablement le déclencheur qui relie ce dossier au module Morgue (1.11).
- **"Confidentiel"** comme flag patient explicite — un dossier peut être marqué comme nécessitant une protection renforcée (cohérent avec le contrôle par code d'accès vu en 3.6).
- **"Message d'alerte"** en zone de texte libre bien visible — bannière d'alerte clinique (allergie sévère, patient à risque, consignes particulières) affichée probablement sur tous les écrans où le patient est chargé.

**Lien avec DPI-RDC**
Plusieurs éléments structurants à intégrer si absents : (1) modèle d'adresse Commune/Quartier/Avenue plutôt qu'un champ texte unique ; (2) support de **plusieurs conventions simultanées** par patient ; (3) un **flag "confidentiel"** et un **flag "décédé"** au niveau patient ; (4) une **bannière d'alerte clinique** visible en permanence sur le dossier.

---

### 3.11 Onglet Séjours (historique complet des épisodes)

**Capture :** `11_dossier_medical_sejours.png`

**Champs et données**
- Boutons de création par type de séjour : **Ambulatoire, Urgence, Hospitalisation, COVID ISO, COVID HOSPI**, + bouton **Tarifier**
- Tableau principal (historique complet) : **site, Date entrée, Date sortie, Motif, Unité Médicale, Origine, Diagnostic d'admission** — l'exemple montre un historique réel remontant sur plusieurs mois
- Tableau secondaire (détail du séjour sélectionné) : **Date entrée service, Date sortie service, Motif, Type de séjour** (dropdown, ex. `Ambulant`), **Unité Médicale**
- Actions latérales : **Valider**, **Modifier**, **Imprimer Bon**

**Règles métier observées**
- Le champ **"Origine"** (ex. `Domicile`) trace **d'où vient le patient** pour cet épisode — pertinent pour distinguer une admission directe d'un transfert depuis un autre établissement.
- Le **"Diagnostic d'admission"** est une colonne dédiée dès l'entrée du séjour, distincte des actes/résultats qui viendront ensuite.
- C'est ici que se matérialise concrètement le triptyque **Ambulatoire/Urgence/Hospitalisation** (+ variantes Covid) déjà repéré comme pivot du modèle de données (1.18, 2.6) : chaque bouton crée un nouveau séjour typé.

**Lien avec DPI-RDC**
Confirme qu'un **séjour/épisode** doit porter, dès sa création : type, origine, motif, unité médicale, et (pour les séjours cliniques) un diagnostic d'admission — modèle à structurer clairement dans la table `episodes` ou équivalent de DPI-RDC.

---

### 3.12 Onglet Rendez-vous (agenda du patient)

**Capture :** `12_dossier_medical_rendezvous.png`

Simple table de RDV liés à ce patient (**PATIENT, Prestataire, Date, Type de RDV, Venir pour, Observation**), avec boutons **Créer Rendez Vous** et **Imprimer**. Confirme que la prise de RDV est accessible **directement depuis le dossier patient**, en plus de l'agenda global multi-patients (3.3) — deux points d'entrée pour la même donnée, pattern à reproduire (créer un RDV depuis la fiche patient doit alimenter le même agenda que la vue globale).

---

### 3.13 Onglet Protocoles (documentation clinique / actes à prescrire)

**Capture :** `13_dossier_medical_protocoles.png`

**Actions disponibles**
**Achever actes**, **Prescrire** (icône crayon), **Voir tout**, **Imprimer**, **Afficher**, **Modifier**, **Compléments +**, **Nouveau +**

**Champs du tableau**
**date, catégorie, Resumé, Prestataire** — exemple : `Rapport Médical` / `Certificat d'aptitude physique` / `Dr NGOMA Oscar`

**Règles métier observées**
- Cet onglet centralise à la fois la **création de nouvelles prescriptions** ("Prescrire", "Nouveau") et la **documentation clinique produite** (rapports, certificats). "Catégorie" (`Rapport Médical` ici) suggère un référentiel de **types de documents cliniques** (certificat, rapport, ordonnance...) au-delà des simples actes techniques.
- **"Achever actes"** accessible directement ici confirme, une fois de plus, le cycle Prescription→Achèvement déjà documenté (1.14/1.15/3.x), mais cette fois **initié depuis le dossier du patient plutôt que depuis une file de travail**.

**Lien avec DPI-RDC**
Prévoir un référentiel de **types de documents cliniques génération** (certificat, rapport, courrier...) distinct du catalogue d'actes techniques, avec un cycle de vie propre (rédaction → validation → impression).

---

### 3.14 Onglet Résultats (labo + imagerie combinés)

**Capture :** `14_dossier_medical_resultats.png`

**Actions** : Résultats Labo Covid, Imprimer Imagerie, Imprimer Labo, **Résultats Labo En attente**, Afficher (œil)
**Tableau** : date, catégorie, Resumé, Prestataire — exemple : `Imagerie` / `Radiographie Standard` / `Dr BILA IYOMBI Marie`

**Règles métier observées**
- Labo et Imagerie sont **fusionnés dans une seule chronologie de résultats** au niveau du patient (alors que ce sont des modules séparés dans le menu principal) — bonne pratique : le patient doit voir une **vue unifiée de tous ses résultats**, indépendamment du département qui les a produits.
- Le filtre **"Résultats Labo En attente"** permet d'isoler rapidement ce qui n'est pas encore disponible — utile pour le suivi clinique actif.

**Lien avec DPI-RDC**
Le futur module Laboratoire de DPI-RDC devrait alimenter une **timeline de résultats unifiée** au niveau du dossier patient (labo + imagerie + éventuellement autres examens), pas seulement une liste interne au module laboratoire.

---

### 3.15 Onglet Paramètres (constantes / signes vitaux)

**Capture :** `15_dossier_medical_parametres.png`

**Champs et données**
- **Profil à encoder** (dropdown) : `CONSULTATION GENERALE`, `CONSULTATION NUTRITIONISTE`, `CONSULTATION ORL`, `CONSULTATION PEDIATRIQUE`, `CONSULTATION RP`, `CONSULTATION UDH` + bouton **Go**
- **Filtrer sur ligne courante** (dropdown)
- Tableau : horodatage, **Paramètre, Valeur, Saisi par** — exemples observés : `TAD=79`, `Poids=92.000`, `TAS=118`, `FC=85`, `T°=36.0` (Tension Artérielle Diastolique/Systolique, Fréquence Cardiaque, Température, Poids)

**Règles métier observées — point clé**
Les **paramètres à saisir dépendent du "Profil" de consultation choisi** : une consultation ORL n'aura probablement pas le même jeu de paramètres qu'une consultation pédiatrique ou nutritionniste. C'est un **système de formulaires de signes vitaux configurables par spécialité**, plutôt qu'un formulaire unique fixe (poids/taille/tension pour tout le monde).

**Lien avec DPI-RDC**
Fonctionnalité à fort potentiel pour le futur module Consultations : au lieu d'un formulaire de constantes universel, prévoir des **"profils de paramètres" configurables par type de consultation/spécialité**, extensibles sans redéveloppement (ex : table `parametre_profils` + `parametre_definitions` liées).

---

### 3.16 Onglets Antécédents et Allergies

**Captures :** `16_dossier_medical_antecedents.png`, `17_dossier_medical_allergies.png`

Deux onglets à la structure identique : un sélecteur **"Ajouter antécédent"** / **"Ajouter une allergie"** (dropdown, donc **liste structurée et non texte libre**), un bouton **Valider**, et une liste simple (**Libellé**) des éléments déjà enregistrés.

**Règles métier observées**
- Antécédents et allergies sont choisis dans un **référentiel prédéfini** (dropdown), pas saisis en texte libre — essentiel pour l'exploitation statistique/l'aide à la décision (ex : alertes d'interaction médicamenteuse basées sur les allergies structurées).

**Lien avec DPI-RDC**
Prévoir des référentiels structurés `antecedents` et `allergies` (codifiés, éventuellement alignés sur une nomenclature type CIM-10 pour les antécédents) plutôt que du texte libre, pour permettre à terme des alertes automatiques (ex. prescription bloquée si allergie connue au produit).

---

### 3.17 Onglet Actes (cycle de vie complet par patient)

**Capture :** `18_dossier_medical_actes.png`

**Tableau** : **Date de Prescription, Acte, Date de Validation, Date d'Achèvement** + bouton **Imprimer**

**Exemple observé**
`27/07/2026 15:08` → *Résultats avant 1 semaine - Généraliste* → validé `15:08` → achevé `15:26`
`23/07/2026 09:37` → *Hemoglobine glyquée (Hb glyquée) / % / 4-5,6 / DBT Sucré ≥6,5 / Prédiabète 5,7-6,4* → validé `09:40` → achevé `10:39`

**Règles métier observées**
- Confirme et précise le cycle déjà repéré (1.14/1.15) : **3 horodatages distincts** par acte — Prescription, Validation, Achèvement — tous conservés au niveau du dossier patient.
- Le nom de l'acte de laboratoire **inclut à nouveau les valeurs de référence** directement dans le libellé (`% / 4-5,6 / DBT Sucré ≥6,5...`) — confirme le point d'attention déjà soulevé en 1.17 : séparer proprement nom de l'acte et métadonnées de référence dans le modèle de données cible.

---

### 3.18 Onglet Produits (médicaments dispensés)

**Capture :** `19_dossier_medical_produits.png`

**Tableau** : **Date_P, Produit, Qté, Qte R, Posologie, Date Validation, Date Achèvement**

**Exemple observé**
`ARTEMETER+LUMEFANTRINE 20/120 (COMBIART/COARTEM...)` — Qté 2.00, Qte R 2.00, Posologie *"2x4co pdt 3jrs"*
`IBUPROFEN cés 400mg PQT10` — Qté 1.00, Qte R 1.00, Posologie *"S/2X1 CO/J PO PUIS AU BESOIN"*

**Règles métier observées**
- **"Qté" (quantité prescrite) et "Qte R" (quantité réellement délivrée)** sont deux champs distincts — permet de détecter les écarts entre ce qui a été prescrit et ce qui a été effectivement remis (rupture de stock partielle, ajustement...).
- La **Posologie est un champ texte libre standardisé** (abréviations médicales courantes : `2x4co pdt 3jrs`, `S/2X1 CO/J PO PUIS AU BESOIN`) — pas de structuration further (fréquence/durée séparées), ce qui limite l'exploitation automatique (calcul de fin de traitement, alertes) mais reste fidèle aux habitudes de prescription du personnel.

**Lien avec DPI-RDC**
Pour le futur module Pharmacie, prévoir explicitement la distinction **quantité prescrite / quantité délivrée**. À réfléchir : structurer la posologie (fréquence, durée, voie d'administration) en champs séparés plutôt qu'en texte libre, pour permettre des alertes automatiques (interaction, surdosage, durée de traitement expirée) — amélioration réelle par rapport au logiciel de référence.

---

### 3.19 Onglet Paiement

**Capture :** `09_dossier_medical_paiement.png`

**Actions** : **Accompte +**, **Imprimer Bon FC**, **Imprimer Bon $**
**Tableau** : **N°, Date de transaction, Type Transaction, Utilisateur, Montant en dollars, Montant en FC, Numéro Séjour**

**Règles métier observées**
- Chaque transaction de paiement est **rattachée à un numéro de séjour précis** — donc les paiements ne sont pas juste "au patient" mais "au patient pour tel épisode", ce qui permet une réconciliation facture/paiement fine.
- Chaque montant peut être enregistré en **FC et en dollars séparément** (colonnes distinctes) — confirme une fois de plus la logique multi-devises déjà vue (2.6), gérée ici transaction par transaction.
- **"Accompte"** (acompte) est une action de paiement dédiée, cohérente avec la distinction Facturé/Payé/Acompte déjà vue en 2.4.

---

### 3.20 Onglet Trace accès (audit trail)

**Capture :** `20_dossier_medical_trace_acces.png`

**Tableau** : **Date d'accès, Résumé, Document du, Utilisateur, Commentaire**

**Exemples observés**
Ouvertures de dossier par différents utilisateurs (`Ikula Boris`, `Dr DIAKENGUA Vainqueur`, `Banshikuye Josiane`, `Tshibuabua Reddy`, `Musau Hélène`, `Dr NGOMA Oscar`), plus des entrées liées à un document précis (`Certificat d'aptitude physique`) avec des commentaires typés : `Ouverture dossier`, `Impression Pr`, `Ajoute Pr`.

**Règles métier observées — point clé sécurité**
**Chaque ouverture du dossier patient est journalisée individuellement**, avec utilisateur, date/heure précise, et un type d'action normalisé (`Ouverture dossier`, `Impression Pr`, `Ajoute Pr`...). C'est un vrai **journal d'audit d'accès aux données médicales**, essentiel pour la conformité (confidentialité, RGPD-like, ou exigences locales de protection des données de santé).

**Lien avec DPI-RDC**
Fonctionnalité **non négociable** pour un DMI sérieux : chaque accès en lecture à un dossier patient (pas seulement les modifications) devrait être journalisé — utilisateur, horodatage, type d'action. À vérifier si DPI-RDC a déjà ce niveau de traçabilité en place ; sinon, priorité haute pour la conformité et la confiance du personnel médical.

---

### 3.21 Onglet Hospitalisations (sous-séjours et documentation infirmière)

**Capture :** `21_dossier_medical_hospitalisations.png`

**Tableau** : **Identifiant de sous_sejour, Nro Séjour, Service, Date entrée service, Date sortie service, Motif, Type de séjour**
**Actions latérales** : **Recap Evolution, Recap Trans. Inf., Actes Prescrits, Recap Traitements, Recap Evolution. Inf. URG, Recap Trans. Inf.URG**

**Règles métier observées**
- Confirme la structure à deux niveaux déjà pressentie (1.16) : un **séjour hospitalier (Nro Séjour)** peut contenir plusieurs **sous-séjours** (`Identifiant de sous_sejour`), correspondant probablement à chaque changement de service/unité pendant l'hospitalisation (le bouton "Transférer vers service" vu en 1.16 crée un nouveau sous-séjour).
- Distinction claire entre **documentation médicale** ("Recap Evolution" = notes d'évolution clinique) et **documentation infirmière** ("Recap Trans. Inf." = transmissions infirmières), avec des variantes dédiées pour les urgences (**"...Inf. URG"**).

**Lien avec DPI-RDC**
Pour le futur module Hospitalisation, prévoir une structure **séjour → sous-séjours (par unité)**, avec une documentation clinique distincte par profession (médecin vs infirmier) et par contexte (hospitalisation classique vs urgences).

---

### 3.22 Onglet Messagerie (fil patient)

**Capture :** `22_dossier_medical_messagerie.png`

**Actions** : **Nouveau Message +**, **Voir Message**
**Tableau** : **ID, Statut exp., envoyé le, par, à, Statut Dest., réponse le**

Messagerie interne **rattachée spécifiquement au dossier du patient** — probablement utilisée pour la coordination entre professionnels autour d'un cas précis (ex : une infirmière signale une observation au médecin traitant), distincte de la messagerie générale du menu principal (vue en 1.1).

---

### 3.23 Statistiques des Ambulatoires

**Capture :** `23_modal_statistiques_ambulatoires.png`

Modale accessible depuis le bouton "Statistiques" du bloc Dossier Patient (menu principal, 3.1). Options : **Registre Consultation, Avec Diagnostic, Registre actes infirmiers, Registre Séjour, Registre Forfait Mama, Registre Forfait Mama (Hospi)**

**Règles métier observées**
- **"Avec Diagnostic"** comme registre séparé suggère qu'un même registre de consultations peut être consulté **avec ou sans le détail diagnostic** — probablement une distinction de confidentialité (le diagnostic est une donnée plus sensible que le simple passage).
- **"Registre Forfait Mama"** (+ variante Hospi) révèle un **module maternité à forfait dédié** — une prise en charge de la grossesse/accouchement à tarif forfaitaire, avec son propre registre statistique, distinct du reste. À creuser si des captures dédiées à la maternité/fiche obstétricale arrivent plus tard.

---

## Constats transversaux — Module Dossier Patient (Parties 1 et 2)

- **Un seul composant "Liste de séjours"** réutilisé dans au moins 3 contextes différents (Salle des Soins, Cabinet Médical, Consultation Hospi) — bon pattern d'architecture (composant paramétrable plutôt que vues dupliquées).
- **"Motif"** confirmé comme liste structurée d'états de suivi, pilier du modèle d'épisode.
- **Workflow de prise en charge explicite et horodaté** : file d'attente → "Recevoir le patient" → soins → achèvement des actes → facturation.
- ⭐ **Le Dossier Médical Patient est l'agrégat central de toute l'application** : quasiment toutes les données vues dans les autres modules (épisodes, actes, produits, paiements, RDV) s'y retrouvent en vue mono-patient complète. C'est le modèle de référence à suivre pour l'architecture de données de DPI-RDC.
- **Cycle Prescription → Validation → Achèvement** à 3 horodatages distincts, appliqué systématiquement aux actes ET aux produits.
- **Distinction quantité prescrite / quantité délivrée** sur les produits pharmaceutiques.
- **Paramètres vitaux configurables par profil de consultation/spécialité**, plutôt qu'un formulaire unique universel.
- **Antécédents et allergies structurés** (listes prédéfinies), pas de texte libre — condition nécessaire pour de futures alertes automatiques.
- **Journal d'audit d'accès complet** (qui a ouvert quel dossier, quand, pour quelle action) — exigence de conformité à ne pas négliger dans DPI-RDC.
- **Structure séjour → sous-séjours** pour les hospitalisations, avec documentation distincte médecin/infirmier et contexte normal/urgences.
- **Support de 2 conventions simultanées** et d'une **adresse structurée Commune/Quartier/Avenue** au niveau de l'identité patient.
- **Sécurité renforcée par code d'accès** sur certaines actions sensibles, en plus de la session ouverte.
- **Distinction prix visible/masqué** sur les documents imprimés selon le destinataire (patient vs interne).

---

## Module 4 : Hospitalisation

### 4.1 Accès au module

**Capture :** `01_menu_hospitalisation.png`

Depuis le menu principal, le bloc **Hospitalisation** se déplie en 3 raccourcis : **Admission**, **Service**, **Statistiques**.

*(Remarque : l'écran "Admission" lui-même n'a pas été capturé dans ce lot — le flux d'entrée en hospitalisation reste à documenter si des captures dédiées arrivent plus tard.)*

---

### 4.2 Service — vue clinique complète d'un lit occupé

**Capture :** `02_service_hopital_jour_lit_occupe.png`

**Objectif de l'écran**
Version enrichie de l'écran "Service" déjà vu en 1.16 (Chirurgie) — ici pour l'unité **Hôpital de jour**. C'est **l'écran de tournée quotidienne** du personnel soignant : liste des lits + accès direct à toute la documentation clinique du patient sélectionné.

**Champs et données**
- **Unité** (dropdown, ex. `Hôpital de jour`)
- **Diètes** (dropdown, ex. `DIÈTE BASIQUE`) — régime alimentaire, visible en tête d'écran
- Tableau des lits : **ch-lit, statut, DS, Nom, sexe, age, cat, Convention, Sortie prévue, Commentaire** (identique à 1.16)

**Barre d'outils clinique** (pour un lit occupé)
`D.E.M` · `Messages` · `Dossier Patient` · **Évolution** (dropdown de statut rapide, ex. `Bonne`) · `Allergies` · **`Dossier Infirmier`** · `Antécédents` · `Ancien Dossier Hospi` · `Evolution / Diagnostic` · `Résultats Examens` · `Actes et Produits` · **`Prescription`** · **`Achever actes`**

**Barre d'outils administrative/facturation**
`Validation` · `Détails Facture` · `Sortie définitive` · `Imprimer bon` · `Changer de Lit` · `Billet de sortie` · `Transf vers serv` · `Rapport Médical` · `Modifier sortie` · `Note de Sortie` · `Certificat Med`

**Règles métier observées**
- Le dropdown **"Évolution"** directement dans la barre d'outils (valeur rapide type `Bonne`) permet de taguer l'état clinique général du patient **en un clic**, sans ouvrir le dossier complet — un résumé visuel utile en tournée.
- Cet écran est le **point d'entrée unique vers toute la documentation clinique** du patient hospitalisé : dossier médical, dossier infirmier, antécédents, allergies, résultats, évolution/diagnostic, prescription — tout est accessible depuis la liste des lits, sans navigation complexe.
- La présence à la fois d'actions cliniques et administratives (facturation, sortie, changement de lit) confirme que **le même écran sert au personnel soignant et à la gestion administrative du séjour** — pas de séparation stricte des interfaces par métier.

**Lien avec DPI-RDC**
Écran de référence à viser pour le futur module Hospitalisation de DPI-RDC : une **vue "ma tournée"** centrée sur les lits de l'unité, avec accès direct (sans changer d'écran) à la documentation clinique complète et aux actions administratives du séjour. C'est probablement l'écran à plus fort impact sur l'efficacité quotidienne du personnel hospitalier.

---

### 4.3 Prescription (Actes / Produits Pharmacie)

**Capture :** `03_prescription_actes_pharmacie.png`

**Objectif de l'écran**
Création d'une prescription pour le patient hospitalisé, déclenchée depuis le bouton "Prescription" de l'écran Service (4.2).

**Champs et données**
- 2 onglets : **Actes** / **Produit Pharmacie**
- Sélection en cascade : **Spécialité → Sous-spécialité → Acte**
- Tableau des lignes prescrites : **acte, Note/Posologie, qté, Prix U. Fc, PU. Conv (prix unitaire convention), PrixT_Fc (prix total), urgent** (case à cocher)
- Actions : **Prescription Libre**, **Utilisation Stock Local**, **Supprimer** (icône poubelle), **Fermer**, **Valider**

**Règles métier observées**
- Le **prix est calculé en temps réel dans la grille de prescription** (prix unitaire FC, prix unitaire convention, prix total FC) — le prescripteur voit donc immédiatement l'impact financier de sa prescription, avant même validation.
- La case **"urgent"** par ligne permet de prioriser certains actes/produits dans le flux de traitement (probablement remonte en tête des files de travail vues dans les modules précédents).
- **"Prescription Libre"** permet de sortir du catalogue structuré Spécialité/Sous-spécialité/Acte pour un cas non couvert — soupape de sécurité utile mais à utiliser avec parcimonie (moins exploitable statistiquement).
- **"Utilisation Stock Local"** suggère la possibilité de prescrire directement depuis le stock du service (armoire à pharmacie de l'unité) plutôt que de systématiquement passer par la pharmacie centrale — pertinent pour les unités avec stock tampon.

**Lien avec DPI-RDC**
Le calcul de prix en temps réel pendant la prescription (avant validation) est une bonne pratique UX à reprendre. Prévoir aussi une option de **prescription hors-catalogue tracée séparément** (pour ne pas polluer les statistiques sur les actes catalogués) et la possibilité de distinguer **stock de service vs stock central** en pharmacie.

---

### 4.4 Dossier Infirmier — vue d'ensemble

**Captures :** `04_dossier_infirmier_transmissions.png` à `13_dossier_infirmier_traitements_grille24h.png`

**Objectif général**
Le **Dossier Infirmier** est une fenêtre dédiée, distincte du Dossier Médical Patient (module 3), consacrée exclusivement à la **documentation de soins infirmiers** d'un patient hospitalisé. Il se compose de **9 sous-onglets** : **Transmissions Infirmières, Signes Vitaux, Pansement, Gavage, Rapports Auxiliaires, Bilan Hydrique, Evaluation Neuro, Transfusion, Traitements**.

**Constat d'architecture**
Ce dédoublement **Dossier Médical (module 3) / Dossier Infirmier (ici)** confirme une séparation nette entre la documentation **médicale** (diagnostic, actes, produits, résultats) et la documentation **de soins infirmiers** (surveillance, gestes techniques, administration horaire des traitements) — deux vues professionnelles distinctes sur le même patient/séjour, avec des besoins de structure très différents (le dossier infirmier est beaucoup plus proche du "chevet du patient" et de la surveillance continue).

**Lien avec DPI-RDC**
Pour le futur module Hospitalisation, prévoir explicitement cette séparation **dossier médical / dossier infirmier**, avec un modèle de données propre au suivi infirmier (surveillance horaire, gestes techniques normalisés) plutôt que de tout faire rentrer dans le modèle "Acte" générique déjà défini pour le reste de l'application.

---

### 4.5 Dossier Infirmier — Transmissions Infirmières

**Capture :** `04_dossier_infirmier_transmissions.png`

Journal de notes narratives infirmières. Tableau : **Date action, Problème, Actions, Achevé par**. Exemple observé (traduit en substance) : une note clinique détaillée décrivant l'état d'une patiente en post-opératoire de césarienne en urgence pour éclampsie, avec évaluation de la lucidité, coloration, hydratation et paramètres vitaux. Boutons : **Imprimer**, **Afficher**, **Nouveau +**.

**Règles métier observées**
Chaque transmission associe un **problème identifié** aux **actions entreprises**, avec la personne qui l'a "achevée" — structure narrative libre mais organisée en colonnes (Problème/Action), plutôt qu'un simple champ texte unique.

---

### 4.6 Dossier Infirmier — Signes Vitaux (+ courbe)

**Captures :** `05_dossier_infirmier_signes_vitaux.png`, `06_dossier_infirmier_signes_vitaux_graphe.png`

**Champs et données**
Tableau : **Date achèvement, Achevé par, Poids, Temp, TA (tension artérielle, format `systolique/diastolique`), FC, FR (fréquence respiratoire), SatOxy, Glycémie, DébitOxy**
Bouton **"Courbe sur"** bascule vers un **graphique linéaire** de l'évolution d'un paramètre dans le temps (ex. température).

**Règles métier observées**
- Set de paramètres **beaucoup plus complet que l'onglet "Paramètres" du dossier patient** (module 3.15, qui n'affichait que TAD/TAS/FC/T°/Poids) — le suivi infirmier en hospitalisation capture en plus **FR, SatOxy, Glycémie, DébitOxy**, cohérent avec une surveillance rapprochée de patient hospitalisé (vs consultation ambulatoire ponctuelle).
- La **visualisation en courbe** est une fonctionnalité déjà présente et clairement utile pour le suivi clinique (repérer une tendance, une dégradation).
- ⚠️ Remarque technique mineure : sur cette instance, le fond du graphique affiche une **image de carte du monde non pertinente** (probablement un template par défaut non remplacé) — à noter comme un défaut cosmétique du logiciel de référence, à ne pas reproduire dans DPI-RDC.

**Lien avec DPI-RDC**
Le futur module Hospitalisation devrait distinguer un **set de paramètres vitaux "ambulatoire"** (léger) d'un **set "hospitalisation"** (complet, avec FR/SatOxy/Glycémie/DébitOxy), avec visualisation en courbe pour le suivi de tendance — fonctionnalité à forte valeur clinique.

---

### 4.7 Dossier Infirmier — Pansement

**Capture :** `07_dossier_infirmier_pansement.png`

Suivi des soins de plaies. Tableau : **Date achèvement, Achevé par, Etat de la Plaie, Protocole, DateRefaire**. La colonne **"DateRefaire"** programme automatiquement le prochain soin — utile pour ne pas oublier un changement de pansement prévu.

---

### 4.8 Dossier Infirmier — Gavage

**Capture :** `08_dossier_infirmier_gavage.png`

Suivi de l'alimentation entérale (sonde). Tableau : **Date action, Réalisé par, Sonde, Résidu Gastrique, Type Aliment, Qté Aliment, Qté E[limination] (colonne tronquée)**. Confirme un suivi très détaillé et spécialisé, typique des soins intensifs/néonatalogie.

---

### 4.9 Dossier Infirmier — Rapports Auxiliaires

**Capture :** `09_dossier_infirmier_rapports_auxiliaires.png`

Zone libre pour tout rapport infirmier ne rentrant pas dans les catégories dédiées. Tableau : **Date achèvement, Réaliser par, Contenu**. Fonction "fourre-tout" structurée, complémentaire des catégories spécialisées.

---

### 4.10 Dossier Infirmier — Bilan Hydrique

**Capture :** `10_dossier_infirmier_bilan_hydrique.png`

**Champs et données (partiels, tableau large)**
**Superviser par, Date Action, Observation**, puis colonnes d'**entrées** : **Perfusion AM, Apport thérap IV AM, Transfusion AM, Apports Per os/SNG AM, Autres AM, Entrée_AM**, puis début d'une colonne **Uri[nes]** (sortie, tronquée dans la capture).

**Règles métier observées**
- Bilan hydrique classique de suivi hospitalier : **Entrées** (perfusion, apport IV thérapeutique, transfusion, apport oral/sonde naso-gastrique) vs **Sorties** (urines, et probablement d'autres pertes non visibles ici — drains, vomissements...).
- Le suffixe **"_AM"** sur toutes les colonnes suggère un suivi **par créneau/tranche horaire** (Avant-Midi), donc probablement dupliqué pour d'autres tranches (PM, nuit) non visibles dans cette capture — à confirmer si d'autres captures de cet écran arrivent.

**Lien avec DPI-RDC**
Fonctionnalité avancée mais à fort enjeu clinique (surveillance de la balance hydrique, essentielle en réanimation/post-opératoire) — à prévoir si le module Hospitalisation de DPI-RDC vise les services de soins intensifs.

---

### 4.11 Dossier Infirmier — Evaluation Neuro

**Capture :** `11_dossier_infirmier_evaluation_neuro.png`

**Champs et données**
**Date, Supervisé par**, puis colonnes structurées en cases à cocher par créneau (`AM`) : **OO AM** (ouverture des yeux), **A la doul AM** (réaction à la douleur), **Etir AM**, **Décorti AM**, **Décéré AM** (postures de décortication/décérébration), **Aucune AM**, puis réponse verbale : **Orienté AM, Confuse AM, Innapro AM, Incomp[réhensible] AM**

**Règles métier observées**
Structure clairement inspirée de l'**échelle de Glasgow (score de coma)** — ouverture oculaire, réponse motrice, réponse verbale — mais implémentée comme des **cases à cocher par catégorie** plutôt qu'un score numérique calculé automatiquement. Amélioration possible pour DPI-RDC : calculer le **score de Glasgow total automatiquement** à partir des cases cochées, plutôt que de laisser le soignant l'interpréter.

---

### 4.12 Dossier Infirmier — Transfusion

**Capture :** `12_dossier_infirmier_transfusion.png`

Tableau : **Produit Transfusion, Gr Donneur, Gr Receveur, Num Poche, Quantité, Date, Heure Début, Heure Fin, Observation**. Traçabilité complète d'une transfusion sanguine (produit, groupes sanguins donneur/receveur, numéro de poche pour rappel/rappel qualité, horaires précis) — essentiel pour la sécurité transfusionnelle et la traçabilité réglementaire.

---

### 4.13 Dossier Infirmier — Traitements (grille horaire 24h)

**Capture :** `13_dossier_infirmier_traitements_grille24h.png`

**Objectif de l'écran**
Le **plan d'administration des médicaments** (Medication Administration Record) — probablement l'écran infirmier le plus utilisé en pratique quotidienne.

**Champs et données**
- **Date** (sélecteur) + bouton **"Copier vers Jour suivant (Ctrl+D)"** (raccourci clavier direct !)
- Tableau : une ligne par traitement (ex. `OXYTOCIN 10UI X2/J IVD`, `AMOXYCLAV 1.2G X3/J IVD`, `KETOPROFEN 100MG X2/J IV`...), et **24 colonnes (1h à 24h)** avec case à cocher par heure d'administration prévue/réalisée

**Règles métier observées**
- Le nom du traitement **inclut directement la posologie** dans le libellé (`10UI X2/J IVD` = 10 unités, 2 fois par jour, intraveineux direct) — pattern texte libre standardisé déjà vu ailleurs (produits du dossier patient, 3.18).
- Le raccourci **"Copier vers Jour suivant"** est une fonctionnalité UX précieuse pour les traitements chroniques/longue durée — évite de ressaisir chaque jour un plan de traitement inchangé.
- La grille horaire **1h-24h avec case à cocher** est le modèle visuel classique du MAR (Medication Administration Record) utilisé dans la plupart des systèmes hospitaliers dans le monde — à reproduire fidèlement pour DPI-RDC si le module Hospitalisation est développé.

**Lien avec DPI-RDC**
Fonctionnalité à haute valeur ajoutée et relativement simple à implémenter : une grille "traitement × heures" avec case à cocher, dupliquée automatiquement d'un jour à l'autre pour les traitements en cours. Bon candidat de fonctionnalité différenciante même en V1 du module Hospitalisation.

---

### 4.14 Evolutions et Diagnostics (vue médecin)

**Captures :** `14_evolutions_diagnostics_evolution.png`, `15_evolutions_diagnostics_diagnostics.png`

**Objectif de l'écran**
Fenêtre séparée (accessible depuis "Evolution/Diagnostic" en 4.2), réservée à la documentation **médicale** (par opposition au Dossier Infirmier) : évolution clinique narrée par le médecin + diagnostics structurés.

**Onglet Evolution**
Tableau : **Date, Médecin, Contenu** (note narrative libre). Exemple : *"Patiente de 36 ans en postopératoire d'une césarienne en urgence indiquée pour éclampsie."*

**Onglet Diagnostics**
**"Ajouter un Diagnostic"** (dropdown — **liste structurée**, pas texte libre) + boutons Supprimer/Valider. Exemple observé : `Eclampsie`.

**Règles métier observées**
- Les diagnostics sont choisis dans un **référentiel structuré** (cohérent avec le pattern déjà vu pour antécédents/allergies, 3.16) — condition nécessaire pour des statistiques épidémiologiques fiables et un futur alignement CIM-10.
- Séparation claire **note narrative (Evolution) vs donnée structurée (Diagnostics)** — bonne pratique à conserver : la narration reste libre pour la nuance clinique, mais le diagnostic principal est codifié pour l'exploitation.

**Lien avec DPI-RDC**
Prévoir un référentiel de diagnostics structuré (idéalement CIM-10 ou une nomenclature adaptée au contexte RDC) séparé des notes d'évolution en texte libre.

---

### 4.15 Gestion des statuts de lit (Libre / À nettoyer / À réparer)

**Captures :** `16_service_lit_libre_actions.png`, `17_service_lit_a_reparer.png`, `18_service_lit_a_nettoyer.png`

**Objectif de l'écran**
Le même écran "Service" (4.2) affiche une **barre d'outils différente selon le statut du lit sélectionné** — confirmant un comportement contextuel riche.

**Pour un lit "Libre"**
Actions : **Transfert entrant**, **Lit à nettoyer**, **Lit à réparer**, **Bloquer le lit**

**Pour un lit "A réparer"**
Action unique : **Libérer lit**

**Pour un lit "A nettoyer"**
Action unique : **Libérer lit**

**Règles métier observées**
- Le cycle de vie d'un lit comporte donc au moins les statuts : **Occupé → Libre → (A nettoyer | A réparer | Bloqué) → Libre à nouveau (via "Libérer lit")**. C'est un **petit workflow d'état** à part entière, indépendant du séjour patient, qui gère la disponibilité opérationnelle du parc de lits.
- **"Bloquer le lit"** (depuis un lit libre) permet de le retirer temporairement du pool disponible sans routine de nettoyage/réparation — cas d'usage flexible (ex. lit réservé, maintenance préventive).
- **"Transfert entrant"** disponible directement sur un lit libre — probablement le point d'entrée pour affecter un patient venant d'un autre service à ce lit précis.

**Lien avec DPI-RDC**
Le futur module Hospitalisation gagnerait à modéliser explicitement un **statut de lit indépendant du statut du séjour patient** : `Occupé / Libre / À nettoyer / À réparer / Bloqué`, avec des actions dédiées par statut — utile pour la gestion opérationnelle réelle des lits (un lit libre "sur le papier" n'est pas toujours immédiatement disponible).

---

### 4.16 Statistiques d'Hospitalisation

**Capture :** `19_modal_statistiques_hospitalisation.png`

Modale accessible depuis le bloc Hospitalisation du menu principal (4.1). Options : **Registre Hospitalisations, Registre Diagnostic Hospi, Registre Diagnostic Amb, Registre Transfusion, Registre reste du mois, Registre actes infirmiers, Registre Ph M3, Registre Hospi Transfert** + un bouton séparé **Tableau de Bord**.

**Règles métier observées**
- **"Registre Diagnostic Hospi"** vs **"Registre Diagnostic Amb"** confirme que les statistiques de diagnostics sont **scindées par type de séjour** (hospitalisation vs ambulatoire) plutôt qu'un registre unique — cohérent avec le triptyque Ambulatoire/Hospitalisation/Urgences déjà omniprésent.
- **"Registre Hospi Transfert"** dédié confirme, une fois de plus, l'importance des transferts entre services comme événement à part entière (déjà vu en 1.16, 3.21).
- **"Tableau de Bord"** mis en évidence séparément — probablement une vue de synthèse visuelle (indicateurs clés) distincte des registres détaillés bruts.

---

## Constats transversaux — Module Hospitalisation

- **Écran "Service" = tournée clinique** : point d'entrée unique vers toute la documentation (médicale + infirmière) et les actions administratives du séjour, organisé autour de la liste des lits.
- ⭐ **Séparation nette Dossier Médical / Dossier Infirmier**, chacun avec sa propre structure de données adaptée à son métier (le dossier infirmier étant beaucoup plus fin : signes vitaux étendus, bilan hydrique, évaluation neuro, transfusion, MAR horaire).
- **Grille horaire 24h pour l'administration des traitements** (MAR) avec raccourci de copie au jour suivant — fonctionnalité à haute valeur, relativement simple à implémenter.
- **Statut de lit indépendant du statut du séjour patient** (Occupé/Libre/À nettoyer/À réparer/Bloqué), avec un petit workflow dédié — modélisation à ne pas négliger pour la gestion opérationnelle réelle des lits.
- **Diagnostics toujours structurés** (liste prédéfinie), jamais en texte libre — cohérence totale avec les patterns antécédents/allergies déjà observés.
- **Calcul de prix en temps réel** pendant la prescription, avant validation — bonne pratique UX.
- Registres statistiques systématiquement **scindés par type de séjour** (Hospi/Amb/Urg).

---

## Module 5 : Urgences

### 5.1 Accès au module

**Capture :** `01_menu_urgences.png`

Bloc **Urgences** du menu principal, 3 raccourcis : **Triage**, **Prise en Charge**, **Statistiques**.

---

### 5.2 Séjours Urgences — liste et porte d'entrée du triage

**Captures :** `02_liste_sejours_urgences.png`, `03_liste_sejours_modal_revoir_triage.png`

**Objectif de l'écran**
Liste de tous les passages aux urgences (**LISTE DE SEJOURS AUX URGENCES**), filtrable par période, avec pour seul motif observé `Consultation Urgences`.

**Workflow — porte d'entrée du triage**
En cliquant sur un patient déjà dans la liste, une modale demande : *« Le triage a déjà été effectué, voulez-vous le revoir ? »* avec deux choix : **Revoir Triage** / **Ouvrir Dossier patient**.

**Règles métier observées**
- Le système **empêche/questionne la répétition d'un triage déjà fait** — évite la perte de temps et la redondance de données, tout en laissant la possibilité de le revoir/corriger.
- Cette porte d'entrée confirme que **le triage est une étape obligatoire et distincte** avant la prise en charge clinique proprement dite (voir 5.4).

---

### 5.3 Triage (échelle de tri structurée)

**Capture :** `04_triage_formulaire.png`

**Objectif de l'écran**
Formulaire de **triage d'urgence structuré**, permettant de déterminer automatiquement un **niveau d'urgence** (ici `4 — Prise en charge dans les 60 min`, affiché en évidence en rouge/gras) à partir de critères cliniques cochés.

**Structure du formulaire (catégories de critères)**
| Bloc | Exemples de critères |
|---|---|
| Symptômes et Signes (alerte vitale) | Arrêt Cardiorespiratoire, Polytraumatisé, Glasgow < 8, Convulsion, Patient inconscient, Apnée, FC > 180/min, Hémorragie, Réaction Anaphylactique |
| Circulation | Fréquence Cardiaque (4 tranches), Caractéristique du pouls (Rythmique/Arythmique) |
| Température | Afébrile / Fébrile > 38°C |
| Etat Neurologique | Score de Glasgow (15 / 14 / 10-13 pts) |
| Etat général du patient | Bon / Assez bon / Mauvais état général |
| Etat de peau | Bien colorée, Ictère, Pâleur, Cyanose, Transpiration |
| Intensité de la douleur | Normale / Douleur intense |
| Temps d'évolution de la maladie | ≥ 6h / < 6h |
| Mode d'arrivée | En marchant / Transporté par une autre personne |
| Respiration | Fréquence respiratoire, Travail respiratoire (Normal/Tirage intercostal/Stridor) |
| Pathologie motivant la venue | Dyspnée, Céphalée, Agitation, Réaction allergique, Amputation/ATR grave, Douleur abdominale/thoracique, Parésie/dysarthrie, Intoxication médicamenteuse, Perte de connaissance |
| Saignement actif | Non / Léger / Abondant |

Une case **ATR** (probablement "Accident de Travail/Route", à confirmer) est disponible séparément. Le formulaire se conclut par un bouton **"Fin du Triage"**.

**Règles métier observées — point clé**
- Il s'agit d'une **échelle de triage à 5 niveaux structurée**, très proche des standards internationaux de triage d'urgence (type CTAS/ESI) : les critères cochés déterminent automatiquement le niveau d'urgence et donc le délai de prise en charge cible (ici 60 minutes pour le niveau 4).
- La structure en **catégories cliniques indépendantes** (circulation, respiration, neuro, douleur, pathologie...) plutôt qu'un simple score global permet une **traçabilité fine** de ce qui a motivé le niveau attribué.

**Lien avec DPI-RDC**
C'est l'une des fonctionnalités **les plus critiques à répliquer fidèlement** si un module Urgences est prévu pour DPI-RDC : un vrai algorithme de triage structuré (pas un champ "urgence oui/non") a un impact direct sur la sécurité des patients. Recommandation : reprendre une échelle de triage reconnue (ou s'inspirer de celle-ci), avec calcul automatique du niveau à partir des critères cochés plutôt que laisser le soignant choisir subjectivement le niveau.

---

### 5.4 Prise en Charge — liste post-triage

**Capture :** `05_liste_sejours_prise_en_charge.png`

Écran quasi identique à 5.2 (**LISTE DE SEJOURS AUX URGENCES**) mais dans le contexte **"Prise en Charge"** — la file des patients déjà triés, prêts à être pris en charge cliniquement. Confirme la séparation claire entre deux étapes du parcours urgences : **Triage** (évaluation initiale) puis **Prise en Charge** (soins), chacune avec sa propre liste de travail.

---

### 5.5 Statistiques Urgences — Registre Triage

**Capture :** `06_registre_triage.png`

Registre listant chaque triage effectué : **Date, Patient, Sexe, Catégorie, Société, Niveau d'urgence, Achevé par**. Permet une analyse rétrospective de la distribution des niveaux d'urgence, utile pour le pilotage (dimensionnement des ressources selon l'affluence et la gravité).

---

## Constats transversaux — Module Urgences

- **Triage structuré et obligatoire avant prise en charge**, avec niveau d'urgence calculé à partir de critères cliniques cochés — fonctionnalité à très forte valeur sécuritaire, prioritaire si un module Urgences est prévu pour DPI-RDC.
- **Deux files de travail distinctes** (Triage / Prise en Charge) reflètent deux étapes séquentielles du parcours patient aux urgences.
- Le système **prévient la redondance** (triage déjà fait) tout en gardant la possibilité de le revoir.

---

## Module 6 : Statistiques (transverses)

*(À distinguer des mini-modules "Statistiques" locaux déjà vus dans chaque module — celui-ci est le bloc "Statistiques" de premier niveau dans le menu principal, transverse à tout l'hôpital.)*

### 6.1 Accès au module

**Capture :** `01_menu_statistiques.png`

5 raccourcis : **Statistique**, **TAB** (Tableau de bord), **Laboratoire**, **Pharmacie**, **Manager**.

---

### 6.2 Hub Statistiques (Consultations / Laboratoire / Autres)

**Capture :** `02_hub_statistiques.png`

Écran d'aiguillage avec 3 blocs : **Consultations** (Régistre, Avec Diagnostic, Nursing, + bouton séparé "Par catégorie"), **Laboratoire** (bloc présent mais sans bouton visible dans cette capture — peut-être vide par manque de droits ou non configuré), **Autres** (Tableau, Graphique). Confirme, une fois de plus, la distinction **registre "Avec Diagnostic"** vs registre simple déjà vue en 3.23/4.16 — cohérence totale du pattern à travers tout le logiciel.

---

### 6.3 Tableau de Bord de Statistiques CS (BI multidimensionnel)

**Capture :** `03_tableau_de_bord_statistiques_cs.png`

**Objectif de l'écran**
Le véritable outil de **Business Intelligence** du logiciel — un tableau de bord avec un très grand nombre de vues croisées pré-construites.

**Onglets observés (liste quasi complète, 2 rangées)**
Transfert interne Entrant, Diagnostic par mois, Adm par Âge, Adm par Ancienneté, Transfert interne sortant, Evolution par sortie, Heure d'adm, Adm par Localisation, Durée de séjour, Triage — puis : Par Catégorie, Par Sexe, Par Localisation, Par tranche Age, Détail âge, Age Moyen, Par Unité/Service, Par Prestataire, Graphique, Admission, Adm. Par Sexe, Nbre de Jour, Graphe H. Adm, Adm. Par Jour, Taux D'occupation, Adm. Par cat, Adm. Par Contrat, Par contrat, Par catégorie/Contrat.

**Onglet "Sélection"**
Permet de filtrer les statistiques par **type d'activité** (référentiel à 9 valeurs) : `AUTRE, CONSULTATION, INTERVENTION CHIRURGICALE MAJEURE, INTERVENTION CHIRURGICALE MINEURE, ACTE TECHNIQUE LABORATOIRE, ACTE TECHNIQUE IMAGERIE, SEJOURS, REPAS, TRANSPORT` (boutons Tous/Aucun pour tout cocher/décocher), sur une plage de dates (par défaut très large : `01/01/2018` à `31/12/2100`).

**Règles métier observées**
- **"Repas" et "Transport" apparaissent comme des catégories d'activité statistique à part entière**, au même niveau que les actes cliniques — confirme que l'hôtellerie (repas) et le transport (ambulance, vu en 1.7) sont **suivis et probablement facturés** comme n'importe quel autre service, pas juste des à-côtés logistiques.
- **Taux d'occupation** comme onglet dédié — indicateur de pilotage classique en gestion hospitalière (lits occupés / lits disponibles), essentiel pour la direction.
- La richesse de ce tableau de bord (près de 30 vues) illustre un besoin réel et mature de **pilotage par la donnée** dans un hôpital de cette taille — à garder en tête comme objectif à moyen terme pour DPI-RDC, même si une V1 n'a pas besoin d'autant de vues.

**Lien avec DPI-RDC**
Ne pas chercher à répliquer les ~30 vues d'un coup, mais prévoir un **modèle de données qui permette de les construire facilement plus tard** (dimensions : temps, âge, sexe, catégorie, unité, prestataire, convention/contrat) plutôt qu'un modèle qui rendrait ces analyses difficiles a posteriori. Prioriser dès la V1 : taux d'occupation, admissions par jour/catégorie, durée de séjour moyenne — les indicateurs de pilotage les plus immédiatement utiles.

---

### 6.4 Statistiques Laboratoire (dédiées)

**Capture :** `04_statistiques_laboratoire.png`

Fenêtre dédiée avec onglets : **Par Catégorie, Par Machine, Par Unité, Par Service, Convention, Prestataire, Test**. Confirme un axe d'analyse **"Par Machine"** — donc le suivi statistique va jusqu'au niveau de l'équipement d'analyse utilisé (utile pour la maintenance/amortissement des machines de labo).

---

### 6.5 Statistiques Pharmacie (dédiées)

**Capture :** `05_statistiques_pharmacie.png`

Fenêtre dédiée avec onglets : **Par Catégorie, Par Famille, Par officine, Par Service, Par Convention, Entrées Service, Contrat**. Confirme la notion d'**"officine" par service** déjà pressentie (Stock Officine vu en plusieurs endroits, détaillé en 8.4) — chaque service a son propre point de stock suivi séparément dans les statistiques.

---

## Module 7 : Imagerie Médicale

### 7.1 Accès au module

**Capture :** `01_menu_imagerie_medicale.png`

2 raccourcis : **Gestion d'Attente**, **Examen**.

---

### 7.2 Gestion d'Attente Imagerie

**Capture :** `02_gestion_attente_imagerie.png`

**Objectif de l'écran**
File d'attente des demandes d'examens d'imagerie, avec génération de tickets et vue sur la justification clinique de chaque demande.

**Champs et données**
- Filtres période + **Sélectionner Site**, bouton **Générer Numéros +**
- Table gauche : **Patient, Cat, Date**
- Table droite : **Examens Demandés (Rouge = Urgence)** — affichage de la demande complète : date/heure, prescripteur, nom de l'examen, et une **note clinique libre justifiant la demande**
- Actions : **Envoyer à l'examen**, **Details Facture**, **Registre**

**Exemple observé**
*Angioscanner thoracique*, prescrit par Dr MBOMBO Wilfrid, avec justification clinique complète : *"acidose respiratoire sévère à la gazo tumeur hypophysaire au programme de ce jour, exclure pneumopathie ou embolie pulmonaire"*.

**Règles métier observées**
- La demande d'examen d'imagerie **inclut une justification clinique en texte libre**, visible par le service d'imagerie avant même la réalisation — essentiel pour que le radiologue/manipulateur comprenne le contexte clinique et adapte la technique si besoin.
- Le code couleur **Rouge = Urgence** permet un tri visuel immédiat des demandes prioritaires dans la file.

**Lien avec DPI-RDC**
Toute demande d'examen (imagerie, mais aussi labo par extension) devrait porter un **champ de justification clinique structuré ou semi-structuré**, visible par le service exécutant — améliore la pertinence et la sécurité des examens réalisés.

---

### 7.3 Résultat Imagerie (protocole radiologue)

**Capture :** `03_imagerie_resultat_protocole.png`

**Objectif de l'écran**
Saisie du compte-rendu (protocole) par le radiologue, une fois l'examen réalisé.

**Champs et données**
- Table gauche : Patient/Date
- Table droite : **Examen demandé** (avec note clinique, ex. *"Pelviscanner (obstétrique) — Mise au point. A réaliser à partir du 23/07/2026"*), **Bon** (numéro), nom du technicien/radiologue ayant réalisé l'examen, prescripteur
- Actions : **Insérer Images**, **Mettre un protocole ✓**
- Bouton **Registre Protocoles**

**Règles métier observées**
- **"Insérer Images"** confirme que les images d'imagerie elles-mêmes (radios, scanners...) peuvent être rattachées numériquement au dossier — pas seulement le compte-rendu textuel.
- Le compte-rendu final est appelé **"protocole"** — terminologie médicale standard pour le rapport d'imagerie, cohérente avec le "Registre Protocoles" vu aussi en laboratoire (8.6).

**Lien avec DPI-RDC**
Pour un futur module Imagerie, prévoir le stockage de fichiers image (DICOM ou simples images) rattachés à la demande d'examen, en plus du compte-rendu texte structuré.

---

## Module 8 : Laboratoire

### 8.1 Accès au module

**Capture :** `01_menu_laboratoire.png`

4 raccourcis (le dernier semble dupliqué visuellement dans le menu) : **Prélèvement**, **Analyse**, **Validation Biologique**, **Centre d'impression**.

**Constat d'architecture — workflow labo en 3 étapes**
Le laboratoire suit un **circuit à 3 étapes séquentielles**, chacune avec son propre écran dédié : **Prélèvement (8.2) → Analyse (8.3) → Validation Biologique (8.5)**. C'est un pattern de contrôle qualité classique en biologie médicale (séparation technicien préleveur / technicien analyseur / biologiste validateur).

---

### 8.2 Prélèvement

**Capture :** `02_prelevement.png`

**Objectif de l'écran**
Enregistrement du prélèvement d'échantillon (prise de sang, etc.) pour les analyses prescrites.

**Champs et données**
- Filtres période + Site, boutons **Générer Numéros +**, **Achever Prélèvement** (vert)
- Table gauche : Patient/Date
- Table droite : **Analyses Demandées (Rouge = Urgence)** avec date/heure, prescripteur, **nom de l'analyse incluant l'unité et les valeurs normales** (ex. `Fer / µmol/l / 5,83-34,5`), technicien assigné, **NumEch** (numéro d'échantillon, ex. `28/07/2026-827563`), action **Envoyer à l'analyse**

**Exemples d'analyses observées**
`Fer`, `Protéine C Réactive (dosage)`, `Détermine HIV`, `Goutte Epaisse` (recherche de paludisme) — reflète les pathologies courantes dans le contexte congolais (paludisme, VIH).

**Règles métier observées**
- Le **numéro d'échantillon (NumEch)** regroupe plusieurs analyses prescrites en même temps sous un même identifiant (ex. plusieurs lignes partagent le même `28/07/2026-827563`) — un seul prélèvement physique peut servir à plusieurs analyses.
- Le nom de l'analyse **inclut systématiquement l'unité et les valeurs normales** dans son libellé — confirme une fois de plus (déjà vu en 1.17, 3.17) ce pattern à corriger dans DPI-RDC en séparant nom d'acte et métadonnées de référence.

**Lien avec DPI-RDC**
Prévoir un identifiant d'échantillon **partagé entre plusieurs analyses prescrites simultanément**, plutôt qu'un identifiant par analyse individuelle — reflète la réalité physique du prélèvement (une seule prise de sang peut alimenter plusieurs tests).

---

### 8.3 Analyse (saisie du résultat par le technicien)

**Capture :** `03_analyse_labo_resultat.png`

**Champs et données**
- Table gauche : Patient/Date (liste des échantillons à analyser)
- Table droite, pour l'analyse sélectionnée : nom de l'analyse (ex. `Groupage Sanguin ABO et Rhésus`), **echantillon** (numéro), **Résultat** (champ de saisie), **Machine/Méthode**, boutons **Voir Fichier / Insérer Fichier**, **Achever l'acte**
- Filtres bas de page : **Liste d'analyses, Examens Achevés, Examens en Attente**, **Stock Officine**

**Règles métier observées**
- Le champ **"Machine/Méthode"** rattache chaque résultat à l'équipement/méthode utilisé — traçabilité technique importante (cohérent avec l'axe "Par Machine" des statistiques labo, 6.4).
- Possibilité d'**insérer un fichier** en complément du résultat texte (ex. photo d'un frottis, tracé...).

---

### 8.4 Stock Officine (déclinaison par service)

**Capture :** `04_stock_officine_labo.png`

**Objectif de l'écran**
Gestion du stock de consommables **propre à un service** (ici l'officine du Laboratoire) — confirme et détaille le pattern déjà entrevu (bouton "Stock Officine" présent dans de nombreux écrans depuis le début de la documentation).

**Champs et données**
- **Officine** (dropdown, ex. `LABORATOIRE`) — donc chaque service a sa propre officine sélectionnable
- Actions : **Sortie Directe**, **Listes**, **Réquisition +**
- Table : **Produit, Quantité, Prix, Prix_T** (prix total), avec ligne **Somme** en bas de tableau

**Exemples de produits observés**
Consommables de laboratoire/soins courants : Vaseline, Surchaussures, Surblouse PC, Sparadrap (plusieurs formats), Sonde urinaire, Sérum physiologique (plusieurs contenances), Seringues...

**Règles métier observées**
- Modèle de **stock décentralisé par service** (officine locale), avec possibilité de **réquisitionner** depuis la pharmacie/stock central plutôt que de tout centraliser — pertinent pour la réactivité opérationnelle (le labo n'attend pas la pharmacie centrale pour chaque seringue).
- **"Sortie Directe"** permet une consommation de stock sans passer par une prescription formelle — utile pour les consommables (pas les médicaments) qui ne nécessitent pas de traçabilité patient par patient aussi stricte.

**Lien avec DPI-RDC**
Pour le futur module Pharmacie, prévoir un modèle de **stocks multiples** (pharmacie centrale + officines de service), avec un mécanisme de réquisition inter-stock, plutôt qu'un stock hospitalier unique — reflète mieux la réalité opérationnelle d'un hôpital multi-services.

---

### 8.5 Validation Biologique

**Capture :** `05_labo_validation_biologique.png`

**Objectif de l'écran**
Étape finale de contrôle qualité — le **biologiste** relit et valide chaque résultat saisi par le technicien avant sa diffusion.

**Champs et données (par résultat)**
- Nom de l'analyse (ex. `Hémogramme`, `Urée / mg% / 10-50`, `Créatinine / mg% / 0,5-1,3`)
- **Résultat** (saisi par le technicien), **Prescrit par**, **Technicien** (avec horodatage précis de chaque étape)
- **Biologiste** : **Interprétation de Résultat**, **Interprétation de Résultat Révisé**, **Labo Sous traité** (champ pour indiquer un laboratoire externe sous-traitant)
- Actions par ligne : **Retoucher** / **Valider**
- Actions générales : **Details Facture, Dossier Patient, Ancien Dossier Hospi, Générer Protocole**

**Règles métier observées**
- **3 rôles distincts et tracés** interviennent sur un même résultat : celui qui prescrit, celui qui réalise techniquement (technicien), celui qui valide biologiquement (biologiste) — avec un horodatage à chaque étape. C'est un circuit de contrôle qualité rigoureux, essentiel en biologie médicale.
- **"Interprétation de Résultat" vs "Interprétation de Résultat Révisé"** — permet une correction/révision de l'interprétation tout en conservant (probablement) la version initiale, pour audit.
- **"Labo Sous traité"** confirme la possibilité d'externaliser certaines analyses à un laboratoire partenaire, tout en gardant le résultat centralisé dans le dossier du patient — pertinent si l'hôpital n'a pas toujours toutes les capacités techniques en interne.
- Exemple observé avec un **nouveau-né (`BB KILUNDU BALELA (M) 0 ans 0 mois 3 jours`)** — confirme que le système gère des patients de tout âge, y compris les nourrissons de quelques jours, avec un format d'âge adapté (ans/mois/jours).

**Lien avec DPI-RDC**
Le circuit **Prescription → Technicien → Biologiste (validation)** à 3 rôles distincts, chacun tracé et horodaté, est un standard de qualité à répliquer si le module Laboratoire de DPI-RDC vise un usage sérieux en routine clinique (pas juste un enregistrement de résultats). Prévoir aussi la possibilité de marquer une analyse comme **sous-traitée** à un labo externe.

---

### 8.6 Registre Protocoles Labo

**Capture :** `06_registre_protocoles_labo.png`

Registre final listant tous les protocoles (comptes-rendus) de laboratoire générés : **Noms, Prénom, resumé, Prestataire, Date**, avec option d'impression. Point de sortie du circuit labo complet (Prélèvement → Analyse → Validation → Protocole).

---

## Constats transversaux — Modules Statistiques / Imagerie / Laboratoire

- **Workflow qualité en 3 étapes tracées** pour le laboratoire (Prélèvement → Analyse → Validation Biologique), avec un rôle et un horodatage distincts à chaque étape — standard à viser pour toute donnée biologique.
- **Justification clinique en texte libre** rattachée aux demandes d'examens (imagerie et labo) — améliore la pertinence clinique et la priorisation (code couleur urgence).
- **Un numéro d'échantillon peut regrouper plusieurs analyses** prescrites en même temps — reflète la réalité physique du prélèvement.
- **Stocks décentralisés par service ("officines")**, avec réquisition vers un stock central — modèle plus réaliste qu'un stock hospitalier unique.
- **"Repas" et "Transport" traités comme des catégories d'activité statistique/facturable** à part entière, au même titre que les actes cliniques.
- **Tableau de bord BI très riche** (près de 30 vues croisées) — objectif à moyen terme pour DPI-RDC, en construisant dès maintenant un modèle de données qui le permettra sans refonte majeure.
- **Possibilité de sous-traitance d'analyses** à un laboratoire externe, tout en centralisant le résultat dans le dossier patient.
- Terminologie **"Protocole"** utilisée de façon cohérente pour désigner un compte-rendu médical structuré (imagerie et laboratoire).

---

## Module 9 : Pharmacie

### 9.1 Menu Pharmacie (Officine / Dépôt Central)

**Capture :** `88.png`

**Objectif de l'écran**
Sous-menu du module Pharmacie accessible depuis l'accordéon du menu principal, avec seulement deux entrées : **Officine** et **Dépôt Central**.

**Éléments visibles**
- Icônes Officine (fiole + croix rouge) et Dépôt Central (fiole + croix rouge, style distinct)

**Règles métier observées**
- Contrairement à d'autres modules (Bloc Opératoire, Autres) qui exposent 3-4 sous-fonctions, la Pharmacie n'a que **deux points d'entrée**, reflet d'une séparation stricte entre deux niveaux logiques : la dispensation aux patients (Officine, potentiellement multiple par service, comme déjà vu en 8.4 pour le Laboratoire) et la gestion du stock central de l'hôpital (Dépôt Central), qui approvisionne les officines.
- Ce découplage à 2 niveaux (Dépôt central unique → Officines multiples par service) confirme et généralise le modèle de "stock décentralisé" entrevu en 8.4 (Stock Officine du Laboratoire) — la Pharmacie est visiblement le point d'entrée officiel de ce modèle, consommé ensuite par les autres services (Labo, Hospitalisation, Ambulatoire, Néonatologie...) via leurs propres officines.

**Lien avec DPI-RDC**
Confirme la nécessité, pour le futur module Pharmacie de DPI-RDC, de modéliser explicitement deux entités de stock distinctes : un **Dépôt Central** (achats, entrées fournisseurs, inventaire global) et des **Officines** multiples rattachées chacune à un service (Ambulatoire, Hospitalisation, Néonatologie, Laboratoire, Imagerie...), avec un mécanisme de transfert/réquisition entre les deux niveaux.

---

### 9.2 Choix de l'Officine active

**Capture :** `89.png`

**Objectif de l'écran**
Modale de sélection de l'officine sur laquelle l'utilisateur va travailler, affichée immédiatement au clic sur "Officine" depuis le menu Pharmacie.

**Champs et données**
- **Officine** (dropdown) — exemple observé : `MUANDA`
- Boutons **Valider** / **Annuler**

**Règles métier observées**
- Comme pour le "Lieu de travail" (1.2) et le "Stock Officine" (8.4, 9.4), le choix de l'officine est un **préalable obligatoire** avant d'accéder à l'écran de travail — le système impose systématiquement un contexte explicite (site, poste, officine) avant d'afficher des données, plutôt que de le déduire automatiquement.
- La valeur observée `MUANDA` suggère que les officines sont nommées par affectation ou localisation précise plutôt que par un libellé de service générique — à confirmer avec une liste complète des officines existantes.

**Lien avec DPI-RDC**
Le pattern "sélection de contexte obligatoire avant tout accès aux données" (lieu de travail, officine, salle...) revient assez souvent pour être traité comme un vrai principe de conception transversal dans DPI-RDC plutôt qu'une contrainte ad hoc par module — envisager un mécanisme de contexte de session unifié (site + poste + officine active) réutilisable par tous les modules.

---

### 9.3 Officine Ambulatoire — Liste des prescriptions à délivrer

**Capture :** `91.png`

**Objectif de l'écran**
Écran de travail principal du pharmacien/préparateur en officine : liste des prescriptions en attente de délivrance pour les patients ambulatoires, avec actions de délivrance ligne par ligne.

**Champs et données**
- Filtres : **Date de début / Date de fin**, **Période prédéfinie**, **Sélectionner Site** (ex. `CHME`)
- Table de gauche : **Patient**, **Date** (de la prescription) — sélection d'un patient filtre la table de droite
- Table de droite (prescription du patient sélectionné) : **Produit**, **qte**, **Posologie**, **Date**, **Prescrit par**, colonne **poser**
- Actions par ligne : **Achever** (✓), **Supprimer** (✗), **Achever une partie** (✓ partielle)
- Boutons globaux : **Stock Officine**, **Details Facture**, **Details Facture Prod**, **Les prescriptions**

**Règles métier observées**
- Une prescription peut être **délivrée intégralement, partiellement, ou refusée (Supprimer)** ligne par ligne — le workflow ne force pas une délivrance en bloc de toute l'ordonnance, cohérent avec la réalité d'une pharmacie hospitalière où une rupture de stock sur un seul produit ne doit pas bloquer la délivrance du reste.
- Chaque ligne de prescription porte **Prescrit par** (le médecin prescripteur) — traçabilité complète cohérente avec le circuit déjà observé en laboratoire (8.5).
- Les produits observés mélangent **médicaments et consommables médicaux** (seringues, gants stériles, fil de suture, sparadrap) avec des molécules (Amoxicilline, Diclofénac, Lidocaïne) — la Pharmacie ne distingue donc pas structurellement médicament vs consommable dans le circuit de prescription/délivrance.
- Colonne **"poser"** en fin de tableau (peu détaillée sur cette capture) suggère un suivi de l'administration effective du produit au patient, au-delà de la simple délivrance pharmaceutique — à creuser dans une capture ultérieure.
- La colonne **Produit** inclut systématiquement dosage et forme galénique dans son libellé (ex. `ACIDE MEFENAMIQUE (Meftal-500) Ces 500 mg Pl. 10`) — encore le pattern "métadonnées dans le libellé" déjà signalé pour les analyses de labo (1.17, 3.17, 8.2), à corriger dans DPI-RDC via un référentiel produit structuré (nom, dosage, forme, conditionnement en champs séparés).

**Lien avec DPI-RDC**
Pour le futur module Pharmacie de DPI-RDC : prévoir une **délivrance partielle par ligne de prescription** (pas seulement un statut binaire délivré/non délivré global), un lien fort **prescription → prescripteur → délivrance**, et un référentiel produit unique couvrant à la fois médicaments et consommables (avec dosage/forme/conditionnement en champs structurés distincts du nom).

---

### 9.4 Stock Officine (rappel générique)

**Capture :** `92.png`

**Objectif de l'écran**
Même écran générique que documenté en 8.4 (Stock Officine du Laboratoire), ici accédé depuis la Pharmacie sans officine présélectionnée — capture d'un message de garde-fou.

**Champs et données**
- **Officine** (dropdown, vide ici), actions **Sortie Directe**, **Listes**, **Réquisition +**
- Table **Produit / Quantité / Prix / Prix_T**, ligne **Somme**
- Message modal observé : *"Prière de sélectionner une officine"*, bouton **OK**

**Règles métier observées**
- Confirme que l'écran **Stock Officine est un composant générique réutilisé** par plusieurs modules (Laboratoire en 8.4, Pharmacie ici) plutôt qu'un écran dupliqué par service — bonne pratique de conception dans le logiciel source.
- La validation "officine obligatoire" avant d'afficher le tableau est un **garde-fou explicite côté UI**, pas juste une contrainte silencieuse.

**Lien avec DPI-RDC**
Confirme l'intérêt de concevoir un **composant Stock unique et paramétrable** (par officine/service) dans DPI-RDC plutôt qu'un écran par module — réutilisable pour Pharmacie, Laboratoire, Imagerie, etc.

---

### 9.5 Dépôt Central — Tableau de bord de gestion du stock pharmaceutique

**Capture :** `93.png`

**Objectif de l'écran**
Écran de pilotage du stock central de la pharmacie hospitalière : gestion des entrées/sorties, suivi des demandes des officines, rapports d'inventaire et de mouvements.

**Champs et données**
- Bloc gauche **Fiches des Produits** : liste des officines demandeuses, date de dernière demande, bouton **Voir** — ex. `OFFICINE AMBULATOIRE`, `OFFICINE HOSPITALISATION` (×2, deux entrées distinctes), `NEONATOLOGIE`
- Bloc central **Rapports**, filtré par **Date de début / Date de fin / Période prédéfinie** :
  - Sous-bloc **Dépôt Central** : **Stock Actuel**, **Inventaire**, **Rapport des Réquisitions**, **Rapport des Entrées**, **Stock Alerte**, **Inventaire Dépôt**, **Effectuer Entrée**, **Effectuer Sortie**, **Stock des officines**, **Rapport des Sorties**, **Stock Réappro**, **Rapport Inventaire**
  - Sous-bloc **Officines** : **Sorties / Consommation**, **Sorties vers Services**
- Bloc droit **Signalétique** : **Provenances**, **Destination Sortie prod**, **Officines Internes**, **Effectuer Entrée officine Ambulatoire**, **Effectuer Entrée officine Hospitalisation**
- Bloc **Composition KIT** : **Liste**, **Composition**

**Règles métier observées**
- Le Dépôt Central sert de **hub d'approvisionnement pour plusieurs officines**, chacune pouvant faire des demandes horodatées suivies individuellement (ex. deux entrées distinctes pour "OFFICINE HOSPITALISATION" avec des horodatages différents dans la même journée) — confirme un flux de réquisition répété et granulaire plutôt qu'une commande globale périodique.
- **"Stock Alerte"** distinct de "Stock Actuel" — suggère un seuil de réapprovisionnement paramétré par produit, avec vue dédiée aux ruptures/produits sous seuil.
- Notion de **"KIT"** (Composition/Liste) — un kit regroupe probablement plusieurs produits sous une référence unique (ex. "kit accouchement", "kit pansement"), simplifiant la sortie de plusieurs produits liés en une seule opération.
- **"Provenances"** et **"Destination Sortie prod"** dans le bloc Signalétique suggèrent un référentiel de fournisseurs/provenances pour les entrées, et de destinations (services, patients, pertes...) pour les sorties — traçabilité complète du cycle de vie d'un produit.
- La distinction entre **"Effectuer Entrée officine Ambulatoire"** et **"Effectuer Entrée officine Hospitalisation"** en boutons dédiés (plutôt qu'un bouton générique avec sélection d'officine) suggère que ce sont les deux officines les plus fréquemment approvisionnées, avec un raccourci direct pour accélérer le flux quotidien.

**Lien avec DPI-RDC**
Pour le futur module Pharmacie de DPI-RDC, prévoir : (1) un **workflow de réquisition officine → dépôt central** avec historique horodaté par demande ; (2) une notion de **seuil d'alerte par produit** distincte du stock courant ; (3) un référentiel de **kits** (regroupement de produits sous une référence unique) ; (4) une traçabilité **provenance (entrée) / destination (sortie)** pour chaque mouvement de stock, essentielle pour l'audit et la gestion des pertes/péremptions.

---

## Module 10 : Bloc Opératoire

### 10.1 Menu Bloc Opératoire

**Capture :** `94.png`

**Objectif de l'écran**
Sous-menu du module Bloc Opératoire, avec 4 sous-fonctions : **Réservation**, **Planification**, **Horaire Bloc**, **Intervention**, plus **Rapport**.

**Règles métier observées**
- Séparation claire entre la **demande** (Réservation), la **planification** (affectation salle/horaire), le **suivi visuel** (Horaire Bloc, vue calendrier), et l'**exécution/clôture** (Intervention) — circuit à 4 étapes distinctes, cohérent avec la rigueur de traçabilité déjà observée ailleurs (labo en 3 étapes, imagerie).

**Lien avec DPI-RDC**
Le Bloc Opératoire n'était pas dans le périmètre initial des modules Consultations/Pharmacie/Laboratoire prévus pour la prochaine phase de DPI-RDC — à évaluer si CHME/CSK nécessitent une gestion de bloc dès cette phase ou si ça peut être reporté à une itération ultérieure, vu la complexité de planification de salle qu'implique ce module.

---

### 10.2 Demandes pour le Bloc — Programme Préopératoire (Réservation)

**Capture :** `95.png`

**Objectif de l'écran**
Écran de saisie/consultation des demandes d'intervention chirurgicale, avant planification définitive.

**Champs et données**
- Filtres : **Mes demandes / Toutes les demandes** (radio), **Préoparatoire / Plannifiées** (radio), **Date début / fin**, **Période prédéfinie**
- Table : **PATIENT**, **Intervention** (ex. `césarienne`), **Diagnostics** (ex. `Bassin rétréci`), **Chirurgien** (ex. `Dibeti Blandine`), **Date Echéance**, **Service** (ex. `CHIRURGIE OBSTETRICALE`), **Durée** (ex. `01:00`), **Demandé par** (ex. `Dr PATAULI DESIRE`)
- Actions : **Modifier**, **Nouvelle Demande +**

**Règles métier observées**
- La demande d'intervention est **initiée par un médecin autre que le chirurgien opérateur** (ici "Demandé par : Dr PATAULI DESIRE" vs "Chirurgien : Dibeti Blandine") — reflète le circuit réel où le médecin traitant/référent demande l'intervention, ensuite affectée à un chirurgien.
- Le filtre **"Mes demandes" vs "Toutes les demandes"** indique une vue personnalisée par utilisateur connecté en plus d'une vue globale — pattern de filtrage par acteur utile pour la gestion de charge de travail individuelle.
- Le champ **Diagnostics** est en texte libre et directement lié à la demande d'intervention — justifie cliniquement la nécessité de l'opération.
- La **Durée** est estimée dès la demande (avant planification), ce qui permet ensuite un calcul de créneaux disponibles en salle.

**Lien avec DPI-RDC**
Si le Bloc Opératoire est retenu pour une phase future de DPI-RDC, prévoir un circuit à 2 rôles distincts (demandeur ≠ chirurgien opérateur), avec diagnostic et durée estimée dès la demande — nécessaires pour la planification de salle en aval.

---

### 10.3 Planification du Bloc

**Capture :** `96.png`

**Objectif de l'écran**
Affectation d'une demande préopératoire à un créneau horaire précis (salle + heure), transformant une "demande" en "intervention planifiée".

**Champs et données**
- Table haute **Programme Préopératoire** (Clic droit pour planifier) : mêmes colonnes que 10.2, + **Consentement** (Oui/Non), **Urgence** (Oui/Non)
- Table basse **Programme planifié** : mêmes colonnes, alimentée après planification
- Actions : **Revoir**, **Plannifier**, **Replannifier**, **Salle d'OP**, **Service Bloc**

**Règles métier observées**
- Le **consentement du patient** (Oui/Non) est un champ à part entière suivi au niveau de chaque intervention — exigence éthique/légale intégrée au workflow, pas juste un document papier annexe.
- Le champ **Urgence** (Oui/Non) permet de distinguer les interventions programmées des interventions urgentes, avec probablement un impact sur la priorité de planification (à confirmer).
- L'exemple observé (`06/08/2026 09:00`) montre que la **Date Echéance** intègre une heure précise une fois planifiée (alors qu'en 10.2, avant planification, seule la date était visible) — la planification ajoute la granularité horaire.
- Interaction en **clic droit** pour planifier (plutôt qu'un formulaire dédié) — pattern d'interaction rapide orienté productivité, cohérent avec le style général de l'application.

**Lien avec DPI-RDC**
Si retenu, prévoir un champ **Consentement patient** explicite et tracé (pas seulement narratif) et un flag **Urgence** distinct du champ Diagnostic, pour permettre un tri/priorisation automatique des interventions à planifier.

---

### 10.4 Horaire Bloc (vue calendrier par salle)

**Capture :** `97.png`

**Objectif de l'écran**
Vue calendrier hebdomadaire de l'occupation du bloc opératoire, avec un onglet par salle.

**Champs et données**
- Onglets **Salle 1, Salle 2, Salle 3**
- Grille horaire 0h-23h en lignes, jours de la semaine en colonnes, navigation semaine précédente/suivante
- Bouton **Imprimer**

**Règles métier observées**
- L'hôpital dispose d'au moins **3 salles d'opération distinctes**, chacune avec son propre planning — confirme un établissement de taille significative (cohérent avec CHME, hôpital de référence).
- La grille couvre les **24h de la journée**, pas seulement les horaires de bureau — suggère la possibilité d'interventions de nuit (urgences chirurgicales).
- Cette vue est **complémentaire** à la Planification (10.3) : elle sert de tableau de bord visuel pour repérer rapidement les créneaux libres/occupés, plutôt que de saisie.

**Lien avec DPI-RDC**
Une vue calendrier par ressource (ici salle d'opération) est un pattern réutilisable pour toute ressource à capacité limitée dans DPI-RDC (salle de consultation, lit, équipement d'imagerie...) — à garder en tête comme composant UI générique si DPI-RDC évolue vers une gestion de capacité/planning.

---

### 10.5 Registre BlocOp

**Capture :** `98.png`

**Objectif de l'écran**
Registre exhaustif de toutes les interventions réalisées au bloc, filtrable par période — équivalent bloc opératoire du "Registre Protocoles Labo" (8.6).

**Champs et données**
Colonnes : **Date, Patient, Sexe, Age, Cat, Localisation, Société, Intervention, Diagnostic, Type Anesthésie, Chirurgien, Anesthésiste, Acte Intervention**

**Règles métier observées**
- Le registre inclut le **Type Anesthésie** et l'**Anesthésiste** comme colonnes de premier niveau — confirme que l'anesthésiste est un acteur tracé au même titre que le chirurgien, avec probablement un rôle et une responsabilité distincts dans le dossier opératoire.
- La colonne **Société** (déjà vue pour la prise en charge assurantielle en facturation) est présente ici aussi — le registre du bloc reste connecté à la logique de facturation/tiers payant.
- La colonne **Cat** (catégorie, déjà vue en 4.x hospitalisation — catégorie de chambre A/B/C) apparaît aussi ici, suggérant un lien entre le type de prise en charge du patient et son passage au bloc.

**Lien avec DPI-RDC**
Si le Bloc Opératoire est intégré à terme, prévoir dès la conception du dossier opératoire les champs **Type Anesthésie** et **Anesthésiste** comme entités de premier niveau (pas des sous-champs texte libre), pour permettre des statistiques par type d'anesthésie et par praticien.

---

### 10.6 Intervention Bloc (clôture)

**Capture :** `99.png`

**Objectif de l'écran**
Écran de clôture d'une intervention planifiée — dernière étape du circuit Réservation → Planification → Intervention.

**Champs et données**
- Mêmes colonnes que 10.3 (Programme planifié)
- Actions : **Clôturer l'intervention**, **Salle d'OP**, **Service Bloc**

**Règles métier observées**
- La **clôture est une action explicite et distincte** de la planification — une intervention planifiée n'est pas automatiquement considérée comme terminée à l'heure prévue, il faut une confirmation active, cohérent avec le pattern "Achever l'acte" déjà vu en Laboratoire (8.3) et Imagerie.
- Ce troisième écran clôt le circuit à 3 temps du Bloc Opératoire : **Demande (10.2) → Planification (10.3) → Clôture (10.6)**, avec le Registre (10.5) et l'Horaire (10.4) comme vues de consultation transverses.

**Lien avec DPI-RDC**
Le pattern "clôture explicite d'un acte" (vs échéance temporelle automatique) est récurrent dans GPS-Monkole (labo, imagerie, bloc) — à adopter systématiquement dans DPI-RDC pour tout acte/prestation ayant un cycle de vie (planifié → en cours → achevé), plutôt que de déduire un statut depuis une date.

---

## Module 11 : Autres

### 11.1 Menu Autres

**Capture :** `100.png`

**Objectif de l'écran**
Sous-menu "fourre-tout" regroupant des fonctions transverses ne correspondant à aucun grand module métier : **Suivi Haart**, **Rapport Nursing**, **Diète et ménage**.

**Règles métier observées**
- **"Suivi Haart"** fait référence au suivi thérapeutique **HAART** (Highly Active Antiretroviral Therapy — trithérapie antirétrovirale VIH), confirmant que CHME prend en charge des patients VIH avec un suivi de traitement dédié, distinct du dossier médical général.
- Le regroupement de fonctions aussi disparates (suivi thérapeutique spécialisé, rapport de soins infirmiers, gestion diète/ménage hôtelier) dans un seul menu **"Autres"** est révélateur d'une **dette de structuration progressive** du logiciel — des fonctions ajoutées au fil du temps sans réorganisation complète du menu principal. Point de vigilance pour DPI-RDC : prévoir une architecture de menu extensible qui n'oblige pas à créer un fourre-tout à mesure que de nouveaux besoins métier apparaissent.

**Lien avec DPI-RDC**
Le suivi VIH/HAART est un besoin de santé publique important en RDC — à évaluer si DPI-RDC doit prévoir un **module de suivi thérapeutique chronique générique** (réutilisable pour HAART, mais aussi tuberculose, diabète, hypertension...) plutôt qu'un module ad hoc uniquement pour le VIH.

---

### 11.2 Dossier Auxiliaire — Planification des tâches de soins

**Captures :** `102.png`, `103.png`

**Objectif de l'écran**
Planification et suivi des tâches effectuées par le personnel auxiliaire/brancardier, sur une base horaire.

**Workflow**
1. Une modale de choix de profil s'affiche : **Auxiliaire** / **Brancardier** (capture 102)
2. Sélection → ouverture de l'écran **Dossier Auxiliaire** (capture 103), avec sélection du **Service** (dropdown), onglets **Planification Auxiliaire** / **Rapport**

**Champs et données**
- **Date**, bouton **Copier vers Jour suivant (Ctrl+D)**, **Nouveau +**
- Table : **Tâches**, **Effectuer par**, grille horaire **1h à 24h** (une colonne par heure)

**Règles métier observées**
- La planification est **horaire et journalière**, avec une fonction de duplication rapide vers le jour suivant (raccourci clavier dédié) — suggère des tâches largement récurrentes d'un jour à l'autre (tournées de nursing, transport de patients), d'où l'intérêt de dupliquer plutôt que ressaisir.
- Cette fonction n'est **pas rattachée au dossier du patient** mais au **planning du personnel** — c'est un outil de gestion des ressources humaines/organisation du service, pas un outil clinique.
- Cette fonction n'apparaît pas rattachée à "Suivi Haart" malgré la proximité visuelle sur la capture 100 (flèche pointant vers ce bouton) — voir 12.5 où la table des fonctions (`HAART` = "Accès au Suivi Haart") confirme que ce sont deux fonctions distinctes ; la modale Auxiliaire/Brancardier est probablement accessible via une icône non capturée du menu principal plutôt que via "Suivi Haart".

**Lien avec DPI-RDC**
Si DPI-RDC souhaite couvrir la gestion opérationnelle du personnel (au-delà du dossier patient), prévoir un module de **planification horaire par service et par type de personnel** (auxiliaire, brancardier, infirmier...), avec duplication rapide de planning — fonctionnalité utile en dehors du strict périmètre clinique mais très demandée en gestion hospitalière quotidienne.

---

### 11.3 Diète et Ménage

**Capture :** `105.png`

**Objectif de l'écran**
Suivi transversal, pour les patients hospitalisés, du régime alimentaire (diète) prescrit et du service hôtelier (ménage) associé — vue par service.

**Champs et données**
- Filtres : **Service** (dropdown, ex. `Gynéco-Obstétrique`), **Date de début / fin**, **Période prédéfinie**
- Table : **Patient, Sexe, Age, CAT, Entrée, Sortie prévue, Sortie, DS** (durée de séjour, en jours), **Service, Chambre/Lit, Diètes, Société**
- Icônes par ligne (lit, plateau-repas) — probablement des raccourcis d'action
- Bouton **Details Facture**

**Règles métier observées**
- Le type de diète (ex. `DIÈTE BASIQUE`) est **rattaché au séjour hospitalier** au même titre que la chambre/lit — la nutrition hospitalière est traitée comme une prestation de soin à part entière, pas comme un simple service annexe.
- La colonne **DS** (durée de séjour, ex. `4`, `16`) est calculée et affichée directement dans cette vue — utile pour ajuster la diète/ménage en fonction de la durée réelle du séjour.
- Présence de nouveau-nés dans la liste (âge `0`, ex. `201 / Lit 1 bébé`) avec leur propre ligne, lit et diète — confirme (comme en 8.5) que même les nourrissons ont un enregistrement individualisé, y compris pour des aspects non strictement médicaux comme la diète.
- Bouton **Details Facture** directement accessible depuis cet écran — la diète/ménage a donc un **impact facturable**, cohérent avec la logique de facturation à l'acte/prestation déjà observée dans les autres modules.

**Lien avec DPI-RDC**
Pour DPI-RDC, si le module Hospitalisation intègre un volet hôtelier, prévoir que la **diète soit une prestation facturable rattachée au séjour** (pas juste une note libre dans le dossier médical), avec calcul automatique de durée de séjour affiché en contexte pour faciliter les ajustements quotidiens.

---

## Module 12 : Système / Administration

### 12.1 Menu Système

**Capture :** `106.png`

**Objectif de l'écran**
Sous-menu Système, avec un unique point d'entrée : **Administration**.

**Règles métier observées**
- Contrairement aux autres modules qui exposent plusieurs sous-fonctions directement dans l'accordéon, **Système ne montre qu'une seule icône (Administration)** — toute la complexité de configuration est regroupée derrière cette porte d'entrée unique plutôt que d'exposer chaque paramètre en sous-menu.

---

### 12.2 Menu Sécurité (Administration)

**Capture :** `108.png`

**Objectif de l'écran**
Panneau d'administration central, avec un menu de paramétrage général (colonne gauche) et une modale **Menu Sécurité** dédiée à la gestion des accès (utilisateurs, profils, fonctions).

**Champs et données**
- Colonne gauche (paramétrage général) : **Sécurité, Paramètres généraux, Paramètres Tarification, Paramètres médicaux, Paramètres RV, Liste Agent, Liste Ayant Droit, Param Entête, reprise fichiers SQL**
- Modale **Menu Sécurité** : session affichée (**Ikula Boris**), boutons **Utilisateurs, Profils, Fonctions**, **Fermer**

**Règles métier observées**
- L'administration regroupe à la fois de la **configuration métier** (Paramètres Tarification, Paramètres médicaux, Paramètres RV) et de la **gestion des accès** (Sécurité/Utilisateurs/Profils/Fonctions) dans un seul panneau — pas de séparation entre configuration fonctionnelle et administration des droits.
- **"Liste Ayant Droit"** (distinct de "Liste Agent") suggère un référentiel des bénéficiaires de prise en charge (assurance, société, ayants droit d'un assuré principal) — cohérent avec la logique multi-tiers déjà observée en facturation (assurance à 3 paliers).
- **"reprise fichiers SQL"** — fonction d'import/reprise de données via fichiers SQL bruts, probablement utilisée pour la migration/reprise d'historique lors du déploiement initial du logiciel dans un nouveau site — pertinent pour la propre stratégie de migration de DPI-RDC.

**Lien avec DPI-RDC**
Le triptyque **Utilisateurs / Profils / Fonctions** correspond exactement au modèle Users/Roles/Permissions déjà adopté par DPI-RDC via Spatie RBAC — bon signal de convergence. La distinction **"Liste Agent" vs "Liste Ayant Droit"** est à vérifier : DPI-RDC modélise-t-il déjà les ayants droit d'un assuré (personnes couvertes par la police d'un tiers payant) séparément du personnel de l'hôpital ?

---

### 12.3 Table Utilisateur

**Capture :** `109.png`

**Champs et données**
Colonnes : **Actif** (checkbox), **Nom et prénom**, **description**, **Description Profiluser**, **Dernière utilisation**
Exemples observés : `Abeli Fideline` / `INFIRMIERE CHEF` (dernière utilisation 02/09/2023) ; `Administrateur` / `INFORMATICIEN` (dernière utilisation 24/05/2024)

**Règles métier observées**
- Chaque utilisateur est rattaché à **un seul profil** (Description Profiluser), pas à plusieurs rôles cumulés — modèle RBAC à un rôle par utilisateur plutôt qu'un système multi-rôles.
- La colonne **Dernière utilisation** avec horodatage précis permet d'identifier facilement les comptes inactifs/dormants (ex. `Abeli Fideline` non utilisé depuis longtemps par rapport à la date des captures, juillet 2026) — utile pour l'audit de sécurité et le nettoyage des comptes.
- Case **Actif** décochée pour au moins un utilisateur observé — confirme un mécanisme de désactivation de compte sans suppression (traçabilité conservée).

**Lien avec DPI-RDC**
Le suivi de **"Dernière utilisation"** par compte est une bonne pratique de sécurité à intégrer dans DPI-RDC si ce n'est pas déjà le cas — permet de détecter les comptes dormants ou potentiellement compromis (cohérent avec l'intérêt de ComteDartagnan pour la sécurité web/OWASP).

---

### 12.4 Tableau des Profils Utilisateurs

**Capture :** `110.png`

**Champs et données**
Liste de profils (extrait visible, ordre alphabétique) : `ADMINISTRATIF`, `ADMINISTRATIF GOMBE`, `AUXILIAIRE`, `AUXILIAIRE AMBULATOIRE`, `CHEFRECEPTION`, `COMPTA`, `COORDINATION BLOC`, `DIRECTION FINANCIERE`, `DIRECTION MEDICALE`, `DIRECTION NURSING`, `FACTURATION`, `FACTURATION COVID`, `GESTIONNAIRE SSP`, `GLIS`, `IMAGERIE`, `IMAGERIE CHEF`, `INFIRMIER`, `INFIRMIER BO`, `INFIRMIER CHEF BLOC OPERATOIRE`, `INFIRMIER COVID`, `INFIRMIERE CHEF`, `INFIRMIER SARUD`, `INFIRMIER RDV`, `INFIRM RECEP FACTUR`, `INFORMATICIEN`, `INF STAGE PERFECT`

**Règles métier observées**
- Le référentiel de profils est **très granulaire et multiplié par site/contexte** plutôt que générique : `ADMINISTRATIF` existe en version standard et en version `ADMINISTRATIF GOMBE` (site-spécifique), de même pour `AUXILIAIRE` vs `AUXILIAIRE AMBULATOIRE` — un même métier a des profils distincts selon le contexte d'exercice, plutôt qu'un système de permissions composables.
- Présence de profils spécifiques Covid (`FACTURATION COVID`, `INFIRMIER COVID`) — séquelle de la période pandémique déjà notée en 1.1 (module "Covid Center"), confirmée ici au niveau RBAC.
- Profil **`INF STAGE PERFECT`** (infirmier en stage de perfectionnement) — suggère un profil dédié pour le personnel en formation continue, avec probablement des droits restreints par rapport à un infirmier titulaire.
- La multiplication des profils (26+ visibles, liste probablement plus longue) illustre un **risque classique de prolifération de rôles** dans un système RBAC vieillissant — chaque nouveau besoin crée un nouveau profil plutôt que de composer des permissions existantes.

**Lien avec DPI-RDC**
Point de vigilance direct pour Spatie RBAC dans DPI-RDC : préférer une **composition de permissions fines** (via des rôles combinables) plutôt que de dupliquer un rôle pour chaque variante site/contexte (ex. `ADMINISTRATIF` + attribut "site" plutôt que `ADMINISTRATIF` et `ADMINISTRATIF GOMBE` comme deux rôles distincts) — évite la prolifération observée ici.

---

### 12.5 Table des Fonctions (permissions granulaires)

**Capture :** `111.png`

**Champs et données**
Colonnes : **Code**, **Libellé**. Extrait observé (ordre alphabétique par code) :
`ACHEVÉ A` / Achever Acte · `ACVPERSO` / Voir mes Actes Achevé · `ACVTOUS` / Voir tous les Actes Achevés · `ADH` / Admission Patients Hospi · `ADM` / Admission Patients AMBU · `ADMCOVID` / Admission Patients Covid · `AGENDA` / Accès aux RDVs · `A RECUP` / A récupérer · `BLOCDEM` / Demande Intervention Bloc · `BLOCPLAN` / Plannif Interventions Bloc · `CAISSE` / Accès à Facturation · `CAUTION` / Ajout Caution · `COMPTA` / Accès aux Rapports Compta · `CORFACT` / Correction Facture · `DEPOT` / Accès au Dépôt Pharmacie · `DIETE` / Accès à Diète et Ménage · `DOSPAT` / Accès au Dossier Patient · `DOSSINF` / Accès au Dossier Infirmier · `ENDO` / Accès à l'Endoscopie · `ENTREEOF` / Entrer Produit Officine Direct · `ENTREEP` / Entrer Produit Pharmacie · `ENVIMG` / Envoie Image · `HAART` / Accès au Suivi Haart · `HORBLOC` / Accès aux Horaires Bloc · `IMG` / Accès à l'Imagerie · `IMPLAB` / Impression Labo

**Règles métier observées**
- Le système RBAC est bâti sur des **permissions atomiques mappées 1:1 à une action ou un écran précis** (ex. `ACVPERSO` "voir mes actes achevés" vs `ACVTOUS` "voir tous les actes achevés" — même fonction, portée différente selon le scope). C'est un modèle de permissions fines, plus granulaire que les profils eux-mêmes (12.4), qui sont ensuite des **agrégats de ces fonctions**.
- Distinction systématique entre portée personnelle et portée globale pour une même action (`ACVPERSO`/`ACVTOUS`) — chaque action sensible existe potentiellement en deux versions (soi-même vs tous), permettant un contrôle d'accès fin sans dupliquer les écrans.
- Le code `HAART` confirme la lecture faite en 11.1 : "Accès au Suivi Haart" est bien une fonction dédiée à part entière, séparée du dossier médical général — la fonction "Suivi Haart" est donc a priori un vrai module clinique VIH et non un mauvais étiquetage, ce qui clarifie l'ambiguïté relevée en 11.2 sur la capture 102 (modale Auxiliaire/Brancardier) : cette dernière est probablement une fonction distincte non nommée dans cet extrait, malgré la proximité visuelle avec le bouton "Suivi Haart" sur la capture 100.
- Codes courts (2 à 8 caractères), en majuscules, mnémotechniques — convention de nommage cohérente sur l'ensemble du référentiel.

**Lien avec DPI-RDC**
Ce référentiel de fonctions atomiques est un excellent modèle à répliquer avec Spatie RBAC : définir des **permissions Laravel nommées et mappées 1:1 à une action/écran** (ex. `actes.voir-personnel` vs `actes.voir-tous`), puis composer les rôles/profils comme des ensembles de permissions plutôt que de coder les droits en dur par écran. Le pattern **portée personnelle vs globale** (`ACVPERSO`/`ACVTOUS`) est directement transposable en Laravel (policies avec scope `own` vs `all`).

---

## Constats transversaux — Modules Pharmacie / Bloc Opératoire / Autres / Système

- **Modèle de stock à deux niveaux** (Dépôt Central unique → Officines multiples par service) confirmé et généralisé par la Pharmacie — dépasse le cas isolé du Laboratoire (8.4) et doit être traité comme un vrai modèle de données transverse dans DPI-RDC.
- **Délivrance/exécution partielle par ligne**, plutôt qu'un statut global binaire, revient dans plusieurs modules (Pharmacie ici, déjà observé pour les actes en Labo/Imagerie) — pattern de granularité à conserver.
- **Circuit en 3 temps (Demande → Planification → Clôture)** confirmé pour le Bloc Opératoire, cohérent avec le circuit à 3 rôles/étapes du Laboratoire (8.5) — signe d'une philosophie de traçabilité assez uniforme dans tout le logiciel pour les actes à fort enjeu.
- **Menu "Autres" comme zone de dette de structuration** : les fonctions qui n'ont pas trouvé leur place dans un module dédié (suivi VIH, planning du personnel, diète/ménage) s'y accumulent — signal d'alerte pour DPI-RDC de prévoir une architecture de navigation extensible dès le départ.
- **RBAC à deux niveaux** : des fonctions atomiques (Table Fct, mappées 1:1 à une action) agrégées en profils (Tableau des profils), mais avec une tendance à la **prolifération de profils** dupliqués par site/contexte plutôt qu'à la composition — point de vigilance direct pour Spatie RBAC dans DPI-RDC.
- **Sélection de contexte obligatoire avant accès aux données** (lieu de travail, officine, salle...) est un principe transversal appliqué de façon cohérente dans tout le logiciel — à formaliser comme pattern de conception explicite dans DPI-RDC plutôt que de le réimplémenter au cas par cas par module.

---

## Annexe — Suivi des captures intégrées

| Lot | Captures (nom original) | Module documenté |
|---|---|---|
| 1 | 1.png à 16.png | Accueil/Réception — partie 1 |
| 2 | 17.png à 28.png | Accueil/Réception — partie 2 |
| 3 | 29.png à 36.png | Facturation |
| 4 | 37.png à 44.png | Dossier Patient — partie 1 |
| 5 | 45.png à 59.png | Dossier Patient — partie 2 (dossier médical complet) |
| 6 | 60.png, 62.png à 62-14.png, 63.png à 66.png | Hospitalisation |
| 7 | 67.png à 83.png, 85.png à 87.png | Urgences, Statistiques, Imagerie Médicale, Laboratoire |
| 8 | 88.png, 89.png, 91.png à 100.png, 102.png, 103.png, 105.png, 106.png, 108.png à 111.png | Pharmacie, Bloc Opératoire, Autres, Système/Administration |

*Toutes les captures reçues sont conservées et renommées de façon descriptive dans le dossier `captures/` (sous-dossiers par module) du bundle final, pour référence croisée avec ce document.*

---

*Document en cours de construction — prochain lot à intégrer selon l'ordre que tu enverras.*
