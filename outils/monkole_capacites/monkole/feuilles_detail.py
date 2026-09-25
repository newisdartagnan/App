"""Feuilles de détail par site : médecins par jour, jours par médecin, actes."""
import calendar
import datetime as dt
from collections import defaultdict

from openpyxl.utils import get_column_letter as L

from . import visites as V
from .calculs import SANS_MEDECIN, arbre_actes
from .styles import (ALERTE_F, ALERTE_T, BLANC, BLOC, CLAIR, DATE, DEC, DIM, DIM_TETE, FILET, HORS, NAVY, NB, NB_TIRET, NUIT,
                     ORANGE, TEAL, TEAL2, TETE, TEXTE, ZEBRE, ecrire, lien, mise_en_page, note, style)
from .texte import JOURS_COURTS, MOIS, nom_jour

CLASSES = [("cons", V.CONSULTATION), ("avant", V.AVANT), ("apres", V.APRES)]


def libelle_periode(R, tiret="–"):
    d, f = R.debut, R.fin
    if d.month == f.month:
        return f"{d.day:02d}{tiret}{f.day:02d} {MOIS[d.month - 1]}"
    return f"{d.day:02d}/{d.month:02d}{tiret}{f.day:02d}/{f.month:02d}"


def jours_du_mois(R):
    premier = dt.datetime(R.debut.year, R.debut.month, 1)
    dernier_mois = dt.datetime(R.fin.year, R.fin.month, calendar.monthrange(R.fin.year, R.fin.month)[1])
    n = (dernier_mois - premier).days + 1
    return [premier + dt.timedelta(days=i) for i in range(n)]


def tri_medecins(totaux):
    """Total décroissant, puis nom."""
    return sorted(totaux, key=lambda m: (-totaux[m], m))


