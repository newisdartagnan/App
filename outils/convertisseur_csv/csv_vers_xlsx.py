"""Convertisseur CSV -> XLSX sans perte (outil autonome, utilisable pour tout fichier .csv).

Utilisation : déposer les fichiers .csv (ou .tsv, .txt) dans « entree », puis double-cliquer sur CONVERTIR_CSV_EN_XLSX.bat
(ou : python csv_vers_xlsx.py [dossier_entree] [dossier_sortie] [--texte]).
Les .xlsx sont écrits dans « sortie » sous le même nom ; chaque fichier converti sans écart est rangé dans
« entree/convertis » ; compte rendu : sortie/rapport_conversion.txt.

Pourquoi : ouvrir un CSV dans Excel puis l'enregistrer en .xlsx abîme les données (zéros en tête supprimés, dates relues au
format américain, grands nombres arrondis en notation 1,23E+15, textes commençant par « = » pris pour des formules,
accents mal décodés). Ici, chaque cellule garde exactement la valeur du fichier :
- un nombre n'est converti en nombre que s'il se relit à l'identique (pas de zéro en tête, au plus 15 chiffres) ;
- une date n'est convertie que si toute sa colonne est au format jour/mois/année (ou année-mois-jour) ;
- tout le reste est écrit en texte, tel quel ; avec --texte, toutes les cellules restent en texte.
Le classeur est ensuite relu et comparé au fichier source, cellule par cellule : l'écart attendu est zéro.
"""
import csv
import datetime as dt
import io
import os
import re
import shutil
import sys

try:
    import openpyxl
    from openpyxl.cell import WriteOnlyCell
    from openpyxl.cell.cell import ILLEGAL_CHARACTERS_RE
except ImportError:
    print("Le module openpyxl manque. Installez-le avec :  py -m pip install openpyxl")
    sys.exit(1)

ICI = os.path.dirname(os.path.abspath(__file__))
EXTENSIONS = (".csv", ".tsv", ".txt")
ENCODAGES = ("utf-8-sig", "cp1252", "latin-1")
SEPARATEURS = ";,\t|"
NOMS_SEPARATEURS = {";": "point-virgule", ",": "virgule", "\t": "tabulation", "|": "barre verticale"}
MAX_TEXTE = 32767                    # longueur maximale d'une cellule Excel
MAX_LIGNES = 1048576                 # lignes maximales d'une feuille Excel (en-tête compris)

ENTIER = re.compile(r"0|-?[1-9]\d{0,14}")
DECIMAL = re.compile(r"-?(?:0|[1-9]\d{0,14})([.,])(\d{1,9})")
# (motif, format strptime (None = ISO), format d'affichage Excel)
DATES = [
    (re.compile(r"\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}"), "%d/%m/%Y %H:%M:%S", "dd/mm/yyyy hh:mm:ss"),
    (re.compile(r"\d{2}/\d{2}/\d{4} \d{2}:\d{2}"), "%d/%m/%Y %H:%M", "dd/mm/yyyy hh:mm"),
    (re.compile(r"\d{2}/\d{2}/\d{4}"), "%d/%m/%Y", "dd/mm/yyyy"),
    (re.compile(r"\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}"), None, "yyyy-mm-dd hh:mm:ss"),
    (re.compile(r"\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}"), None, "yyyy-mm-dd hh:mm"),
    (re.compile(r"\d{4}-\d{2}-\d{2}"), "%Y-%m-%d", "yyyy-mm-dd"),
]


# ----------------------------------------------------------------------------------------------
# Lecture
# ----------------------------------------------------------------------------------------------
def lire(chemin):
    """Lignes du fichier (en-tête compris), encodage et séparateur reconnus."""
    brut = open(chemin, "rb").read()
    for enc in ENCODAGES:
        try:
            texte = brut.decode(enc)
            break
        except UnicodeDecodeError:
            continue
    m = re.match(r"sep=(.)\r?\n", texte)            # première ligne « sep=; » ajoutée par certains logiciels pour Excel
    if m:
        sep, texte = m.group(1), texte[m.end():]
    elif chemin.lower().endswith(".tsv"):
        sep = "\t"
    else:
        echantillon = texte[:65536]
        try:
            sep = csv.Sniffer().sniff(echantillon, delimiters=SEPARATEURS).delimiter
        except csv.Error:
            premiere = echantillon.split("\n", 1)[0]
            sep = max(SEPARATEURS, key=premiere.count)
    lignes = list(csv.reader(io.StringIO(texte, newline=""), delimiter=sep, quotechar='"'))
    while lignes and not any(lignes[-1]):
        lignes.pop()
    return lignes, enc, NOMS_SEPARATEURS.get(sep, sep)


