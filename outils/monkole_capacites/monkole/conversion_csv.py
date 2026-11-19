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

from .texte import sans_accents

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


def lire_csv(chemin_csv):
    """Lignes du CSV (en-tête compris), encodage et séparateur reconnus."""
    texte, enc = lire_texte(chemin_csv)
    m = re.match(r"sep=(.)\r?\n", texte)            # première ligne « sep=; » ajoutée par certains logiciels pour Excel
    if m:
        sep, texte = m.group(1), texte[m.end():]
    else:
        sep = separateur(texte)
    lignes = list(csv.reader(io.StringIO(texte, newline=""), delimiter=sep, quotechar='"'))
    while lignes and not any(lignes[-1]):
        lignes.pop()
    return lignes, enc, {";": "point-virgule", ",": "virgule", "\t": "tabulation", "|": "barre verticale"}.get(sep, sep)


def _compte_rendu(fichier, lignes, enc, sep):
    return {"fichier": fichier, "encodage": enc, "separateur": sep, "lignes": max(len(lignes) - 1, 0),
            "colonnes": len(lignes[0]) if lignes else 0, "dates": 0, "nombres": 0, "textes": 0, "caracteres_retires": 0,
            "textes_trop_longs": 0, "lignes_irregulieres": 0, "colonnes_dates_texte": [], "ecarts": 0, "avertissements": []}


def convertir(chemin_csv, chemin_xlsx):
    """Convertit un CSV en XLSX et contrôle le résultat. Renvoie un dictionnaire de compte rendu."""
    lignes, enc, sep = lire_csv(chemin_csv)
    cr = _compte_rendu(os.path.basename(chemin_csv), lignes, enc, sep)
    ecrire_controler(lignes, chemin_xlsx, os.path.splitext(os.path.basename(chemin_csv))[0], cr)
    return cr


def ecrire_controler(lignes, chemin_xlsx, nom_feuille, cr):
    """Écrit les lignes (en-tête compris) dans un .xlsx, le relit et compte les cellules différentes (cr["ecarts"])."""
    if len(lignes) > MAX_LIGNES:
        cr["avertissements"].append(f"{len(lignes)} lignes : au-delà de la limite d'Excel ({MAX_LIGNES}), fichier non converti.")
        cr["ecarts"] = len(lignes) - MAX_LIGNES
        return cr
    douteuses = _colonnes_dates(lignes)
    entete = lignes[0] if lignes else []
    cr["colonnes_dates_texte"] = [entete[i] if i < len(entete) else f"colonne {i + 1}" for i in sorted(douteuses)]
    larg = len(entete)

    wb = openpyxl.Workbook(write_only=True)
    ws = wb.create_sheet(re.sub(r"[\\/*?:\[\]]", "_", nom_feuille)[:31] or "Feuille1")
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


# Extractions brutes -> fichier attendu par les programmes : (code, motif du nom brut, nom produit). Le nom produit ne
# porte pas de période : l'utilisateur ajoute lui-même « _AAAAMMJJ-AAAAMMJJ » avant de lancer les statistiques.
CIBLES = [
    ("diag_evo", r"^actes_diagnostic_(webgyn|webcons|weburg)|^diagnostic_evolucare", "Diagnostic_Evolucare"),
    ("actes_evo", r"^acte_a_facturer|^actesnd_evolucare", "ActesND_Evolucare"),
    ("visites_evo", r"^visite_patient|^visite_evolucare", "Visite_Evolucare"),
    ("hospi_evo", r"^patient_hospitalise|^hospi_evolucare", "Hospi_Evolucare"),
]
REGROUPES = {"diag_evo"}           # plusieurs fichiers bruts (webgyn, webcons, weburg) -> un seul classeur
SOURCE = "Fichier_source"


def colonnes_attendues():
    from .diag_calculs import EXPORTS as DIAG
    from .lecture import EXPORTS
    return {"diag_evo": DIAG["Evolucare"][1], "actes_evo": EXPORTS["actes_evo"][1], "visites_evo": EXPORTS["visites_evo"][1],
            "hospi_evo": EXPORTS["hospi_evo"][1]}


def _nom_normalise(nom):
    base = re.sub(r"^[0-9a-f]{8}-", "", os.path.splitext(nom)[0], flags=re.I)
    return re.sub(r"\s+", "_", sans_accents(base).lower())


def cible(nom):
    n = _nom_normalise(nom)
    for code, motif, prefixe in CIBLES:
        m = re.search(motif, n)
        if m:
            return code, prefixe, (m.group(1) if m.lastindex else None)
    return None


def _cle(t):
    return re.sub(r"\s+", " ", sans_accents(str(t or "")).strip().lower())


def _renommer(entete, prefixe, renommages):
    """Renomme les colonnes d'après la feuille « Colonnes CSV » du référentiel."""
    regles = {_cle(src): dst for fichier, src, dst in renommages if _cle(fichier) in (_cle(prefixe), "") and src and dst}
    return [regles.get(_cle(h), h) for h in entete]


