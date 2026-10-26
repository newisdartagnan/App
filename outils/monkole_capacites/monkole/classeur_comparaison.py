"""Classeur « CSMKL2 et CHME » produit en même temps que « Activités et capacités » : lecture quotidienne et charge
individuelle de chaque activité, avec les mêmes tableaux que la feuille CSMKL2, pour comparer les sites.
Onglet CSMKL2 : activité principale. Onglet CHME : ambulatoire, puis urgences."""
from collections import Counter, defaultdict

import openpyxl

from . import visites as V
from .feuilles_synthese import fusion, tableau_charge, tableau_lecture, titre_periode
from .styles import CLAIR, TEXTE, bandeau, ecrire, mise_en_page

ONGLET_CSMKL2 = "167D8D"
ONGLET_CHME = "143348"
DER = 14


def specialites_medecins(R, site, act):
    """Spécialité de chaque médecin = celle de la majorité de ses visites sur la période."""
    c = defaultdict(Counter)
    for v in R.visites:
        if v["site"] == site and v["activite"] == act and v["medecin"]:
            c[v["medecin"]][v["specialite"]] += 1
    return {m: x.most_common(1)[0][0] for m, x in c.items()}


def _onglet(wb, nom, onglet, titre, sous_titre):
    ws = wb.create_sheet(nom)
    mise_en_page(ws, onglet, zoom=85)
    ws.page_setup.fitToHeight = 0
    ws.column_dimensions["A"].width = 30
    ws.column_dimensions["B"].width = 24 if nom == "CHME" else 12
    for c in "CDEFGHIJKLMN":
        ws.column_dimensions[c].width = 12
    bandeau(ws, 1, titre, DER, taille=16, hauteur=30)
    fusion(ws, 2, 1, DER)
    ecrire(ws, (2, 1), sous_titre, taille=10, couleur=TEXTE, fond=CLAIR)
    ws.row_dimensions[2].height = 22
    return ws


def construire(R, chemin):
    wb = openpyxl.Workbook()
    wb.remove(wb.active)
    per = titre_periode(R)
    seuil = f"cabinet compté à partir de {R.seuil} visites dans la journée"

    # CSMKL2 : activité principale (mêmes tableaux que la feuille CSMKL2 du classeur principal)
    ws = _onglet(wb, "CSMKL2", ONGLET_CSMKL2, f"CSMKL2 / ACTIVITÉ PRINCIPALE — {per}",
                 f"GPS + Evolucare • capacité = {R.cap_jour} visites par médecin en cabinet et par jour • {seuil} • "
                 f"{R.cabinets_csmkl2} cabinets physiques")
    r = tableau_lecture(ws, 4, R, "CSMKL2", V.PRINCIPALE, DER, "LECTURE QUOTIDIENNE / ACTIVITÉ PRINCIPALE",
                        f"Intervenants >{R.cap_jour}", "Cabinets toutes activités", R.cab_site["CSMKL2"], None, R.cabinets_csmkl2)
    tableau_charge(ws, r + 2, R, "CSMKL2", V.PRINCIPALE, DER, "CHARGE INDIVIDUELLE / ACTIVITÉ PRINCIPALE", f"Jours >{R.cap_jour}")

    # CHME : ambulatoire puis urgences
    capacite = (f"ambulatoire : créneaux de rendez-vous de chaque médecin (à défaut : médiane de la spécialité, puis {R.cap_jour})"
                if R.horaire else f"ambulatoire : {R.cap_jour} visites par médecin en cabinet et par jour")
    ws = _onglet(wb, "CHME", ONGLET_CHME, f"CHME / ACTIVITÉ AMBULATOIRE ET URGENCES — {per}",
                 f"GPS + Evolucare • capacité {capacite} ; urgences : {R.cap_jour} visites par médecin et par jour • {seuil} • "
                 f"{R.cabinets_chme} cabinets de spécialistes")
    lib_depasse = "Intervenants >\ncapacité" if R.horaire else f"Intervenants >{R.cap_jour}"
    lib_audela = "Jours au-delà\ncapacité" if R.horaire else f"Jours >{R.cap_jour}"
    r = tableau_lecture(ws, 4, R, "CHME", V.AMBULATOIRE, DER, "LECTURE QUOTIDIENNE / ACTIVITÉ AMBULATOIRE", lib_depasse,
                        f"Cabinets spécialistes\n(sur {R.cabinets_chme})", R.cab_chme_spec, R.cabinets_chme, None)
    r = tableau_charge(ws, r + 2, R, "CHME", V.AMBULATOIRE, DER, "CHARGE INDIVIDUELLE / ACTIVITÉ AMBULATOIRE", lib_audela,
                       specialites=specialites_medecins(R, "CHME", V.AMBULATOIRE))
    r = tableau_lecture(ws, r + 2, R, "CHME", V.URGENCES, DER, "LECTURE QUOTIDIENNE / URGENCES", f"Intervenants >{R.cap_jour}",
                        "Cabinets toutes activités", R.cab_site["CHME"], None, None)
    tableau_charge(ws, r + 2, R, "CHME", V.URGENCES, DER, "CHARGE INDIVIDUELLE / URGENCES", f"Jours >{R.cap_jour}")
    wb.save(chemin)
    return wb
