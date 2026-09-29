# Monkole — Activités et capacités, et Diagnostics (programmes hors connexion)

Deux programmes dans le même dossier, qui partagent `entrees` et `sorties` :

| À lancer | Exports à déposer dans `entrees` | Classeur produit dans `sorties` |
|---|---|---|
| `LANCER_MONKOLE.bat` | les six exports Visites / Actes / Hospi (GPS et Evolucare) | Activités et capacités |
| `LANCER_DIAGNOSTICS.bat` | `Diagnostic_GPS_…` et `Diagnostic_Evolucare_…` | Diagnostics et composition des familles |

Les huit exports peuvent rester ensemble dans `entrees` : chaque programme ne lit que les siens.

## Partie 1 — Activités et capacités

Ce programme reproduit, à partir des six exports GPS et Evolucare, le classeur
**« Activités et capacités »** dans la présentation de la *version retenue*
(`Monkole_Activites_Capacites_01-15_Septembre_2026_Version_retenue.xlsx`).
Il fonctionne sans Internet et sans Claude, et donne toujours le même résultat pour les mêmes fichiers.

## Installation (une seule fois)

1. Python est déjà installé sur l'ordinateur (Windows). Sinon : <https://www.python.org>, en cochant *Add Python to PATH*.
2. Copier le dossier `monkole_capacites` sur l'ordinateur (par exemple dans `Documents`).
3. Au premier lancement, `LANCER_MONKOLE.bat` installe le module `openpyxl` (connexion Internet nécessaire **une seule fois**).
   Installation manuelle possible : `py -m pip install openpyxl`.

## Utilisation à chaque nouvelle période

1. Vider le dossier `entrees`, puis y déposer les **six exports** de la période :
   - `Visites_AAAAMMJJ-AAAAMMJJ.xlsx` (GPS)
   - `Visite_Evolucare_AAAAMMJJ-AAAAMMJJ.xlsx`
   - `ActesND_AAAAMMJJ-AAAAMMJJ.xlsx` (GPS)
   - `ActesND_Evolucare_AAAAMMJJ-AAAAMMJJ.xlsx`
   - `Hospi_AAAAMMJJ-AAAAMMJJ.xlsx` (GPS)
   - `Hospi_Evolucare_AAAAMMJJ-AAAAMMJJ.xlsx`
2. Double-cliquer sur **`LANCER_MONKOLE.bat`**.
3. Le classeur apparaît dans `sorties`, par exemple `Monkole_Activites_Capacites_01-21_Septembre_2026.xlsx`,
   avec un court résumé texte `..._resume.txt`.

La période est lue dans le nom des fichiers (`20260901-20260921`). Les lignes hors période sont exclues
et signalées dans « Notez bien ».

## Ce que contient le classeur

Dashboard → toutes les feuilles CSMKL2 → toutes les feuilles CHME → « Notez bien » (+ bases masquées).

| Feuille | Contenu |
|---|---|
| Dashboard | Tout sur un écran (zoom 78 %) : utilisation CSMKL2 et CHME, urgences, occupation des lits, actes ; tableau des consultations (capacité, cabinets comptés / physiques) ; courbes quotidiennes ; lecture **par semaine** ; lits par unité. Couleurs d'alerte. Chaque titre porte une explication au survol (petit triangle rouge) ; lexique complet dans « Notez bien » |
| CSMKL2 | Synthèse, lecture quotidienne, **charge individuelle avec la moyenne de visites par jour actif** (classement de la plus haute à la plus basse) |
| CHME | Occupation sur les lits réels (GPS + Evolucare), occupation par unité, cabinets de spécialistes, ambulatoire, urgences, spécialités |
| … médecins | Médecins en lignes, jours du mois en colonnes (dimanches en violet), 4 lignes par médecin |
| … jours | Date → Jour → Médecin, filtre sur la date pour voir qui a consulté un jour donné |
| … actes | Spécialité → sous-spécialité → acte (groupes « + »), GPS sur Date_V, Evolucare sur DATEHEURE |
| Notez bien | Règles, contrôles quantitatifs (écarts attendus : 0), sources, rapprochements de noms, lexique des termes du Dashboard |

Règles conservées : repère de 24 visites par médecin en cabinet et par jour (cabinet compté à partir de 2 visites),
visites isolées comptées, suivi hospitalier séparé, activités secondaires CSMKL2 = **MAISON ROSE**, dossiers sans date
d'entrée comptés sans durée inventée, produits Evolucare séparés des actes.

