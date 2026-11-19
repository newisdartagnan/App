"""Conversion CSV -> XLSX sans perte, pour les extractions faites directement depuis Evolucare (ou GPS).

Ouvrir un CSV dans Excel puis l'enregistrer en .xlsx abîme les données : zéros en tête des numéros de dossier supprimés,
dates interprétées au format américain, grands nombres arrondis, textes commençant par « = » pris pour des formules.
Ici, chaque cellule garde exactement la valeur du fichier CSV :
- une date n'est convertie que si toute la colonne est au format jour/mois (ou année-mois-jour), sinon elle reste en texte ;
- un nombre n'est converti que s'il se relit à l'identique (pas de zéro en tête, au plus 15 chiffres), sinon il reste en texte ;
- tout le reste est écrit en texte, tel quel.
Après l'écriture, le classeur est relu et comparé au CSV, cellule par cellule : l'écart attendu est zéro."""
import csv
import datetime as dt
import io
import os
import re
import shutil

import openpyxl
from openpyxl.cell import WriteOnlyCell
from openpyxl.cell.cell import ILLEGAL_CHARACTERS_RE

ENCODAGES = ("utf-8-sig", "cp1252", "latin-1")
SEPARATEURS = ";,\t|"
MAX_TEXTE = 32767                    # longueur maximale d'une cellule Excel
MAX_LIGNES = 1048576                 # lignes maximales d'une feuille Excel

ENTIER = re.compile(r"0|-?[1-9]\d{0,14}")
DECIMAL = re.compile(r"-?(?:0|[1-9]\d{0,14})([.,])(\d{1,9})")
# (motif, format strptime, format d'affichage Excel)
DATES = [
    (re.compile(r"\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}"), "%d/%m/%Y %H:%M:%S", "dd/mm/yyyy hh:mm:ss"),
    (re.compile(r"\d{2}/\d{2}/\d{4} \d{2}:\d{2}"), "%d/%m/%Y %H:%M", "dd/mm/yyyy hh:mm"),
    (re.compile(r"\d{2}/\d{2}/\d{4}"), "%d/%m/%Y", "dd/mm/yyyy"),
    (re.compile(r"\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}"), None, "yyyy-mm-dd hh:mm:ss"),
    (re.compile(r"\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}"), None, "yyyy-mm-dd hh:mm"),
    (re.compile(r"\d{4}-\d{2}-\d{2}"), "%Y-%m-%d", "yyyy-mm-dd"),
]


def lire_texte(chemin):
    """Contenu du fichier et encodage reconnu (UTF-8, sinon Windows-1252, sinon Latin-1)."""
    brut = open(chemin, "rb").read()
    for enc in ENCODAGES:
        try:
            return brut.decode(enc), enc
        except UnicodeDecodeError:
            continue
    return brut.decode("latin-1", errors="replace"), "latin-1"


def separateur(texte):
    echantillon = texte[:65536]
    try:
        return csv.Sniffer().sniff(echantillon, delimiters=SEPARATEURS).delimiter
    except csv.Error:
        premiere = echantillon.split("\n", 1)[0]
        return max(SEPARATEURS, key=premiere.count)


def _date(s):
    for motif, fmt, affichage in DATES:
        if motif.fullmatch(s):
            try:
                d = dt.datetime.strptime(s, fmt) if fmt else dt.datetime.fromisoformat(s.replace("T", " "))
            except ValueError:
                return None
            if 1900 <= d.year <= 9999:
                return d, affichage
            return None
    return None


def _jour_mois_inverse(s):
    """Vrai si une date jj/mm/aaaa ne peut se lire qu'au format américain (mois > 12 en 2e position)."""
    m = re.match(r"(\d{2})/(\d{2})/\d{4}", s)
    return bool(m and int(m.group(2)) > 12 and int(m.group(1)) <= 12)


def _colonnes_dates(lignes):
    """Colonnes où les dates jj/mm/aaaa sont sûres : aucune valeur de la colonne n'est au format mois/jour."""
    douteuses = set()
    for l in lignes[1:]:
        for i, v in enumerate(l):
            if v and _jour_mois_inverse(v):
                douteuses.add(i)
    return douteuses


def valeur(s, colonne_douteuse):
    """(valeur Excel, format d'affichage) pour un texte CSV ; None si cellule vide. Conversion seulement si elle est
    réversible à l'identique."""
    if s == "":
        return None, None
    if s != s.strip():
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


def attendu(cellule_csv, colonne_douteuse):
    """Valeur que la relecture du classeur doit redonner (contrôle cellule par cellule)."""
    v, _ = valeur(cellule_csv, colonne_douteuse)
    if isinstance(v, str):
        v = ILLEGAL_CHARACTERS_RE.sub("", v)
    return v