# ----------------------------------------------------------------------------------------------
# Valeurs
# ----------------------------------------------------------------------------------------------
def _date(s):
    for motif, fmt, affichage in DATES:
        if motif.fullmatch(s):
            try:
                d = dt.datetime.strptime(s, fmt) if fmt else dt.datetime.fromisoformat(s.replace("T", " "))
            except ValueError:
                return None
            return (d, affichage) if 1900 <= d.year <= 9999 else None
    return None


def colonnes_mois_jour(lignes):
    """Colonnes dont une date ne peut se lire qu'au format américain (2e nombre > 12) : gardées en texte."""
    douteuses = set()
    for l in lignes[1:]:
        for i, v in enumerate(l):
            m = re.match(r"(\d{2})/(\d{2})/\d{4}", v)
            if m and int(m.group(2)) > 12 and int(m.group(1)) <= 12:
                douteuses.add(i)
    return douteuses


def valeur(s, colonne_douteuse, tout_texte=False):
    """(valeur Excel, format d'affichage) ; conversion seulement si elle est réversible à l'identique."""
    if s == "":
        return None, None
    if tout_texte or s != s.strip():
        return s, None
    if ENTIER.fullmatch(s):
        return int(s), "0"
    m = DECIMAL.fullmatch(s)
    if m and len(s.lstrip("-0").replace(",", "").replace(".", "")) <= 15:     # au plus 15 chiffres significatifs
        return float(s.replace(",", ".")), "0." + "0" * len(m.group(2))
    if not colonne_douteuse:
        d = _date(s)
        if d:
            return d
    return s, None


# ----------------------------------------------------------------------------------------------
# Écriture et contrôle
# ----------------------------------------------------------------------------------------------
def convertir(chemin_source, chemin_xlsx, tout_texte=False):
    """Convertit un fichier et contrôle le résultat cellule par cellule. Renvoie un compte rendu."""
    lignes, enc, sep = lire(chemin_source)
    cr = {"fichier": os.path.basename(chemin_source), "cible": chemin_xlsx, "encodage": enc, "separateur": sep,
          "lignes": max(len(lignes) - 1, 0), "colonnes": len(lignes[0]) if lignes else 0, "feuilles": 1, "dates": 0,
          "nombres": 0, "textes": 0, "ecarts": 0, "avertissements": []}
    douteuses = set() if tout_texte else colonnes_mois_jour(lignes)
    entete = lignes[0] if lignes else []
    if douteuses:
        cr["avertissements"].append("Dates gardées en texte (format mois/jour détecté) : "
                                    + ", ".join(entete[i] if i < len(entete) else f"colonne {i + 1}" for i in sorted(douteuses)) + ".")
    # Au-delà de la limite d'Excel : plusieurs feuilles, l'en-tête répété en haut de chacune
    par_feuille = MAX_LIGNES - 1
    blocs = [lignes[1 + k:1 + k + par_feuille] for k in range(0, max(len(lignes) - 1, 1), par_feuille)] if lignes else [[]]
    cr["feuilles"] = len(blocs)
    if len(blocs) > 1:
        cr["avertissements"].append(f"Plus de {par_feuille} lignes : réparties sur {len(blocs)} feuilles (en-tête répété).")

    retires = trop_longs = irregulieres = 0
    attendu = []                       # (feuille, lignes attendues à la relecture)
    wb = openpyxl.Workbook(write_only=True)
    base = re.sub(r"[\\/*?:\[\]]", "_", os.path.splitext(os.path.basename(chemin_source))[0])[:27] or "Feuille"
    for b, bloc in enumerate(blocs):
        ws = wb.create_sheet(base if len(blocs) == 1 else f"{base}_{b + 1}")
        vues = []
        for n, ligne in enumerate([entete] + bloc if entete else bloc):
            if n and len(ligne) != len(entete):
                irregulieres += 1
            if not ligne:                                 # ligne vide au milieu du fichier : gardée vide
                ws.append([None])
                vues.append([])
                continue
            cellules, vue = [], []
            for i, s in enumerate(ligne):
                v, fmt = (s or None, None) if n == 0 else valeur(s, i in douteuses, tout_texte)
                if isinstance(v, str):
                    propre = ILLEGAL_CHARACTERS_RE.sub("", v)
                    retires += len(v) - len(propre)
                    trop_longs += len(propre) > MAX_TEXTE
                    v = propre
                c = WriteOnlyCell(ws, value=v)
                if isinstance(v, str):
                    c.data_type = "s"                     # un texte commençant par « = » reste un texte
                    cr["textes"] += n > 0
                elif isinstance(v, dt.datetime):
                    cr["dates"] += 1
                elif v is not None:
                    cr["nombres"] += 1
                if fmt:
                    c.number_format = fmt
                cellules.append(c)
                vue.append(v)
            ws.append(cellules)
            vues.append(vue)
        attendu.append(vues)
    wb.save(chemin_xlsx)

    relu = openpyxl.load_workbook(chemin_xlsx, read_only=True)
    for feuille, vues in zip(relu.worksheets, attendu):
        relues = list(feuille.iter_rows(values_only=True))
        if len(relues) != len(vues):
            cr["ecarts"] += abs(len(relues) - len(vues))
        for vue, row in zip(vues, relues):
            row = list(row) + [None] * (len(vue) - len(row))
            cr["ecarts"] += sum(1 for i, v in enumerate(vue) if row[i] != v)
    relu.close()
    if retires:
        cr["avertissements"].append(f"{retires} caractère(s) de contrôle invisibles retirés (interdits dans un .xlsx).")
    if trop_longs:
        cr["avertissements"].append(f"{trop_longs} cellule(s) de plus de {MAX_TEXTE} caractères : Excel les coupera à l'affichage.")
    if irregulieres:
        cr["avertissements"].append(f"{irregulieres} ligne(s) n'ont pas le même nombre de colonnes que l'en-tête "
                                    "(conservées telles quelles).")
    return cr