# ----------------------------------------------------------------------------------------------
# MÉDECINS PAR JOUR (médecins en lignes, jours du mois en colonnes)
# ----------------------------------------------------------------------------------------------
class GrilleMedecins:
    def __init__(self, ws, R):
        self.ws, self.R = ws, R
        self.jours = jours_du_mois(R)
        self.c0 = 3
        self.cT = self.c0 + len(self.jours)
        self.cG, self.cE, self.cJ, self.cMin, self.cMax = self.cT + 1, self.cT + 2, self.cT + 3, self.cT + 4, self.cT + 5
        self.der = self.cMax
        self.periode = set(R.jours)
        self.ligne = 5

    def _fond_jour(self, j, defaut):
        if j not in self.periode:
            return HORS
        if j.weekday() == 6:
            return DIM
        return defaut

    def entetes(self, site):
        ws, R = self.ws, self.R
        mois = MOIS[R.debut.month - 1].upper()
        ws.merge_cells(start_row=1, start_column=1, end_row=1, end_column=self.der)
        ecrire(ws, "A1", f"ACTIVITÉ DES MÉDECINS • {site} | {mois} {R.debut.year}", taille=15, gras=True, couleur="FFFFFF",
               fond=NUIT, h=None, wrap=None, police="Aptos")
        ws.row_dimensions[1].height = 30
        ws.merge_cells(start_row=2, start_column=1, end_row=2, end_column=self.der)
        ecrire(ws, "A2", f"{libelle_periode(R)} • Jours en colonnes • 3 classes de visite + total par jour • GPS + Evolucare • Dimanches en violet",
               taille=11, couleur=NUIT, fond=BLANC, police="Aptos", h=None, wrap=None)
        ws.row_dimensions[2].height = 28
        for r in (3, 4):
            for c in range(1, self.der + 1):
                ecrire(ws, (r, c), taille=11, gras=True, couleur="FFFFFF", fond=NUIT, h="center", police="Aptos")
        for i, j in enumerate(self.jours):
            c = self.c0 + i
            fond = DIM_TETE if j.weekday() == 6 else NUIT
            ecrire(ws, (3, c), JOURS_COURTS[j.weekday()], taille=11, gras=True, couleur="FFFFFF", fond=fond, h="center", police="Aptos")
            ecrire(ws, (4, c), j.day, taille=11, gras=True, couleur="FFFFFF", fond=fond, h="center", police="Aptos", fmt="00")
        ws.cell(row=4, column=1, value="Médecin / activité")
        ws.cell(row=4, column=2, value="Classe de visite")
        for c, t in ((self.cT, "Total\npériode"), (self.cG, "GPS"), (self.cE, "Evolucare"), (self.cJ, "Jours avec\nvisites"),
                     (self.cMin, "Minimum\nen un jour*"), (self.cMax, "Maximum\nen un jour")):
            ws.cell(row=4, column=c, value=t)
        ws.row_dimensions[3].height = 22
        ws.row_dimensions[4].height = 38
        ws.column_dimensions["A"].width = 31
        ws.column_dimensions["B"].width = 32
        for i in range(len(self.jours)):
            ws.column_dimensions[L(self.c0 + i)].width = 5
        for c in range(self.cT, self.der + 1):
            ws.column_dimensions[L(c)].width = 13

    def _cellule_jour(self, r, j, valeur, gras, couleur, fond_defaut):
        fond = self._fond_jour(j, fond_defaut)
        c = ecrire(self.ws, (r, self.c0 + self.jours.index(j)), valeur if j in self.periode else None,
                   taille=11, gras=gras, couleur=("FFFFFF" if (gras and fond == HORS and fond_defaut in (TEAL2, NUIT)) else couleur),
                   fond=fond, fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
        return c

    def section(self, texte):
        ws = self.ws
        ws.merge_cells(start_row=self.ligne, start_column=1, end_row=self.ligne, end_column=self.der)
        ecrire(ws, (self.ligne, 1), texte, taille=11, gras=True, couleur="FFFFFF", fond=NUIT, h=None, v=None, wrap=None, police="Aptos")
        ws.row_dimensions[self.ligne].height = 26
        self.ligne += 1

    def vide(self, style_jours=True):
        if style_jours:
            ws, r = self.ws, self.ligne
            ecrire(ws, (r, 1), taille=11, couleur=NUIT, h=None, wrap=None, police="Aptos")
            ecrire(ws, (r, 2), taille=11, couleur=NUIT, h=None, wrap=None, police="Aptos")
            for j in self.jours:
                fond = self._fond_jour(j, None)
                ecrire(ws, (r, self.c0 + self.jours.index(j)), taille=11, couleur=NUIT, fond=fond, fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
            for c in range(self.cT, self.der + 1):
                ecrire(ws, (r, c), taille=11, couleur=NUIT, fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
        self.ligne += 1

    def ligne_cabinets(self, libelle, texte_b, par_jour):
        ws, r = self.ws, self.ligne
        ecrire(ws, (r, 1), libelle, taille=11, gras=True, couleur=NUIT, fond=TETE, h=None, police="Aptos")
        ecrire(ws, (r, 2), texte_b, taille=11, gras=True, couleur=NUIT, fond=TETE, h=None, police="Aptos")
        for j in self.jours:
            self._cellule_jour(r, j, par_jour.get(j, 0), True, NUIT, TETE)
        ecrire(ws, (r, self.cT), sum(par_jour.get(j, 0) for j in self.R.jours), taille=11, gras=True, couleur=NUIT, fond=TETE,
               fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
        for c in range(self.cG, self.der + 1):
            ecrire(ws, (r, c), taille=11, gras=True, couleur=NUIT, fond=TETE, fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
        ws.row_dimensions[r].height = 43
        self.ligne += 1

    def bloc(self, nom, comptes, total_fond=TEAL2, a_sous_cab=False, nom_gras=True):
        """comptes : dict jour -> {'cons','avant','apres','total','gps','evo'}."""
        ws, R = self.ws, self.R
        for i, (k, lib) in enumerate(CLASSES):
            r = self.ligne
            ecrire(ws, (r, 1), nom if (i == 0 and not a_sous_cab) else None, taille=11, gras=True, couleur=NUIT, fond=BLOC, h=None, police="Aptos")
            ecrire(ws, (r, 2), lib, taille=11, couleur=NUIT, fond=BLOC, h=None, wrap=None, police="Aptos")
            for j in self.jours:
                self._cellule_jour(r, j, comptes.get(j, {}).get(k, 0), False, NUIT, "FFFFFF")
            tot = sum(comptes.get(j, {}).get(k, 0) for j in R.jours)
            vals = {self.cT: tot, self.cG: comptes.get("_gps", {}).get(k, 0), self.cE: comptes.get("_evo", {}).get(k, 0)}
            for c in range(self.cT, self.der + 1):
                ecrire(ws, (r, c), vals.get(c), taille=11, couleur=NUIT, fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
            ws.row_dimensions[r].height = 23
            self.ligne += 1
        r = self.ligne
        ecrire(ws, (r, 1), None, taille=11, couleur=NUIT, fond=(None if total_fond == NUIT else BLOC), h=None, wrap=None, police="Aptos")
        ecrire(ws, (r, 2), "Total par jour", taille=11, gras=True, couleur="FFFFFF", fond=total_fond, h=None, wrap=None, police="Aptos")
        jours_tot = [comptes.get(j, {}).get("total", 0) for j in R.jours]
        for j in self.jours:
            self._cellule_jour(r, j, comptes.get(j, {}).get("total", 0), True, "FFFFFF", total_fond)
        actifs = [n for n in jours_tot if n > 0]
        vals = {self.cT: sum(jours_tot), self.cG: sum(comptes.get("_gps", {}).values()), self.cE: sum(comptes.get("_evo", {}).values()),
                self.cJ: len(actifs), self.cMin: min(actifs) if actifs else 0, self.cMax: max(actifs) if actifs else 0}
        for c in range(self.cT, self.der + 1):
            ecrire(ws, (r, c), vals.get(c), taille=11, gras=True, couleur="FFFFFF", fond=total_fond, fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
        ws.row_dimensions[r].height = 25
        self.ligne += 1

    def trace(self, nom, texte_b, par_jour, gps, evo, total=False):
        ws, r = self.ws, self.ligne
        fond = TEAL2 if total else "FFFFFF"
        ecrire(ws, (r, 1), nom, taille=11, gras=not total, couleur=NUIT, fond=BLOC, h=None, police="Aptos", wrap=None if total else True)
        ecrire(ws, (r, 2), texte_b, taille=11, gras=total, couleur="FFFFFF" if total else NUIT, fond=TEAL2 if total else BLOC,
               h=None, wrap=None, police="Aptos")
        for j in self.jours:
            self._cellule_jour(r, j, par_jour.get(j, 0), total, "FFFFFF" if total else NUIT, fond)
        vals = [par_jour.get(j, 0) for j in self.R.jours]
        actifs = [n for n in vals if n > 0]
        d = {self.cT: sum(vals), self.cG: gps, self.cE: evo}
        if total:
            d.update({self.cJ: len(actifs), self.cMin: min(actifs) if actifs else 0, self.cMax: max(actifs) if actifs else 0})
        for c in range(self.cT, self.der + 1):
            ecrire(ws, (r, c), d.get(c), taille=11, gras=total, couleur="FFFFFF" if total else NUIT, fond=TEAL2 if total else None,
                   fmt=NB_TIRET, h="center", wrap=None, police="Aptos")
        ws.row_dimensions[r].height = 25 if total else 23
        self.ligne += 1

    def fin(self, texte):
        ws, r = self.ws, self.ligne
        ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=self.der)
        c = ws.cell(row=r, column=1, value=texte)
        style(c, taille=11, couleur="000000", h=None, police="Calibri")
        ws.row_dimensions[r].height = 30


def comptes_par_jour(visites):
    out = defaultdict(lambda: {"cons": 0, "avant": 0, "apres": 0, "total": 0})
    gps = defaultdict(int)
    evo = defaultdict(int)
    for v in visites:
        k = {V.CONSULTATION: "cons", V.AVANT: "avant", V.APRES: "apres"}.get(v["classe"])
        if not k:
            continue
        out[v["jour"]][k] += 1
        out[v["jour"]]["total"] += 1
        (gps if v["logiciel"] == "GPS" else evo)[k] += 1
    d = dict(out)
    d["_gps"] = dict(gps)
    d["_evo"] = dict(evo)
    return d


def _par_medecin(visites):
    groupes = defaultdict(list)
    for v in visites:
        groupes[v["medecin_aff"]].append(v)
    cle = {m: (-len(l), -len({v["jour"] for v in l}), m) for m, l in groupes.items()}
    return [(m, groupes[m]) for m in sorted(groupes, key=lambda m: cle[m])]


def feuille_medecins(wb, R, site, onglet):
    ws = wb.create_sheet(f"{site} médecins")
    mise_en_page(ws, onglet, zoom=80, figer="C5", resume_dessous=True)
    g = GrilleMedecins(ws, R)
    g.entetes(site)
    vs = [v for v in R.visites if v["site"] == site and v["activite"] != V.HOSPITALISATION]
    g.ligne_cabinets("TOTAL DU SITE", "Cabinets ouverts — toutes activités", R.cab_site[site])
    g.bloc(None, comptes_par_jour(vs), total_fond=NUIT, a_sous_cab=True)
    g.vide(style_jours=False)
    fin_txt = f"* Minimum sur les jours avec visite. Les {_jours_vides(R, g)} restent vides : données non fournies."
    if site == "CSMKL2":
        g.section("ACTIVITÉ PRINCIPALE")
        pr = [v for v in vs if v["activite"] == V.PRINCIPALE]
        cab = {l["jour"]: l["cab"] for l in R.jours_act[(site, V.PRINCIPALE)]}
        g.ligne_cabinets("MÉDECINE GÉNÉRALE / FAMILLE", "Cabinets ouverts — activité principale", cab)
        g.bloc(None, comptes_par_jour(pr), a_sous_cab=True)
        g.vide(style_jours=False)
        for m, l in _par_medecin(pr):
            g.bloc(m, comptes_par_jour(l))
            g.vide()
        g.vide(style_jours=False)
        g.section("MAISON ROSE — HORS CAPACITÉ PRINCIPALE")
        mr = [v for v in vs if v["activite"] == V.SECONDAIRE]
        g.bloc("TOTAL MAISON ROSE", comptes_par_jour(mr))
        g.vide()
        for m, l in _par_medecin(mr):
            g.bloc(m, comptes_par_jour(l))
            g.vide()
        g.fin(fin_txt + " MAISON ROSE remplace le libellé d’activité secondaire, sans reclassement.")
    else:
        amb = [v for v in vs if v["activite"] == V.AMBULATOIRE]
        g.section("AMBULATOIRE")
        g.bloc("TOTAL AMBULATOIRE", comptes_par_jour(amb))
        g.vide(style_jours=False)
        specs = defaultdict(list)
        for v in amb:
            specs[v["specialite"]].append(v)
        for sp in sorted(specs, key=lambda s: (-len(specs[s]), s)):
            g.section(f"AMBULATOIRE / {sp}")
            g.bloc("TOTAL SPÉCIALITÉ", comptes_par_jour(specs[sp]))
            g.vide()
            for m, l in _par_medecin(specs[sp]):
                g.bloc(m, comptes_par_jour(l))
                g.vide()
        urg = [v for v in vs if v["activite"] == V.URGENCES]
        g.section("URGENCES")
        g.bloc("TOTAL URGENCES", comptes_par_jour(urg))
        g.vide()
        for m, l in _par_medecin(urg):
            g.bloc(m, comptes_par_jour(l))
            g.vide()
        g.section("HOSPITALISATION / TRACES DE SUIVI (PAS DES ADMISSIONS)")
        tr = [v for v in R.visites if v["site"] == site and v["activite"] == V.HOSPITALISATION]
        unites = defaultdict(list)
        for v in tr:
            unites[v["specialite"]].append(v)
        for u in sorted(unites):
            pj = defaultdict(int)
            for v in unites[u]:
                pj[v["jour"]] += 1
            g.trace(u, "Traces de suivi", pj, sum(1 for v in unites[u] if v["logiciel"] == "GPS"),
                    sum(1 for v in unites[u] if v["logiciel"] != "GPS"))
        pj = defaultdict(int)
        for v in tr:
            pj[v["jour"]] += 1
        g.trace("HOSPITALISATION", "Total traces de suivi", pj, sum(1 for v in tr if v["logiciel"] == "GPS"),
                sum(1 for v in tr if v["logiciel"] != "GPS"), total=True)
        g.vide(style_jours=False)
        g.fin(fin_txt + " Les traces d’hospitalisation restent hors des visites de consultation.")
    return ws


def _jours_vides(R, g):
    vides = [j for j in g.jours if j not in g.periode]
    if not vides:
        return "jours hors période"
    return f"{vides[0].day:02d}–{vides[-1].day:02d}/{vides[-1].month:02d}"


# ----------------------------------------------------------------------------------------------
# JOURS PAR MÉDECIN (Date → Jour → Médecin)
# ----------------------------------------------------------------------------------------------
def _situation(total, cap, seuil, maison_rose=0):
    if total == 0 and maison_rose:
        return "MAISON ROSE : hors repère"
    if total < seuil:
        return "Visite comptée, cabinet non compté"
    if total > cap:
        return "Au-dessus de la limite"
    if total == cap:
        return "Limite atteinte"
    return "Sous la limite"


def _entete_jours(ws, R, site, colonnes, largeurs):
    n = len(colonnes)
    der = L(n)
    ws.merge_cells(f"A1:{der}1")
    ecrire(ws, "A1", f"JOURS PAR MÉDECIN • {site} | {libelle_periode(R).upper()} {R.fin.year}", taille=14, gras=True,
           couleur="FFFFFF", fond=NUIT, h=None, wrap=None, police="Aptos")
    ws.merge_cells(f"A2:{der}2")
    ecrire(ws, "A2", "Liste par date puis par médecin • Filtrer la colonne Date pour voir qui a consulté • Visites enregistrées, pas planning de présence",
           taille=11, couleur=NUIT, h=None, wrap=None, police="Aptos")
    ws.merge_cells("A3:C3")
    ws.merge_cells("D3:H3")
    ws.merge_cells(f"I3:{der}3")
    for ref, t, cible in (("A3", "Retour à la synthèse", f"'{site}'!A1"), ("D3", "Médecins / calendrier", f"'{site} médecins'!A1"),
                          ("I3", "Règles et contrôles", "'Notez bien'!A1")):
        c = ecrire(ws, ref, t, taille=11, couleur=TEAL2, h=None, v=None, wrap=None, police="Aptos")
        c.hyperlink = f"#{cible}"
    for i, t in enumerate(colonnes, start=1):
        gauche = i <= 3
        ecrire(ws, (5, i), t, taille=11, gras=True, couleur="FFFFFF", fond=TEAL2, h="left" if gauche else "center", police="Aptos")
    for i, w in enumerate(largeurs, start=1):
        ws.column_dimensions[L(i)].width = w
    ws.row_dimensions[1].height = 30
    ws.row_dimensions[2].height = 27
    ws.row_dimensions[3].height = 23
    ws.row_dimensions[4].height = 9
    ws.row_dimensions[5].height = 51
    ws.auto_filter.ref = f"A5:{der}5"


def _ligne_jour(ws, r, valeurs, premier, gras_cols, gauche_cols, orange_col=None, orange=False):
    dimanche = valeurs[0].weekday() == 6
    fond = DIM if dimanche else None
    for i, val in enumerate(valeurs, start=1):
        f = ORANGE if (orange and i == orange_col) else fond
        ecrire(ws, (r, i), val, taille=11, gras=i in gras_cols, couleur=NUIT, fond=f,
               fmt=DATE if i == 1 else None, h="left" if i in gauche_cols else "center", police="Aptos",
               filet=FILET if premier else None)
    ws.row_dimensions[r].height = 33


def feuille_jours_csmkl2(wb, R, onglet):
    site = "CSMKL2"
    ws = wb.create_sheet(f"{site} jours")
    mise_en_page(ws, onglet, zoom=80, figer="D6", resume_dessous=True)
    cols = ["Date", "Jour", "Médecin", "Consultation\nprincipale", "Résultats avant\nune semaine", "Résultats après\nune semaine",
            "Total principal\nréel", "Capacité\ncomptée", "Marge avant 24", "Situation du cabinet", "Total toutes\nactivités",
            "Visites\nMAISON ROSE", "Principales\nsans cabinet", "GPS\ntoutes activités", "Evolucare\ntoutes activités"]
    _entete_jours(ws, R, site, cols, [15, 14, 33, 16, 20, 20, 18, 17, 17, 31, 19, 19, 19, 16, 16])
    ws.cell(row=5, column=9).value = f"Marge avant {R.cap_jour}"
    grp = defaultdict(list)
    for v in R.visites:
        if v["site"] == site and v["activite"] in (V.PRINCIPALE, V.SECONDAIRE):
            grp[(v["jour"], v["medecin_aff"])].append(v)
    r = 6
    precedent = None
    for (j, m) in sorted(grp, key=lambda k: (k[0], k[1] == SANS_MEDECIN, k[1])):
        l = grp[(j, m)]
        pr = [v for v in l if v["activite"] == V.PRINCIPALE]
        c = sum(1 for v in pr if v["classe"] == V.CONSULTATION)
        a = sum(1 for v in pr if v["classe"] == V.AVANT)
        p = sum(1 for v in pr if v["classe"] == V.APRES)
        tot = len(pr)
        mr = len(l) - tot
        sans = m == SANS_MEDECIN
        cab = 1 if (tot >= R.seuil and not sans) else 0
        cap = cab * R.cap_jour
        marge = max(R.cap_jour - tot, 0) if cab else 0
        sit = "Sans médecin renseigné" if sans else _situation(tot, R.cap_jour, R.seuil, mr)
        vals = [j, nom_jour(j), m, c, a, p, tot, cap, marge, sit, len(l), mr,
                tot if (tot and not cab) else 0,
                sum(1 for v in l if v["logiciel"] == "GPS"), sum(1 for v in l if v["logiciel"] != "GPS")]
        _ligne_jour(ws, r, vals, j != precedent, {7, 11}, {2, 3, 10}, 10, sit == "Au-dessus de la limite")
        precedent = j
        r += 1
    ws.auto_filter.ref = f"A5:O{r - 1}"
    return ws


def feuille_jours_chme(wb, R, onglet):
    site = "CHME"
    ws = wb.create_sheet(f"{site} jours")
    mise_en_page(ws, onglet, zoom=80, figer="D6", resume_dessous=True)
    cols = ["Date", "Jour", "Médecin", "Activité", "Spécialités du jour", "Consultation", "Résultats avant\nune semaine",
            "Résultats après\nune semaine", "Total visites", "Cabinet\ncompté", "Capacité\nthéorique", f"Marge avant {R.cap_jour}",
            "Situation", "GPS", "Evolucare", f"Dépassement\n> {R.cap_jour}", "Visite isolée"]
    _entete_jours(ws, R, site, cols, [15, 14, 33, 17, 37, 15, 19, 19, 16, 15, 16, 17, 31, 12, 13, 17, 15])
    grp = defaultdict(list)
    for v in R.visites:
        if v["site"] == site and v["activite"] in (V.AMBULATOIRE, V.URGENCES):
            grp[(v["jour"], v["medecin_aff"], v["activite"])].append(v)
    r = 6
    precedent = None
    for (j, m, act) in sorted(grp, key=lambda k: (k[0], k[1] == SANS_MEDECIN, k[1], k[2])):
        l = grp[(j, m, act)]
        c = sum(1 for v in l if v["classe"] == V.CONSULTATION)
        a = sum(1 for v in l if v["classe"] == V.AVANT)
        p = sum(1 for v in l if v["classe"] == V.APRES)
        tot = len(l)
        sans = m == SANS_MEDECIN
        cab = 1 if (tot >= R.seuil and not sans) else 0
        cap = cab * R.cap_jour
        marge = max(R.cap_jour - tot, 0) if cab else 0
        sit = "Sans médecin renseigné" if sans else _situation(tot, R.cap_jour, R.seuil)
        specs = " / ".join(sorted({v["specialite"] for v in l}))
        vals = [j, nom_jour(j), m, act, specs, c, a, p, tot, cab, cap, marge, sit,
                sum(1 for v in l if v["logiciel"] == "GPS"), sum(1 for v in l if v["logiciel"] != "GPS"),
                1 if (cab and tot > R.cap_jour) else 0, 1 if (tot == 1 and not sans) else 0]
        _ligne_jour(ws, r, vals, j != precedent, {9}, {2, 3, 4, 5, 13}, 13, sit == "Au-dessus de la limite")
        precedent = j
        r += 1
    ws.auto_filter.ref = f"A5:Q{r - 1}"
    return ws


# ----------------------------------------------------------------------------------------------
# ACTES : spécialité → sous-spécialité → acte (groupes dépliables)
# ----------------------------------------------------------------------------------------------
def feuille_actes(wb, R, site, onglet):
    ws = wb.create_sheet(f"{site} actes")
    mise_en_page(ws, onglet, zoom=85, figer="B7")
    arbre, total = arbre_actes(R, site)
    nd = R.ndays
    entetes = ["Spécialité / sous-spécialité / acte", "GPS / Date_V", "Evo / DATEHEURE", "Dossiers GPS", "Dossiers Evo",
               "Quantités Evo", "Profil hospi GPS", "Profil hospi Evo", "Sans lien GPS", "Sans lien Evo",
               "Consult./résult. GPS", "Consult./résult. Evo"]
    entetes += [f"{j.day:02d} GPS" for j in R.jours] + [f"{j.day:02d} Evo" for j in R.jours]
    entetes += ["Pic GPS / jour", "Date pic GPS", "Pic Evo / jour", "Date pic Evo"]
    der = len(entetes)
    cPic = 13 + 2 * nd
    ws.merge_cells("A1:L1")
    ecrire(ws, "A1", f"{site} / ACTES PAR SPÉCIALITÉ ET SOUS-SPÉCIALITÉ", taille=19, gras=True, couleur=BLANC, fond=NAVY)
    ws.merge_cells("A2:L2")
    ecrire(ws, "A2", f"{libelle_periode(R, '-')} {R.fin.year} | GPS : Date_V ; Evolucare : DATEHEURE, complément séparé", fond=CLAIR)
    ws.merge_cells("A3:L3")
    ecrire(ws, "A3", "Deux dates différentes : aucun total GPS + Evolucare dans les séries. Médicaments/consommables séparés. Les + déplient les actes et les jours.",
           couleur=ALERTE_T, fond=ALERTE_F)
    ws.merge_cells("A4:F4")
    ws.merge_cells("G4:L4")
    lien(ws, "A4", "Retour au Dashboard", "'Dashboard'!A1")
    lien(ws, "G4", "Synthèse du site", f"'{site}'!A1")
    for i, t in enumerate(entetes, start=1):
        ecrire(ws, (6, i), t, gras=True, couleur=BLANC, fond=NAVY)
    for h, r in ((32, 1), (28, 2), (34, 3), (24, 4), (36, 6), (30, 7)):
        ws.row_dimensions[r].height = h
    ws.column_dimensions["A"].width = 64
    for c in list(range(2, 13)) + list(range(cPic, der + 1)):
        ws.column_dimensions[L(c)].width = 14
    for c in range(13, cPic):
        ws.column_dimensions[L(c)].width = 14
        ws.column_dimensions[L(c)].outlineLevel = 1
        ws.column_dimensions[L(c)].hidden = True

    def ligne(r, nom, agg, niveau):
        if niveau == "total":
            fond, coul, gras, ind = TEAL, BLANC, True, 0
        elif niveau == "spec":
            fond, coul, gras, ind = NAVY, BLANC, True, 0
        elif niveau == "sub":
            fond, coul, gras, ind = ZEBRE, TEXTE, True, 1
        else:
            fond, coul, gras, ind = BLANC, TEXTE, False, 2
        ecrire(ws, (r, 1), nom, gras=gras, couleur=coul, fond=fond, h=None, indent=ind)
        vals = [agg["gps"], agg["evo"], agg["dos_gps"], agg["dos_evo"], agg["qte"], agg["hosp_gps"], agg["hosp_evo"],
                agg["sans_gps"], agg["sans_evo"], agg["cons_gps"], agg["cons_evo"]] + agg["jours_gps"] + agg["jours_evo"]
        for i, v in enumerate(vals, start=2):
            ecrire(ws, (r, i), v, gras=gras, couleur=coul, fond=fond, fmt=DEC if i == 6 else NB, h="right")
        ecrire(ws, (r, cPic), agg["pic_gps"], gras=gras, couleur=coul, fond=fond, fmt=NB, h="right")
        ecrire(ws, (r, cPic + 1), agg["date_pic_gps"], gras=gras, couleur=coul, fond=fond, fmt=DATE, h="right")
        ecrire(ws, (r, cPic + 2), agg["pic_evo"], gras=gras, couleur=coul, fond=fond, fmt=NB, h="right")
        ecrire(ws, (r, cPic + 3), agg["date_pic_evo"], gras=gras, couleur=coul, fond=fond, fmt=DATE, h="right")

    ligne(7, "TOTAL / SOURCES SÉPARÉES", total, "total")
    r = 9
    positions = {}
    for sp in arbre:
        positions[sp["nom"]] = r
        ligne(r, sp["nom"], sp["agg"], "spec")
        ws.row_dimensions[r].height = 30
        r += 1
        for sb in sp["subs"]:
            ligne(r, sb["nom"], sb["agg"], "sub")
            ws.row_dimensions[r].height = 30
            ws.row_dimensions[r].outlineLevel = 1
            ws.row_dimensions[r].hidden = True
            r += 1
            for ac in sb["actes"]:
                ligne(r, ac["nom"], ac["agg"], "acte")
                ws.row_dimensions[r].height = 35
                ws.row_dimensions[r].outlineLevel = 2
                ws.row_dimensions[r].hidden = True
                r += 1
    r += 2
    note(ws, r, "Une ligne = un enregistrement, pas toujours un acte technique : forfaits, consultations et prestations hôtelières restent identifiables. Dossiers non additionnables entre spécialités.", 12)
    r += 2
    nprod = sum(1 for a in R.produits if a["site"] == site)
    note(ws, r, f"Produits séparés de cet export : {nprod:,} lignes Evolucare. Tous les originaux sont conservés dans Base actes. Capacité technique non fournie.", 12, alerte=True)
    ws.sheet_properties.outlinePr.summaryBelow = False
    ws.sheet_properties.outlinePr.summaryRight = False
    return ws, positions, arbre
