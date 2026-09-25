# Monkole — Activités et capacités (programme hors connexion)

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
| Dashboard | Visites, actes, hospitalisation, capacité, points d'attention ; lignes 1 à 3 figées |
| CSMKL2 / CHME | Synthèse du site, lecture quotidienne, charge individuelle, graphiques ; lignes 1 à 3 figées |
| … médecins | Médecins en lignes, jours du mois en colonnes (dimanches en violet), 4 lignes par médecin |
| … jours | Date → Jour → Médecin, filtre sur la date pour voir qui a consulté un jour donné |
| … actes | Spécialité → sous-spécialité → acte (groupes « + »), GPS sur Date_V, Evolucare sur DATEHEURE |
| Notez bien | Règles, contrôles quantitatifs (écarts attendus : 0), sources, rapprochements de noms, lexique |

Règles conservées : repère de 24 visites par intervenant-jour compté (à partir de 2 visites), visites isolées
comptées, suivi hospitalier séparé, activités secondaires CSMKL2 = **MAISON ROSE**, taux d'occupation GPS et
Evolucare séparés, dossiers sans date d'entrée comptés sans durée inventée, produits Evolucare séparés des actes.

## Adapter les règles sans toucher au code : `Referentiel_Monkole.xlsx`

Créé au premier lancement à côté du programme. On peut y modifier :

- **Paramètres** : 24 visites par jour, seuil de 2 visites, 6 cabinets CSMKL2 ;
- **Lits CHME** par unité ;
- **MAISON ROSE** : UF rattachées ;
- **UF visites** : UF → spécialité (à compléter quand « Notez bien » signale une UF nouvelle) ;
- **Catégories Evo** et **Produits Evo** : harmonisation des actes Evolucare ;
- **Alias médecins** : rapprochements de noms confirmés (ex. `KALENDA FARID` → `KALENDA NUMBI FARID`).

Si un nouveau libellé apparaît dans les exports, le programme ne devine rien : il le garde tel quel et le liste
dans « Notez bien », rubrique *À valider*.

## Vérification faite sur la période du 1er au 15 septembre 2026

À partir des six exports du 01-15/09, le classeur produit a été comparé cellule par cellule à la version retenue :
Dashboard, CSMKL2, CHME, médecins et jours CSMKL2 sont **identiques**. Toutes les lignes d'actes sont identiques.
Différences voulues et limitées :

- le titre « MS » est retiré de tous les noms (la version retenue le gardait pour 3 intervenants seulement) ;
- l'ordre de deux actes **à égalité** de volume suit l'ordre alphabétique (il était arbitraire dans la version retenue) ;
- un acte Evolucare sans libellé est affiché « (acte sans libellé) » au lieu d'une case vide ;
- les chiffres sont enregistrés comme valeurs (lisibles aussi sur téléphone) ; les contrôles de cohérence sont
  dans « Notez bien ».

## Avec Claude gratuit (facultatif)

Les exports n'ont pas besoin d'être envoyés à Claude. Pour faire rédiger un commentaire pour le directeur,
ouvrir le fichier `..._resume.txt` produit dans `sorties`, puis copier le contenu de `PROMPT_CLAUDE.md` suivi du résumé
dans une conversation Claude.

## Confidentialité

Les exports et les classeurs produits contiennent des numéros de dossiers : ils restent dans `entrees` et
`sorties`, qui ne sont pas versionnés (`.gitignore`). Seul le programme est conservé dans le dépôt.
