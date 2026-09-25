"""Lecture tolérante des exports .xlsx.

Certains logiciels écrivent des fichiers qu'Excel ouvre sans broncher mais qu'openpyxl refuse
(exemple rencontré : attribut « biltinId » au lieu de « builtinId » dans la feuille de styles).
On essaie dans l'ordre :
  1. openpyxl normalement ;
  2. openpyxl sur une copie en mémoire dont la feuille de styles est réparée (le fichier d'origine n'est pas modifié) ;
  3. une lecture directe du XML des cellules, indépendante des styles (sauf pour reconnaître les dates).
"""
import datetime as dt
import io
import os
import re
import warnings
import zipfile
import xml.etree.ElementTree as ET

import openpyxl

AVERTISSEMENTS = []

NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
NS_R = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"
NS_PKG = "{http://schemas.openxmlformats.org/package/2006/relationships}"

# Formats de nombre Excel intégrés qui représentent des dates / heures
FORMATS_DATE = set(range(14, 23)) | set(range(27, 37)) | set(range(45, 48)) | set(range(50, 59))


def lignes_classeur(chemin):
    """Première feuille du classeur : liste de (n° de ligne Excel, tuple de valeurs)."""
    nom = os.path.basename(chemin)
    try:
        return _via_openpyxl(chemin)
    except Exception as erreur:  # fichier accepté par Excel mais refusé par openpyxl
        cause = f"{type(erreur).__name__} : {erreur}"
    try:
        lignes = _via_openpyxl(_styles_repares(chemin))
        AVERTISSEMENTS.append(f"{nom} : feuille de styles défectueuse ({cause}). Réparée en mémoire; fichier d'origine non modifié.")
        return lignes
    except Exception:
        pass
    lignes = _lecture_directe(chemin)
    AVERTISSEMENTS.append(f"{nom} : fichier non standard ({cause}). Données lues directement dans les cellules.")
    return lignes


def _via_openpyxl(source):
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        wb = openpyxl.load_workbook(source, read_only=True, data_only=True)
    try:
        ws = wb.worksheets[0]
        # En lecture seule, openpyxl complète les lignes vides : la position donne le n° de ligne Excel
        return [(n, row) for n, row in enumerate(ws.iter_rows(values_only=True), start=1)]
    finally:
        wb.close()


def _reparer_styles(xml):
    xml = re.sub(r"\bbiltinId=", "builtinId=", xml)
    # Blocs inutiles pour lire des valeurs et des dates : on les retire s'ils sont mal formés
    for bloc in ("cellStyles", "dxfs", "tableStyles", "colors", "extLst"):
        xml = re.sub(rf"<(?:\w+:)?{bloc}\b[^>]*/>", "", xml, flags=re.S)
        xml = re.sub(rf"<((?:\w+:)?){bloc}\b[^>]*>.*?</\1{bloc}>", "", xml, flags=re.S)
    return xml


def _styles_repares(chemin):
    sortie = io.BytesIO()
    with zipfile.ZipFile(chemin) as zi, zipfile.ZipFile(sortie, "w", zipfile.ZIP_DEFLATED) as zo:
        for info in zi.infolist():
            data = zi.read(info.filename)
            if info.filename.lower() == "xl/styles.xml":
                data = _reparer_styles(data.decode("utf-8")).encode("utf-8")
            zo.writestr(info, data)
    sortie.seek(0)
    return sortie


# ------------------------------------------------------------------------------------------------
# Lecture directe du XML
# ------------------------------------------------------------------------------------------------
def _col(ref):
    n = 0
    for ch in ref:
        if ch.isalpha():
            n = n * 26 + ord(ch.upper()) - 64
        else:
            break
    return n


def _format_est_date(code):
    code = re.sub(r'"[^"]*"|\[[^\]]*\]|\\.', "", code or "").lower()
    return bool(re.search(r"[dmyhs]", code)) and "general" not in code


def _texte_si(si):
    return "".join(t.text or "" for t in si.iter(NS + "t"))


def _lecture_directe(chemin):
    with zipfile.ZipFile(chemin) as z:
        noms = {n.lower(): n for n in z.namelist()}

        def lire(nom):
            return z.read(noms[nom.lower()]) if nom.lower() in noms else None

        wb = ET.fromstring(lire("xl/workbook.xml"))
        pr = wb.find(NS + "workbookPr")
        base = dt.datetime(1904, 1, 1) if (pr is not None and pr.get("date1904") in ("1", "true")) else dt.datetime(1899, 12, 30)
        rid = wb.find(f"{NS}sheets/{NS}sheet").get(NS_R + "id")
        cible = None
        for rel in ET.fromstring(lire("xl/_rels/workbook.xml.rels")).iter(NS_PKG + "Relationship"):
            if rel.get("Id") == rid:
                cible = rel.get("Target")
        cible = cible.lstrip("/") if cible.startswith("/") else "xl/" + cible

        partages = []
        if lire("xl/sharedStrings.xml"):
            partages = [_texte_si(si) for si in ET.fromstring(lire("xl/sharedStrings.xml")).iter(NS + "si")]

        dates = []
        styles = lire("xl/styles.xml")
        if styles:
            st = ET.fromstring(styles)
            perso = {int(f.get("numFmtId")): f.get("formatCode") for f in st.iter(NS + "numFmt")}
            xfs = st.find(NS + "cellXfs")
            for xf in (xfs if xfs is not None else []):
                i = int(xf.get("numFmtId", "0"))
                dates.append(i in FORMATS_DATE or (i in perso and _format_est_date(perso[i])))

        lignes = []
        compteur = 0
        for _, el in ET.iterparse(io.BytesIO(lire(cible))):
            if el.tag != NS + "row":
                continue
            compteur = int(el.get("r", compteur + 1))
            cellules = {}
            pos = 0
            for c in el.iter(NS + "c"):
                pos = _col(c.get("r")) if c.get("r") else pos + 1
                t = c.get("t", "n")
                v = c.find(NS + "v")
                if t == "inlineStr":
                    val = _texte_si(c.find(NS + "is")) if c.find(NS + "is") is not None else None
                elif v is None or v.text is None:
                    val = None
                elif t == "s":
                    val = partages[int(v.text)]
                elif t in ("str", "d"):
                    val = v.text
                elif t == "b":
                    val = v.text == "1"
                elif t == "e":
                    val = None
                else:
                    txt = v.text
                    val = int(txt) if re.fullmatch(r"-?\d+", txt) else float(txt)
                    s = int(c.get("s", "0"))
                    if s < len(dates) and dates[s]:
                        val = (base + dt.timedelta(days=float(txt)))
                        val = (val + dt.timedelta(microseconds=500000)).replace(microsecond=0)
                cellules[pos] = val
            el.clear()
            n = max(cellules) if cellules else 0
            while len(lignes) + 1 < compteur:
                lignes.append((len(lignes) + 1, ()))
            lignes.append((compteur, tuple(cellules.get(i) for i in range(1, n + 1))))
        return lignes