def _assembler(sources, prefixe, renommages):
    """Lignes du classeur produit à partir d'un ou plusieurs CSV ; plusieurs fichiers : colonnes alignées par nom et
    colonne Fichier_source ajoutée (webgyn, webcons, weburg)."""
    if len(sources) == 1 and not sources[0][1]:
        lignes = sources[0][2]
        return [_renommer(lignes[0], prefixe, renommages)] + lignes[1:] if lignes else lignes, []
    entete, avert = [], []
    for _, _, lignes, _ in sources:
        for h in _renommer(lignes[0] if lignes else [], prefixe, renommages):
            if _cle(h) not in [_cle(x) for x in entete]:
                entete.append(h)
    sortie = [entete + [SOURCE]]
    for nom, etiquette, lignes, _ in sources:
        if not lignes:
            continue
        propre = _renommer(lignes[0], prefixe, renommages)
        cles = [_cle(h) for h in propre]
        if len(set(cles)) != len(cles):
            avert.append(f"{nom} : noms de colonnes en double, alignement par position.")
        if [_cle(h) for h in entete] != cles:
            avert.append(f"{nom} : colonnes différentes des autres fichiers, alignées par nom.")
        pos = [cles.index(_cle(h)) if _cle(h) in cles else None for h in entete]
        for l in lignes[1:]:
            if not l:
                continue
            sortie.append([(l[p] if p is not None and p < len(l) else "") for p in pos] + [etiquette or nom])
    return sortie, avert


def convertir_dossier(dossier_csv, dossier_xlsx, dossier_traites=None, renommages=()):
    """Convertit les .csv d'un dossier. Les extractions brutes d'Evolucare sont renommées comme les attendent les
    programmes (et les trois fichiers de diagnostics regroupés) ; les autres .csv gardent leur nom. Les CSV convertis
    sans écart ni colonne manquante sont rangés dans dossier_traites."""
    os.makedirs(dossier_xlsx, exist_ok=True)
    attendues = colonnes_attendues()
    groupes, autres = {}, []
    for nom in sorted(os.listdir(dossier_csv)):
        if not nom.lower().endswith(".csv"):
            continue
        c = cible(nom)
        if c:
            groupes.setdefault(c[0], (c[1], []))[1].append((nom, c[2]))
        else:
            autres.append(nom)

    comptes = []
    for code, (prefixe, fichiers) in sorted(groupes.items()):
        sources = []
        for nom, etiquette in fichiers:
            lignes, enc, sep = lire_csv(os.path.join(dossier_csv, nom))
            sources.append((nom, etiquette, lignes, (enc, sep)))
        noms = [s[0] for s in sources]
        if len(sources) > 1 and code not in REGROUPES:
            comptes.append({"fichier": ", ".join(noms), "ecarts": 1, "avertissements": [
                f"Plusieurs fichiers pour {prefixe} : laissez-en un seul dans entrees_csv."], "lignes": 0, "colonnes": 0,
                "encodage": "", "separateur": "", "dates": 0, "nombres": 0, "textes": 0})
            continue
        lignes, avert = _assembler(sources, prefixe, renommages)
        attendu_n = sum(sum(1 for l in s[2][1:] if l) for s in sources)
        if len(lignes) - 1 != attendu_n and (len(sources) > 1 or sources[0][1]):
            avert.append(f"Regroupement : {len(lignes) - 1} lignes produites pour {attendu_n} lignes lues.")
        cible_xlsx = os.path.join(dossier_xlsx, f"{prefixe}.xlsx")
        encs = sorted({s[3][0] for s in sources})
        seps = sorted({s[3][1] for s in sources})
        cr = _compte_rendu(" + ".join(noms), lignes, ", ".join(encs), ", ".join(seps))
        cr["avertissements"] += avert
        ecrire_controler(lignes, cible_xlsx, prefixe, cr)
        entete = [_cle(h) for h in (lignes[0] if lignes else [])]
        cr["manquantes"] = [c for c in attendues[code] if _cle(c) not in entete]
        if cr["manquantes"]:
            cr["avertissements"].append("Colonnes attendues absentes : " + ", ".join(cr["manquantes"]) + ". Colonnes du fichier : "
                                        + ", ".join(str(h) for h in (lignes[0] if lignes else [])) + ". À renommer dans la "
                                        "feuille « Colonnes CSV » du référentiel.")
        anciens = [f for f in os.listdir(dossier_xlsx) if f.lower().startswith(prefixe.lower() + "_")
                   and f.lower().endswith(".xlsx") and os.path.join(dossier_xlsx, f) != cible_xlsx]
        if anciens:
            cr["avertissements"].append("Autre(s) fichier(s) du même type dans entrees, à retirer : " + ", ".join(anciens) + ".")
        cr["cible"] = cible_xlsx
        _ranger(cr, [os.path.join(dossier_csv, n) for n in noms], dossier_traites)
        comptes.append(cr)

    for nom in autres:                   # autres CSV (GPS, déjà renommés...) : même nom en .xlsx
        source = os.path.join(dossier_csv, nom)
        cible_xlsx = os.path.join(dossier_xlsx, os.path.splitext(nom)[0] + ".xlsx")
        cr = convertir(source, cible_xlsx)
        cr["cible"] = cible_xlsx
        cr["manquantes"] = []
        _ranger(cr, [source], dossier_traites)
        comptes.append(cr)
    return comptes


def _ranger(cr, sources, dossier_traites):
    if dossier_traites and cr["ecarts"] == 0 and not cr.get("manquantes"):
        os.makedirs(dossier_traites, exist_ok=True)
        for src in sources:
            shutil.move(src, os.path.join(dossier_traites, os.path.basename(src)))
