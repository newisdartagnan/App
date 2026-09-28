"""Assemblage du classeur des diagnostics dans l'ordre de la version retenue."""
import openpyxl

from . import diag_classeur as DC
from . import diag_notez as DN
from .diag_calculs import SANS_SITE

ORDRE = ["Dashboard", "Composition familles", "Âge et sexe", "CSMKL2", "CSMKL2 diagnostics", "CHME", "CHME diagnostics", "Dictionnaire",
         "À classer", "Notez bien", "Groupes globaux", "Base sources", "Base mentions", "Dossiers source", "Calculs"]


def construire(R, chemin):
    wb = openpyxl.Workbook()
    wb.remove(wb.active)
    DN.calculs(wb, R)
    _, pos_dico = DC.dictionnaire(wb, R)
    _, pos_compo = DC.composition(wb, R, pos_dico)
    G = R.global_
    DC.synthese(wb, R, "Dashboard", G, "MONKOLE / DIAGNOSTICS REGROUPÉS PAR SITE", DC.TEAL,
                [(s, len(R.sites[s].dossiers), R.sites[s].total) for s in ("CSMKL2", "CHME", SANS_SITE)], pos_compo, pos_dico)
    DC.feuille_age_sexe(wb, R, DC.TEAL)
    for site, onglet in (("CSMKL2", "3D91CC"), ("CHME", "264E70")):
        P = R.sites[site]
        par_lg = [(lg, len(R.tableau[(site, lg)].dossiers), R.tableau[(site, lg)].total) for lg in ("GPS", "Evolucare")]
        DC.synthese(wb, R, site, P, f"{site} / DIAGNOSTICS REGROUPÉS", onglet, par_lg, pos_compo, pos_dico)
        DC.diagnostics_site(wb, R, f"{site} diagnostics", P, f"{site} / DIAGNOSTICS REGROUPÉS", onglet, pos_dico)
    DC.diagnostics_site(wb, R, "Groupes globaux", G, "MONKOLE / DIAGNOSTICS REGROUPÉS", DC.TEAL, pos_dico, masquer=True)
    DN.a_classer(wb, R)
    DN.notez_bien(wb, R)
    DN.bases(wb, R)
    wb._sheets = [wb[n] for n in ORDRE if n in wb.sheetnames]
    wb.active = 0
    for ws in wb.worksheets:
        ws.sheet_view.tabSelected = ws.title == "Dashboard"
    wb.save(chemin)
