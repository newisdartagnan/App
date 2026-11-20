# Convertisseur CSV → XLSX sans perte

Outil autonome, indépendant des statistiques Monkole : il transforme **n'importe quel fichier .csv** (ou .tsv, .txt) en
classeur Excel **.xlsx** sans perdre la moindre donnée. Il fonctionne sans Internet (sauf la première installation
d'`openpyxl`) et peut être copié seul sur n'importe quel ordinateur Windows qui a Python.

## Pourquoi ne pas passer par Excel

Ouvrir un .csv dans Excel puis l'enregistrer en .xlsx abîme les données sans prévenir :

| Dans le .csv | Après Excel | Avec ce convertisseur |
|---|---|---|
| `000123` (numéro de dossier) | `123` | `000123` |
| `1234567890123456789` | `1,23457E+18` (chiffres perdus) | `1234567890123456789` |
| `05/09/2026` | parfois lu 9 mai (format américain) | 5 septembre 2026 |
| `=SOMME(A1)` dans un texte | transformé en formule | reste du texte |
| accents (`Fièvre`, `é`) | parfois `FiÃ¨vre` | `Fièvre` |
| `12,50` | selon les réglages de Windows | `12,50` (nombre 12,5 affiché avec 2 décimales) |

## Utilisation

1. Déposer les fichiers dans le dossier **`entree`**.
2. Double-cliquer sur **`CONVERTIR_CSV_EN_XLSX.bat`**.
3. Les .xlsx arrivent dans **`sortie`**, sous le même nom. Chaque fichier converti sans écart est rangé dans
   `entree/convertis` ; le compte rendu est dans `sortie/rapport_conversion.txt`.

Variante : **`CONVERTIR_CSV_EN_XLSX_TOUT_EN_TEXTE.bat`** écrit toutes les cellules en texte, exactement comme dans le
fichier (aucun nombre ni aucune date reconnus) : utile pour un fichier de codes ou d'identifiants.

En ligne de commande : `py csv_vers_xlsx.py [dossier_entree] [dossier_sortie] [--texte]`.

## Ce que fait la conversion

- **Encodage** reconnu automatiquement (UTF-8 avec ou sans BOM, Windows-1252, Latin-1) et **séparateur** (`;`, `,`,
  tabulation, `|`, ou ligne `sep=;` en tête de fichier).
- Les cellules entre guillemets qui contiennent un séparateur ou un retour à la ligne restent entières.
- Un **nombre** n'est converti en nombre que s'il se relit exactement : pas de zéro en tête, au plus 15 chiffres.
  Sinon il reste en texte.
- Une **date** n'est convertie que si toute sa colonne est au format jour/mois/année (ou année-mois-jour). Une colonne où
  apparaît une date au format mois/jour reste entièrement en texte, et le rapport le signale.
- Un texte qui commence par `=` reste un texte.
- Au-delà de 1 048 575 lignes (limite d'Excel), le fichier est réparti sur plusieurs feuilles, l'en-tête répété.

## Contrôle « aucune perte »

Après l'écriture, le classeur est **relu et comparé au fichier source, cellule par cellule**. Le rapport donne pour
chaque fichier : lignes, colonnes, encodage, séparateur, nombre de dates, de nombres et de textes, et le résultat du
contrôle. En cas d'écart, le fichier source reste dans `entree` et le rapport l'explique.

Seules modifications possibles, toujours signalées dans le rapport : les caractères de contrôle invisibles (interdits
dans un .xlsx) sont retirés, et une cellule de plus de 32 767 caractères sera coupée à l'affichage par Excel.
