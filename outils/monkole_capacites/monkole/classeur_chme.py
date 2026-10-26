"""Classeur CHME produit en même temps que « Activités et capacités » : lecture quotidienne et charge individuelle
de l'activité ambulatoire du CHME, présentées comme les tableaux de la feuille CSMKL2."""
from collections import Counter, defaultdict

import openpyxl

from . import visites as V
from .feuilles_synthese import fusion, tableau_charge, tableau_lecture, titre_periode
from .styles import CLAIR, TEXTE, bandeau, ecrire, mise_en_page

ONGLET = "143348"


def specialites_medecins(R, site="CHME", act=V.AMBULATOIRE):
    """Spécialité de chaque médecin = celle de la majorité de ses visites sur la période."""
    c = defaultdict(Counter)
    for v in R.visites:
        if v["site"] == site and v["activite"] == act and v["medecin"]:
            c[v["medecin"]][v["specialite"]] += 1
    return {m: x.most_common(1)[0][0] for m, x in c.items()}


def construire(R, chemin):
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "CHME ambulatoire"
    mise_en_page(ws, ONGLET, zoom=85)
    der = 14
    ws.column_dimensions["A"].width = 30
    ws.column_dimensions["B"].width = 24
    for c in "CDEFGHIJKLMN":
        ws.column_dimensions[c].width = 12
    capacite = (f"créneaux de rendez-vous de chaque médecin (à défaut : médiane de la spécialité, puis {R.cap_jour})" if R.horaire
                else f"{R.cap_jour} visites par médecin en cabinet et par jour")
    bandeau(ws, 1, f"CHME / ACTIVITÉ AMBULATOIRE — {titre_periode(R)}", der, taille=16, hauteur=30)
    fusion(ws, 2, 1, der)
    ecrire(ws, (2, 1), f"GPS + Evolucare • capacité = {capacite} • cabinet compté à partir de {R.seuil} visites dans la journée • "
                       f"{R.cabinets_chme} cabinets de spécialistes", taille=10, couleur=TEXTE, fond=CLAIR)
    ws.row_dimensions[2].height = 22
    lib_depasse = "Intervenants >\ncapacité" if R.horaire else f"Intervenants >{R.cap_jour}"
    lib_audela = "Jours au-delà\ncapacité" if R.horaire else f"Jours >{R.cap_jour}"
    r = tableau_lecture(ws, 4, R, "CHME", V.AMBULATOIRE, der, "LECTURE QUOTIDIENNE / ACTIVITÉ AMBULATOIRE", lib_depasse,
                        f"Cabinets spécialistes\n(sur {R.cabinets_chme})", R.cab_chme_spec, R.cabinets_chme, None)
    tableau_charge(ws, r + 2, R, "CHME", V.AMBULATOIRE, der, "CHARGE INDIVIDUELLE / ACTIVITÉ AMBULATOIRE", lib_audela,
                   specialites=specialites_medecins(R))
    ws.page_setup.fitToHeight = 0
    wb.save(chemin)
    return wb