def convertir(chemin_csv, chemin_xlsx):
    """Convertit un CSV en XLSX et contrôle le résultat. Renvoie un dictionnaire de compte rendu."""
    texte, enc = lire_texte(chemin_csv)
    m = re.match(r"sep=(.)\r?\n", texte)            # première ligne « sep=; » ajoutée par certains logiciels pour Excel
    if m:
        sep, texte = m.group(1), texte[m.end():]
    else:
        sep = separateur(texte)
    lignes = list(csv.reader(io.StringIO(texte, newline=""), delimiter=sep, quotechar='"'))
    while lignes and not any(lignes[-1]):
        lignes.pop()
    cr = {"fichier": os.path.basename(chemin_csv), "encodage": enc, "separateur": {";": "point-virgule", ",": "virgule",
          "\t": "tabulation", "|": "barre verticale"}.get(sep, sep), "lignes": max(len(lignes) - 1, 0),
          "colonnes": len(lignes[0]) if lignes else 0, "dates": 0, "nombres": 0, "textes": 0, "caracteres_retires": 0,
          "textes_trop_longs": 0, "lignes_irregulieres": 0, "colonnes_dates_texte": [], "ecarts": 0, "avertissements": []}
    if len(lignes) > MAX_LIGNES:
        cr["avertissements"].append(f"{len(lignes)} lignes : au-delà de la limite d'Excel ({MAX_LIGNES}), fichier non converti.")
        cr["ecarts"] = len(lignes) - MAX_LIGNES
        return cr
    douteuses = _colonnes_dates(lignes)
    entete = lignes[0] if lignes else []
    cr["colonnes_dates_texte"] = [entete[i] if i < len(entete) else f"colonne {i + 1}" for i in sorted(douteuses)]
    larg = cr["colonnes"]

    wb = openpyxl.Workbook(write_only=True)
    nom = re.sub(r"[\\/*?:\[\]]", "_", os.path.splitext(os.path.basename(chemin_csv))[0])[:31] or "Feuille1"
    ws = wb.create_sheet(nom)
    for n, ligne in enumerate(lignes):
        if n and len(ligne) != larg:
            cr["lignes_irregulieres"] += 1
        cellules = []
        if not ligne:                        # ligne vide au milieu du fichier : gardée vide
            ws.append([None])
            continue
        for i, s in enumerate(ligne):
            v, fmt = (s or None, None) if n == 0 else valeur(s, i in douteuses)
            if isinstance(v, str):
                propre = ILLEGAL_CHARACTERS_RE.sub("", v)
                cr["caracteres_retires"] += len(v) - len(propre)
                if len(propre) > MAX_TEXTE:
                    cr["textes_trop_longs"] += 1
                v = propre
            c = WriteOnlyCell(ws, value=v)
            if isinstance(v, str):
                c.data_type = "s"            # un texte commençant par « = » reste un texte, pas une formule
                cr["textes"] += n > 0
            elif isinstance(v, dt.datetime):
                cr["dates"] += 1
            elif v is not None:
                cr["nombres"] += 1
            if fmt:
                c.number_format = fmt
            cellules.append(c)
        ws.append(cellules)
    wb.save(chemin_xlsx)

    # Contrôle : relecture et comparaison cellule par cellule
    relu = openpyxl.load_workbook(chemin_xlsx, read_only=True)
    relues = list(relu.worksheets[0].iter_rows(values_only=True))
    for n, (ligne, row) in enumerate(zip(lignes, relues)):
        row = list(row) + [None] * (len(ligne) - len(row))
        for i, s in enumerate(ligne):
            a = (s or None) if n == 0 else attendu(s, i in douteuses)
            if isinstance(a, str) and n == 0:
                a = ILLEGAL_CHARACTERS_RE.sub("", a)
            if row[i] != a:
                cr["ecarts"] += 1
    relu.close()
    if len(relues) != len(lignes):
        cr["ecarts"] += abs(len(relues) - len(lignes))
    if cr["caracteres_retires"]:
        cr["avertissements"].append(f"{cr['caracteres_retires']} caractère(s) de contrôle invisibles retirés (interdits dans un .xlsx).")
    if cr["textes_trop_longs"]:
        cr["avertissements"].append(f"{cr['textes_trop_longs']} cellule(s) de plus de {MAX_TEXTE} caractères : Excel les coupera à l'affichage.")
    if cr["lignes_irregulieres"]:
        cr["avertissements"].append(f"{cr['lignes_irregulieres']} ligne(s) n'ont pas le même nombre de colonnes que l'en-tête "
                                    "(conservées telles quelles).")
    if cr["colonnes_dates_texte"]:
        cr["avertissements"].append("Dates gardées en texte (format mois/jour détecté) : " + ", ".join(cr["colonnes_dates_texte"]) + ".")
    return cr


def convertir_dossier(dossier_csv, dossier_xlsx, dossier_traites=None):
    """Convertit tous les .csv d'un dossier. Les CSV convertis sans écart sont rangés dans dossier_traites."""
    os.makedirs(dossier_xlsx, exist_ok=True)
    comptes = []
    for nom in sorted(os.listdir(dossier_csv)):
        if not nom.lower().endswith(".csv"):
            continue
        source = os.path.join(dossier_csv, nom)
        cible = os.path.join(dossier_xlsx, os.path.splitext(nom)[0] + ".xlsx")
        cr = convertir(source, cible)
        cr["cible"] = cible
        if dossier_traites and cr["ecarts"] == 0:
            os.makedirs(dossier_traites, exist_ok=True)
            shutil.move(source, os.path.join(dossier_traites, nom))
        comptes.append(cr)
    return comptes