def convertir_dossier(entree, sortie, tout_texte=False):
    os.makedirs(entree, exist_ok=True)
    os.makedirs(sortie, exist_ok=True)
    comptes = []
    for nom in sorted(os.listdir(entree)):
        if not nom.lower().endswith(EXTENSIONS):
            continue
        source = os.path.join(entree, nom)
        cr = convertir(source, os.path.join(sortie, os.path.splitext(nom)[0] + ".xlsx"), tout_texte)
        if cr["ecarts"] == 0:
            os.makedirs(os.path.join(entree, "convertis"), exist_ok=True)
            shutil.move(source, os.path.join(entree, "convertis", nom))
        comptes.append(cr)
    return comptes


def main(argv):
    args = [a for a in argv[1:] if not a.startswith("--")]
    tout_texte = "--texte" in argv
    entree = args[0] if args else os.path.join(ICI, "entree")
    sortie = args[1] if len(args) > 1 else os.path.join(ICI, "sortie")
    print("Conversion CSV -> XLSX sans perte" + (" (toutes les cellules en texte)" if tout_texte else ""))
    print(f"Dossier d'entrée : {entree}")
    try:
        comptes = convertir_dossier(entree, sortie, tout_texte)
    except PermissionError as e:
        print(f"\nERREUR : fichier ouvert dans Excel ? Fermez-le puis relancez. ({e.filename})")
        return 3
    if not comptes:
        print("\nAucun fichier .csv, .tsv ou .txt dans le dossier d'entrée. Déposez les fichiers puis relancez.")
        return 0
    lignes = [f"Conversion du {dt.datetime.now():%d/%m/%Y %H:%M}" + (" — toutes les cellules en texte" if tout_texte else ""), ""]
    for cr in comptes:
        etat = "OK, aucune perte" if cr["ecarts"] == 0 else f"ÉCART : {cr['ecarts']} cellule(s) différente(s), fichier laissé en place"
        lignes += [f"{cr['fichier']} -> {os.path.basename(cr['cible'])} : {etat}",
                   f"   {cr['lignes']} lignes, {cr['colonnes']} colonnes" + (f", {cr['feuilles']} feuilles" if cr["feuilles"] > 1 else "")
                   + f" ; encodage {cr['encodage']}, séparateur {cr['separateur']} ; {cr['dates']} dates, {cr['nombres']} nombres, "
                   f"{cr['textes']} textes"]
        lignes += [f"   ATTENTION : {a}" for a in cr["avertissements"]]
    ok = sum(1 for cr in comptes if cr["ecarts"] == 0)
    lignes += ["", f"{ok} fichier(s) sur {len(comptes)} convertis sans perte dans : {sortie}"]
    texte = "\n".join(lignes)
    print("\n" + texte)
    with open(os.path.join(sortie, "rapport_conversion.txt"), "w", encoding="utf-8") as f:
        f.write(texte + "\n")
    return 0 if ok == len(comptes) else 4


if __name__ == "__main__":
    sys.exit(main(sys.argv))