Règles fixées avec le directeur des opérations (septembre 2026) :

- **Moyenne / jour actif** = visites du médecin / ses jours actifs (CSMKL2, charge individuelle), sans comparaison au repère ;
- **Lits** : 104 lits réels au CHME — chirurgie 13, gynéco-obstétrique 27, médecine interne 15, néonatologie 12, pédiatrie 13,
  urgences 5, soins intensifs pédiatriques 12, réanimation 7 — d'où un taux d'occupation par unité (les lits paramétrés dans
  GPS et Evolucare comprennent des lits fictifs et ne sont pas utilisés). Les journées GPS
  et Evolucare sont additionnées : un patient n'est suivi que dans un des deux logiciels (migration en cours). Les dates de
  sortie Evolucare sont provisoires. Les séjours entrés avant le début de la période n'ont pas de date d'entrée dans les
  exports : les premiers jours sont sous-estimés, le chiffre du **dernier jour** est le plus représentatif ;
- **Cabinets physiques** : 6 à CSMKL2, 15 de spécialistes au CHME. Ne sont pas comptés dans les 15 : nutrition, kinésithérapie,
  soins infirmiers, laboratoire, imagerie, pharmacie, documents médicaux (liste modifiable). Certaines spécialités se relaient
  dans un même cabinet : le nombre de cabinets comptés est un maximum ;
- **Couleurs d'alerte** : orange sous 50 %, vert de 50 à 85 %, rouge à partir de 85 % ; rouge aussi quand les cabinets
  comptés un jour dépassent les cabinets physiques.

## Adapter les règles sans toucher au code : `Referentiel_Monkole.xlsx`

Créé au premier lancement à côté du programme. On peut y modifier :

- **Paramètres** : 24 visites par jour, seuil de 2 visites, 6 cabinets CSMKL2, 15 cabinets CHME, seuils d'alerte (50 % et 85 %) ;
- **Lits CHME** : lits réels par unité (leur total, 104, est la capacité du CHME) ;
- **Hors cabinets CHME** : spécialités qui n'occupent pas un des cabinets de spécialistes ;
- **MAISON ROSE** : UF rattachées ;
- **UF visites** : UF → spécialité (à compléter quand « Notez bien » signale une UF nouvelle) ;
- **Catégories Evo** et **Produits Evo** : harmonisation des actes Evolucare ;
- **Alias médecins** : rapprochements de noms confirmés (ex. `KALENDA FARID` → `KALENDA NUMBI FARID`).

Si un nouveau libellé apparaît dans les exports, le programme ne devine rien : il le garde tel quel et le liste
dans « Notez bien », rubrique *À valider*.

Un référentiel d'une version précédente est complété automatiquement au lancement (valeurs déjà saisies conservées).

## Vérification faite sur la période du 1er au 15 septembre 2026

À partir des six exports du 01-15/09, le classeur de la première version a été comparé cellule par cellule à la version
retenue : médecins et jours CSMKL2 et toutes les lignes d'actes sont **identiques** (Dashboard, CSMKL2 et CHME ont depuis
été remaniés à la demande du directeur des opérations). Différences voulues et limitées :

- le titre « MS » est retiré de tous les noms (la version retenue le gardait pour 3 intervenants seulement) ;
- l'ordre de deux actes **à égalité** de volume suit l'ordre alphabétique (il était arbitraire dans la version retenue) ;
- un acte Evolucare sans libellé est affiché « (acte sans libellé) » au lieu d'une case vide ;
- les chiffres sont enregistrés comme valeurs (lisibles aussi sur téléphone) ; les contrôles de cohérence sont
  dans « Notez bien ».

## Partie 2 — Diagnostics et composition des familles

`LANCER_DIAGNOSTICS.bat` reproduit le classeur **« Diagnostics et composition des familles »** de la version retenue
(`Monkole_Diagnostics_Composition_Familles_01-15_Septembre_2026`) : Dashboard sur un écran (disque des 16 familles,
12 diagnostics les plus fréquents, tranches d'âge et sexe, sources ; explication de chaque terme au survol ; lexique
complet dans « Notez bien »), Composition des familles (chaque famille à 100 %,
global et par site), **Âge et sexe** (dossiers et diagnostics par tranche d'âge et par sexe, trois diagnostics les plus
fréquents par tranche, familles par âge, dix premiers diagnostics chez les femmes et chez les hommes), CSMKL2,
CSMKL2 diagnostics, CHME, CHME diagnostics, Dictionnaire, Notez bien.

