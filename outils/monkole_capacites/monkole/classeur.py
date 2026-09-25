"""Assemblage du classeur « Activités et capacités » dans l'ordre retenu."""
import openpyxl

from . import feuilles_bases as FB
from . import feuilles_detail as FD
from . import feuilles_synthese as FS
from .notez_bien import feuille_notez_bien

ONGLET_DASH = "008797"
ONGLET_CSMKL2 = "167D8D"
ONGLET_CSMKL2_DETAIL = "087F8C"
ONGLET_CHME = "143348"
ONGLET_CHME_DETAIL = "123047"
ONGLET_REGLES = "657A88"

ORDRE = ["Dashboard", "CSMKL2", "CSMKL2 médecins", "CSMKL2 jours", "CSMKL2 actes", "CHME", "CHME médecins", "CHME jours",
         "CHME actes", "Notez bien", "_Paramètres", "Base visites", "Base hospitalisations", "Dossiers hospitaliers", "Base actes",
         "_Médecins jour", "_Jours", "_Hospi jour"]


def construire(R, chemin):
    wb = openpyxl.Workbook()
    wb.remove(wb.active)
    # Bases masquées d'abord : les graphiques y puisent leurs séries
    FB.parametres(wb, R)
    FB.base_visites(wb, R)
    FB.base_hospitalisations(wb, R)
    FB.dossiers_hospitaliers(wb, R)
    FB.base_actes(wb, R)
    FB.medecins_jour(wb, R)
    FB.jours(wb, R)
    FB.hospi_jour(wb, R)
    # Détail par site
    FD.feuille_medecins(wb, R, "CSMKL2", ONGLET_CSMKL2_DETAIL)
    FD.feuille_jours_csmkl2(wb, R, ONGLET_CSMKL2_DETAIL)
    _, pos_cs, arbre_cs = FD.feuille_actes(wb, R, "CSMKL2", ONGLET_CSMKL2)
    FD.feuille_medecins(wb, R, "CHME", ONGLET_CHME_DETAIL)
    FD.feuille_jours_chme(wb, R, ONGLET_CHME_DETAIL)
    _, pos_ch, arbre_ch = FD.feuille_actes(wb, R, "CHME", ONGLET_CHME)
    # Synthèses
    FS.feuille_csmkl2(wb, R, pos_cs, arbre_cs, ONGLET_CSMKL2)
    FS.feuille_chme(wb, R, ONGLET_CHME)
    FS.feuille_dashboard(wb, R, pos_ch, arbre_ch, arbre_cs, ONGLET_DASH)
    feuille_notez_bien(wb, R, {"arbres": {"CSMKL2": arbre_cs, "CHME": arbre_ch}}, ONGLET_REGLES)
    wb._sheets = [wb[n] for n in ORDRE]
    wb.active = 0
    for ws in wb.worksheets:
        ws.sheet_view.tabSelected = ws.title == "Dashboard"
    wb.calculation.fullCalcOnLoad = True
    wb.save(chemin)
    return wb