Tranches d'âge : moins d'un an, 1-4, 5-14, 15-24, 25-49, 50-64, 65 ans et plus (âge au premier jour du dossier dans la
période, calculé avec la date de naissance de l'export).

Règles conservées : un diagnostic compte une fois par dossier et logiciel sur la période ; les hypothèses seules
restent à part ; récurrent = au moins deux dossiers ; pas de patients uniques entre logiciels ; lignes sans diagnostic
exploitable, sigles à clarifier et textes GPS de 50 caractères signalés.

### Le dictionnaire : `Dictionnaire_Diagnostics.xlsx`

Il contient les 1 557 formulations déjà classées dans la version retenue (texte d'origine → diagnostic regroupé →
famille, avec le statut : sans marqueur de doute, hypothèse, contexte, à clarifier…).

- **Feuille Formulations** : une ligne par formulation et par diagnostic regroupé (une phrase composite occupe
  plusieurs lignes avec le même ID). La colonne *Versions exactes* contient les textes d'origine.
- **Feuille Groupes** : code, libellé, famille et nature de chaque diagnostic regroupé.

### Nouvelles formulations (à chaque nouvelle période)

Les médecins écrivent librement : chaque période apporte des textes jamais vus. Le programme :

1. reconnaît les textes connus, y compris avec d'autres majuscules, accents ou espaces ;
2. **classe automatiquement** un texte nouveau seulement s'il se décompose entièrement en morceaux déjà connus
   (ex. « HTA / DT2 ») — ces lignes sont comptées et signalées « à valider » ;
3. met **à classer** tous les autres : ils restent hors du disque (famille « À clarifier ») et sont listés dans la feuille
   *À classer* et dans `sorties/Diagnostics_a_classer_<période>.xlsx`, avec une **suggestion pré-remplie** quand une
   expression connue est retrouvée dans le texte (suggestion non comptée tant qu'elle n'est pas validée).

Pour les intégrer : ouvrir `Diagnostics_a_classer_…xlsx`, vérifier / compléter *Code groupe* et *Statut*, copier les
lignes à la fin de la feuille *Formulations* du dictionnaire, enregistrer, relancer `LANCER_DIAGNOSTICS.bat`.
Aide possible de Claude gratuit : `PROMPT_CLAUDE_DIAGNOSTICS.md` (on n'y colle que des textes de diagnostic, sans
numéro de dossier).

Test réalisé : en retirant du dictionnaire les 118 formulations ajoutées par Astra le 15/09, le programme en classe 4
automatiquement (toutes exactes), en met 113 à classer (43 avec suggestion, 57 codes suggérés dont 3 erronés) ; une fois
les lignes complétées et copiées, il retrouve exactement les 2 440 diagnostics, 334 différents et 443 hypothèses seules.

### Vérification sur la période du 1er au 15 septembre 2026

Comparaison cellule par cellule avec la version retenue : Composition familles, CSMKL2 diagnostics, CHME diagnostics,
Dictionnaire et Groupes globaux **identiques** ; les chiffres du Dashboard, de CSMKL2 et de CHME sont les mêmes (leur
présentation a été resserrée sur un écran à la demande du directeur des opérations). « Notez bien » garde la même structure, mais les textes propres à la mise à jour
d'Astra (comparaison avec l'export 01-14) sont remplacés par les indicateurs de la période (formulations nouvelles, à classer…).
Le disque n'a plus de légende : les couleurs des familles figurent dans le tableau placé à côté.

## Avec Claude gratuit (facultatif)

Les exports n'ont pas besoin d'être envoyés à Claude. Pour faire rédiger un commentaire pour le directeur,
ouvrir le fichier `..._resume.txt` produit dans `sorties`, puis copier le contenu de `PROMPT_CLAUDE.md` suivi du résumé
dans une conversation Claude.

## Confidentialité

Les exports et les classeurs produits contiennent des numéros de dossiers : ils restent dans `entrees` et
`sorties`, qui ne sont pas versionnés (`.gitignore`). Seuls le programme, le référentiel et le dictionnaire des
formulations (textes de diagnostics sans numéro de dossier ni identité) sont conservés dans le dépôt.
