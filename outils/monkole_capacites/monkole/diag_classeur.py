"""Classeur « Diagnostics et composition des familles » (présentation de la version retenue)."""
from collections import defaultdict

from openpyxl.chart import PieChart, Reference
from openpyxl.chart.label import DataLabelList
from openpyxl.chart.series import DataPoint
from openpyxl.comments import Comment
from openpyxl.drawing.spreadsheet_drawing import AnchorMarker, TwoCellAnchor
from openpyxl.formatting.rule import ColorScaleRule, DataBarRule
from openpyxl.styles import Alignment, Font
from openpyxl.utils import get_column_letter as L

from . import diag_dictionnaire as DD
from .diag_calculs import AGE_INCONNU, SANS_SITE, TRANCHES, age_sexe
from .styles import ecrire
from .texte import MOIS, JOURS_COURTS

NAVY = "143247"
TEAL = "087F8C"
TEAL_C = "008797"
TXT = "243F53"
GRIS = "5A778E"
CLAIR = "F4F8FC"
AL_T = "B67313"
AL_F = "FFF1C9"
# Composition (Aptos)
C_TXT = "274C68"
C_CLAIR = "F3F7FB"
C_NAVY = "163447"
C_GRIS = "61768A"
BLANC = "FFFFFF"

COULEURS = {
    "Grossesse / accouchement": "087F8C", "ORL / respiratoire": "3D91CC", "Cardiovasculaire": "264E70",
    "Endocrino-métabolique": "966CC0", "Infectieux / parasitaire": "E9A03B", "Ophtalmologie": "54A6A6",
    "Digestif / hépatique": "C76F69", "Urinaire / néphrologie": "719950", "Locomoteur / traumatologie": "8198B0",
    "Gynécologie / sein": "CD7DAB", "Neurologie / santé mentale": "7465A4", "Peau / allergie": "B1A047",
    "Hématologie / oncologie": "A65065", "Néonatal / périnatal": "6DBAB0", "Signes généraux / iatrogénie": "887867",
    "Bucco-dentaire": "B8C5D0",
}
NB = "#,##0"
PCT = "0.0%"


def periode_txt(R, tiret="-"):
    d, f = R.debut, R.fin
    if d.month == f.month:
        return f"{d.day:02d}{tiret}{f.day:02d} {MOIS[d.month - 1]} {f.year}"
    return f"{d:%d/%m}{tiret}{f:%d/%m/%Y}"


def zebre(r, clair=CLAIR):
    return clair if r % 2 == 0 else BLANC


def fusion(ws, r1, c1, r2, c2):
    if r2 > r1 or c2 > c1:
        ws.merge_cells(start_row=r1, start_column=c1, end_row=r2, end_column=c2)


def bloc(ws, r1, c1, r2, c2, valeur=None, **kw):
    fusion(ws, r1, c1, r2, c2)
    for r in range(r1, r2 + 1):
        for c in range(c1, c2 + 1):
            ecrire(ws, (r, c), valeur if (r, c) == (r1, c1) else None, **kw)
    return ws.cell(row=r1, column=c1)


def lien(cell, cible):
    cell.hyperlink = f"#{cible}"


def mise_en_page(ws, onglet, zoom=85, figer=None):
    ws.sheet_view.showGridLines = False
    ws.sheet_view.zoomScale = zoom
    ws.sheet_properties.tabColor = onglet
    ws.page_setup.orientation = "landscape"
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 0
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    if figer:
        ws.freeze_panes = figer


def hauteurs(ws, d):
    for r, h in d.items():
        ws.row_dimensions[r].height = h


def tri_familles(P):
    return sorted(DD.FAMILLES, key=lambda f: (-P.familles[f]["total"], DD.FAMILLES.index(f)))


def tri_groupes(groupes):
    return sorted(groupes, key=lambda x: (-x["total"], -x["hyp"], x["groupe"].lower()))


# ----------------------------------------------------------------------------------------------
def entete_commun(ws, R, titre, sous_titre, liens):
    for c in range(1, 17):
        ws.column_dimensions[L(c)].width = 10.5
    bloc(ws, 1, 1, 2, 16, titre, taille=21, gras=True, couleur=BLANC, fond=NAVY)
    bloc(ws, 3, 1, 3, 16, sous_titre, taille=11, couleur=GRIS, fond=CLAIR)
    for i, (t, cible) in enumerate(liens):
        c = bloc(ws, 4, 1 + 4 * i, 4, 4 + 4 * i, t, couleur=TEAL, fond=CLAIR)
        lien(c, cible)


def expliquer(ws, r, c, texte):
    """Explication d'un terme : note Excel affichée au survol de la cellule (petit triangle rouge)."""
    note = Comment(texte, "Monkole")
    note.width, note.height = 320, 130
    ws.cell(row=r, column=c).comment = note


def _titre_bloc(ws, r, texte, c1, c2, aide=None):
    bloc(ws, r, c1, r, c2, texte, taille=10, gras=True, couleur=BLANC, fond=NAVY)
    ws.row_dimensions[r].height = 18
    if aide:
        expliquer(ws, r, c1, aide)


def _entete(ws, r, colonnes, hauteur=26, a_gauche=(), aides=None):
    for c1, c2, t in colonnes:
        bloc(ws, r, c1, r, c2, t, taille=9, gras=True, couleur=TXT, fond=CLAIR,
             h="left" if c1 == colonnes[0][0] or c1 in a_gauche else "right")
        if aides and t in aides:
            expliquer(ws, r, c1, aides[t])
    ws.row_dimensions[r].height = hauteur


def _cel(ws, r, c1, c2, v, fmt=NB, h="right", couleur=TXT, gras=False, fond=None):
    return bloc(ws, r, c1, r, c2, v, taille=10, couleur=couleur, gras=gras, fond=fond or zebre(r),
                fmt=fmt if not isinstance(v, str) else None, h=h, wrap=False)


def _tot(ws, r, c1, c2, v, fmt=NB, h="right"):
    return bloc(ws, r, c1, r, c2, v, taille=10, gras=True, couleur=BLANC, fond=TEAL, fmt=fmt if not isinstance(v, str) else None, h=h,
                wrap=False)


TERMES_DIAG = {
    "Dossiers": "Numéros de dossier distincts, par logiciel et par site, ayant au moins une ligne de diagnostic. Ce ne sont pas "
                "des patients uniques (un patient peut avoir un dossier dans GPS et un dans Evolucare).",
    "Diagnostics comptabilisés": "Un diagnostic écrit sans marqueur de doute, compté une seule fois par dossier et logiciel sur la "
                                 "période, même s’il est répété plusieurs jours.",
    "Différents / récurrents": "Différents : nombre de diagnostics regroupés distincts. Récurrents : diagnostics retrouvés dans au "
                               "moins deux dossiers (ce n’est pas une rechute).",
    "Hypothèses seules": "Diagnostics seulement évoqués avec doute (?, suspicion, à exclure, probable…) et jamais écrits sans doute "
                         "dans le même dossier. Ils ne sont pas dans le disque.",
    "Lignes à classer / à clarifier": "À classer : textes absents du dictionnaire, à compléter dans Dictionnaire_Diagnostics.xlsx "
                                      "(fichier Diagnostics_a_classer_… dans sorties). À clarifier : sigles ou fragments "
                                      "incompréhensibles. Sans diagnostic exploitable : lignes vides ou sans sens clinique.",
    "Famille clinique": "Regroupement des diagnostics en 16 familles (ORL / respiratoire, cardiovasculaire…). Le disque montre la "
                        "part de chaque famille dans les diagnostics comptabilisés. Cliquer sur une famille pour voir sa composition.",
    "Comptés": "Nombre de diagnostics comptabilisés (une fois par dossier et logiciel).",
    "Part": "Diagnostics comptabilisés de la ligne ÷ total des diagnostics comptabilisés (ou dossiers de la tranche ÷ total des "
            "dossiers dans le tableau Âge et sexe).",
    "Hyp. seules": "Nombre de dossiers où ce diagnostic n’est qu’une hypothèse (non compté dans « Comptés »).",
    "Diagnostic / situation clinique": "Diagnostic regroupé : les différentes écritures d’un même diagnostic (HTA, hypertension "
                                       "artérielle…) sont rapprochées. Cliquer pour voir les écritures dans le Dictionnaire.",
    "Tranche d’âge": "Âge au premier jour du dossier dans la période, calculé avec la date de naissance de l’export. Âge inconnu : "
                     "date absente ou incohérente.",
    "Femmes": "Dossiers dont le sexe renseigné est F.",
    "Hommes": "Dossiers dont le sexe renseigné est M.",
    "Diagnostics": "Diagnostics comptabilisés des dossiers de la tranche d’âge.",
    "Site / logiciel": "Répartition des dossiers et des diagnostics comptabilisés par site (CSMKL2, CHME) ou par logiciel "
                       "(GPS, Evolucare).",
}


def synthese(wb, R, nom, P, titre, onglet, lignes_source, pos_compo, pos_dico):
    """Synthèse lisible sur un écran (zoom 85 %) : indicateurs, familles, 12 diagnostics, âge et sexe, sources."""
    ws = wb.create_sheet(nom)
    mise_en_page(ws, onglet, zoom=78)
    ws.page_setup.fitToHeight = 0
    for c in range(1, 17):
        ws.column_dimensions[L(c)].width = 10.5
    ws.column_dimensions["I"].width = 2
    bloc(ws, 1, 1, 1, 10, f"{titre} — {periode_txt(R)}", taille=15, gras=True, couleur=BLANC, fond=NAVY, wrap=False)
    if nom == "Dashboard":
        liens = [(11, 11, "CSMKL2", "'CSMKL2'!A1"), (12, 12, "CHME", "'CHME'!A1"), (13, 13, "Familles", "'Composition familles'!A1"),
                 (14, 15, "Âge et sexe", "'Âge et sexe'!A1"), (16, 16, "Règles", "'Notez bien'!A1")]
    else:
        liens = [(11, 12, "Dashboard", "'Dashboard'!A1"), (13, 14, "Âge et sexe", "'Âge et sexe'!A1"),
                 (15, 16, "Notez bien", "'Notez bien'!A1")]
    for c1, c2, t, cible in liens:
        lien(bloc(ws, 1, c1, 1, c2, t, taille=10, gras=True, couleur=TEAL, fond=CLAIR, h="center"), cible)
    ws.row_dimensions[1].height = 26

    # Indicateurs
    A, S = age_sexe(R, P)
    nd = len(P.dossiers) or 1
    a_classer = sum(1 for src in P.sources if src.get("origine") == "à classer")
    T = TERMES_DIAG
    kpis = [("DOSSIERS", len(P.dossiers), f"Femmes {S['F']['dos'] / nd:.0%} · hommes {S['M']['dos'] / nd:.0%}".replace("%", " %"),
             T["Dossiers"]),
            ("DIAGNOSTICS COMPTABILISÉS", P.total, f"{P.differents} différents · {P.recurrents} récurrents (≥ 2 dossiers)",
             T["Diagnostics comptabilisés"] + " " + T["Différents / récurrents"]),
            ("HYPOTHÈSES SEULES", len(P.hyp), "hors disque", T["Hypothèses seules"]),
            ("LIGNES À CLASSER / À CLARIFIER", f"{a_classer} / {P.lignes_a_clarifier}",
             f"{P.lignes_non_exploitables} lignes sans diagnostic exploitable", T["Lignes à classer / à clarifier"])]
    for i, (t, v, com, aide) in enumerate(kpis):
        c1 = 1 + 4 * i
        bloc(ws, 2, c1, 2, c1 + 3, t, taille=10, gras=True, couleur=BLANC, fond=TEAL, h=None)
        expliquer(ws, 2, c1, aide)
        bloc(ws, 3, c1, 3, c1 + 3, v, taille=22, gras=True, couleur=TEAL, fond=BLANC, fmt=NB, h="center", wrap=None)
        bloc(ws, 4, c1, 4, c1 + 3, com, taille=9, couleur=TXT, fond=CLAIR, h="center")
    hauteurs(ws, {2: 17, 3: 30, 4: 15, 5: 5})

    # Familles : disque + tableau (légende)
    _titre_bloc(ws, 6, "FAMILLES CLINIQUES / PART DES DIAGNOSTICS COMPTABILISÉS", 1, 8, aide=T["Famille clinique"])
    _entete(ws, 7, [(5, 7, "Famille clinique"), (8, 8, "Comptés")], hauteur=18, aides=T)
    fams = tri_familles(P)
    for i, f in enumerate(fams):
        r = 8 + i
        x = P.familles[f]
        c = _cel(ws, r, 5, 7, f, h="left", couleur=COULEURS[f], gras=True)
        lien(c, f"'Composition familles'!A{pos_compo[f]}")
        _cel(ws, r, 8, 8, x["total"])
    rt = 8 + len(fams)
    _tot(ws, rt, 5, 7, "Total", h="left")
    _tot(ws, rt, 8, 8, P.total)
    pie = PieChart()
    pie.height, pie.width = 8.6, 8.3
    pie.add_data(Reference(ws, min_col=8, min_row=8, max_row=7 + len(fams)), titles_from_data=False)
    pie.set_categories(Reference(ws, min_col=5, min_row=8, max_row=7 + len(fams)))
    sr = pie.series[0]
    for i, f in enumerate(fams):
        pt = DataPoint(idx=i)
        pt.graphicalProperties.solidFill = COULEURS[f]
        pt.graphicalProperties.line.solidFill = "FFFFFF"
        sr.dPt.append(pt)
    sr.dLbls = DataLabelList()
    sr.dLbls.showPercent = True
    sr.dLbls.showVal = False
    sr.dLbls.showCatName = False
    sr.dLbls.showSerName = False
    sr.dLbls.showLeaderLines = True
    pie.legend = None           # les couleurs des familles figurent dans le tableau à droite du disque
    # le disque occupe exactement les colonnes A-D, sans recouvrir le tableau des familles
    pie.anchor = TwoCellAnchor(_from=AnchorMarker(col=0, row=7, colOff=38100, rowOff=19050),
                               to=AnchorMarker(col=4, row=rt, colOff=0, rowOff=0))
    ws.add_chart(pie)

    # 12 diagnostics les plus fréquents
    _titre_bloc(ws, 6, "LES 12 DIAGNOSTICS LES PLUS FRÉQUENTS", 10, 16, aide=T["Diagnostic / situation clinique"])
    _entete(ws, 7, [(10, 13, "Diagnostic / situation clinique"), (14, 14, "Comptés"), (15, 15, "Part"), (16, 16, "Hyp. seules")],
            hauteur=18, aides=T)
    top = tri_groupes([x for x in P.groupes.values() if x["total"] > 0])[:12]
    for i, x in enumerate(top):
        r = 8 + i
        c = _cel(ws, r, 10, 13, x["groupe"], h="left", couleur=TEAL_C)
        if x["code"] in pos_dico:
            lien(c, f"'Dictionnaire'!A{pos_dico[x['code']]}")
        _cel(ws, r, 14, 14, x["total"])
        _cel(ws, r, 15, 15, x["total"] / P.total if P.total else 0, fmt=PCT)
        _cel(ws, r, 16, 16, x["hyp"])
    if top:
        ws.conditional_formatting.add(f"N8:N{7 + len(top)}", DataBarRule(start_type="min", end_type="max", color="54A6A6"))

    # Âge et sexe
    r = 8 + 12 + 1
    _titre_bloc(ws, r, "ÂGE ET SEXE / DOSSIERS ET DIAGNOSTICS COMPTABILISÉS", 10, 16, aide=T["Tranche d’âge"])
    _entete(ws, r + 1, [(10, 11, "Tranche d’âge"), (12, 12, "Femmes"), (13, 13, "Hommes"),
                        (14, 14, "Dossiers"), (15, 15, "Part"), (16, 16, "Diagnostics")], hauteur=17, aides=T)
    r += 2
    for n, x in A.items():
        if n == AGE_INCONNU and not sum(x["dos"].values()):
            continue
        tot = sum(x["dos"].values())
        _cel(ws, r, 10, 11, n, h="left")
        _cel(ws, r, 12, 12, x["dos"]["F"])
        _cel(ws, r, 13, 13, x["dos"]["M"])
        _cel(ws, r, 14, 14, tot)
        _cel(ws, r, 15, 15, tot / nd, fmt=PCT)
        _cel(ws, r, 16, 16, x["diag"])
        r += 1
    _tot(ws, r, 10, 11, "Total", h="left")
    _tot(ws, r, 12, 12, S["F"]["dos"])
    _tot(ws, r, 13, 13, S["M"]["dos"])
    _tot(ws, r, 14, 14, len(P.dossiers))
    _tot(ws, r, 15, 15, 1 if P.dossiers else 0, fmt=PCT)
    _tot(ws, r, 16, 16, P.total)
    r_fin_droite = r

    # Sources (gauche, sous les familles)
    r = rt + 2
    _titre_bloc(ws, r, "DOSSIERS ET DIAGNOSTICS PAR SOURCE", 1, 8, aide=T["Site / logiciel"])
    _entete(ws, r + 1, [(1, 4, "Site / logiciel"), (5, 6, "Dossiers"), (7, 8, "Diagnostics comptés")], hauteur=17, aides=T)
    r += 2
    for lib, dos, dia in lignes_source:
        _cel(ws, r, 1, 4, lib, h="left")
        _cel(ws, r, 5, 6, dos)
        _cel(ws, r, 7, 8, dia)
        r += 1
    for x in range(6, max(r, r_fin_droite) + 1):
        if ws.row_dimensions[x].height is None:
            ws.row_dimensions[x].height = 17
    # Lexique sous l'écran (les mêmes explications s'affichent au survol des titres)
    r = max(r, r_fin_droite) + 3
    bloc(ws, r, 1, r, 16, "LEXIQUE / EXPLICATION DES TERMES (aussi au survol des titres marqués d’un petit triangle rouge)",
         taille=10, gras=True, couleur=BLANC, fond=NAVY)
    ws.row_dimensions[r].height = 20
    for i, (terme, texte) in enumerate(T.items()):
        rr = r + 1 + i
        bloc(ws, rr, 1, rr, 3, terme, taille=10, gras=True, couleur=TEAL, fond=zebre(rr))
        bloc(ws, rr, 4, rr, 16, texte, taille=10, couleur=TXT, fond=zebre(rr))
        ws.row_dimensions[rr].height = 30 if len(texte) > 150 else 17
    return ws


# ----------------------------------------------------------------------------------------------
def feuille_age_sexe(wb, R, onglet):
    """Dossiers, diagnostics et familles par tranche d'âge et par sexe (Monkole, CSMKL2, CHME)."""
    ws = wb.create_sheet("Âge et sexe")
    mise_en_page(ws, onglet, zoom=85, figer="A3")
    ws.column_dimensions["A"].width = 30
    for c in range(2, 13):
        ws.column_dimensions[L(c)].width = 11.5
    der = 12
    bloc(ws, 1, 1, 1, der - 2, f"MONKOLE / DIAGNOSTICS PAR ÂGE ET PAR SEXE — {periode_txt(R)}", taille=15, gras=True, couleur=BLANC,
         fond=NAVY, wrap=False)
    lien(bloc(ws, 1, der - 1, 1, der, "Dashboard", taille=9, gras=True, couleur=TEAL, fond=CLAIR, h="center"), "'Dashboard'!A1")
    bloc(ws, 2, 1, 2, der, "Âge au premier jour du dossier dans la période (date de naissance de l’export). Dossiers par logiciel, pas "
                           "patients uniques. Diagnostics comptabilisés = sans marqueur de doute, une fois par dossier.",
         taille=9, couleur=GRIS, fond=CLAIR)
    hauteurs(ws, {1: 26, 2: 28})
    noms = [t[0] for t in TRANCHES] + [AGE_INCONNU]
    r = 4
    perims = [("MONKOLE", R.global_)] + [(s, R.sites[s]) for s in ("CSMKL2", "CHME")]
    # 1. Tranches d'âge par périmètre
    for titre, P in perims:
        A, S = age_sexe(R, P)
        nd = len(P.dossiers) or 1
        _titre_bloc(ws, r, f"{titre} / DOSSIERS ET DIAGNOSTICS PAR TRANCHE D’ÂGE", 1, der)
        _entete(ws, r + 1, [(1, 1, "Tranche d’âge"), (2, 2, "Dossiers\nfemmes"), (3, 3, "Dossiers\nhommes"), (4, 4, "Sexe\ninconnu"),
                            (5, 5, "Dossiers"), (6, 6, "Part des\ndossiers"), (7, 7, "Diagnostics\ncomptés"), (8, 8, "Diagnostics\npar dossier"),
                            (9, 12, "Trois diagnostics les plus fréquents")], a_gauche=(9,))
        r += 2
        for n in noms:
            x = A[n]
            tot = sum(x["dos"].values())
            if n == AGE_INCONNU and not tot:
                continue
            _cel(ws, r, 1, 1, n, h="left", gras=True)
            for c, v in ((2, x["dos"]["F"]), (3, x["dos"]["M"]), (4, x["dos"]["Inconnu"]), (5, tot)):
                _cel(ws, r, c, c, v)
            _cel(ws, r, 6, 6, tot / nd, fmt=PCT)
            _cel(ws, r, 7, 7, x["diag"])
            _cel(ws, r, 8, 8, x["diag"] / tot if tot else 0, fmt="0.00")
            c = _cel(ws, r, 9, 12, _top(x["groupes"], 3), h="left")
            c.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True, indent=1)
            c.font = Font(name="Calibri", size=8, color=TXT)
            ws.row_dimensions[r].height = 30
            r += 1
        _tot(ws, r, 1, 1, "Total", h="left")
        for c, v in ((2, S["F"]["dos"]), (3, S["M"]["dos"]), (4, S["Inconnu"]["dos"]), (5, len(P.dossiers))):
            _tot(ws, r, c, c, v)
        _tot(ws, r, 6, 6, 1 if P.dossiers else 0, fmt=PCT)
        _tot(ws, r, 7, 7, P.total)
        _tot(ws, r, 8, 8, P.total / nd, fmt="0.00")
        _tot(ws, r, 9, 12, "", h="left")
        r += 3
    # 2. Familles par tranche d'âge (Monkole)
    A, S = age_sexe(R, R.global_)
    presents = [n for n in noms if sum(A[n]["dos"].values())]
    _titre_bloc(ws, r, "MONKOLE / FAMILLES CLINIQUES PAR TRANCHE D’ÂGE ET PAR SEXE (DIAGNOSTICS COMPTABILISÉS)", 1, der)
    cols = [(1, 1, "Famille clinique")] + [(2 + i, 2 + i, n) for i, n in enumerate(presents)]
    cf = 2 + len(presents)
    cols += [(cf, cf, "Femmes"), (cf + 1, cf + 1, "Hommes")]
    _entete(ws, r + 1, cols)
    r += 2
    r0 = r
    for f in tri_familles(R.global_):
        _cel(ws, r, 1, 1, f, h="left", couleur=COULEURS[f], gras=True)
        for i, n in enumerate(presents):
            _cel(ws, r, 2 + i, 2 + i, A[n]["familles"][f])
        _cel(ws, r, cf, cf, S["F"]["familles"][f])
        _cel(ws, r, cf + 1, cf + 1, S["M"]["familles"][f])
        r += 1
    ws.conditional_formatting.add(f"B{r0}:{L(1 + len(presents))}{r - 1}",
                                  ColorScaleRule(start_type="min", start_color="FFFFFF", end_type="max", end_color="54A6A6"))
    r += 2
    # 3. Diagnostics les plus fréquents par sexe
    _titre_bloc(ws, r, "MONKOLE / LES 10 DIAGNOSTICS LES PLUS FRÉQUENTS PAR SEXE", 1, der)
    _entete(ws, r + 1, [(1, 4, "Femmes"), (5, 5, "Comptés"), (7, 10, "Hommes"), (11, 11, "Comptés")])
    r += 2
    tf = _classement(S["F"]["groupes"])[:10]
    tm = _classement(S["M"]["groupes"])[:10]
    for i in range(max(len(tf), len(tm))):
        if i < len(tf):
            _cel(ws, r, 1, 4, tf[i][0], h="left")
            _cel(ws, r, 5, 5, tf[i][1])
        if i < len(tm):
            _cel(ws, r, 7, 10, tm[i][0], h="left")
            _cel(ws, r, 11, 11, tm[i][1])
        r += 1
    for x in range(3, r + 1):
        if ws.row_dimensions[x].height is None:
            ws.row_dimensions[x].height = 17
    return ws


def _classement(compteur):
    return sorted(compteur.items(), key=lambda kv: (-kv[1], kv[0].lower()))


def _top(compteur, n):
    return " · ".join(f"{g} ({k})" for g, k in _classement(compteur)[:n])


# ----------------------------------------------------------------------------------------------
def composition(wb, R, pos_dico):
    ws = wb.create_sheet("Composition familles")
    mise_en_page(ws, TEAL, zoom=80, figer="G18")
    G = R.global_
    entete_commun(ws, R, "MONKOLE / COMPOSITION DES FAMILLES CLINIQUES",
                  f"{periode_txt(R)} | Diagnostics différents, volumes et répartition dans les 100 % de chaque famille",
                  [("Retour au Dashboard", "'Dashboard'!A1"), ("CSMKL2 / synthèse", "'CSMKL2'!A1"), ("CHME / synthèse", "'CHME'!A1"),
                   ("Règles / Notez bien", "'Notez bien'!A1")])
    bloc(ws, 6, 1, 7, 16, "DEUX POURCENTAGES DIFFÉRENTS : le poids d’une famille dans le disque global et, dans chaque tableau "
                          "ci-dessous, la part de chaque diagnostic à l’intérieur de cette famille. Les hypothèses seules restent "
                          "hors des 100 %.", taille=11, gras=True, couleur="B87500", fond="FFF2CC")
    kp = [("FAMILLES CLINIQUES", len(DD.FAMILLES), "Familles du modèle conservées", NB),
          ("DIAGNOSTICS DIFFÉRENTS", G.differents, "Groupes différents sans doute explicite", NB),
          ("DIAGNOSTICS COMPTABILISÉS", G.total, "Un diagnostic par dossier et logiciel", NB),
          ("TOTAL INTERNE / FAMILLE", 1, "100 % si la famille a une activité", PCT)]
    for i, (t, v, com, fm) in enumerate(kp):
        c1 = 1 + 4 * i
        bloc(ws, 9, c1, 9, c1 + 3, t, gras=True, couleur=BLANC, fond=TEAL, h=None)
        bloc(ws, 10, c1, 12, c1 + 3, v, taille=27, gras=True, couleur=TEAL, fond=BLANC, fmt=fm, h="center")
        bloc(ws, 13, c1, 13, c1 + 3, com, taille=9, couleur="607D94", fond=CLAIR, h=None)
    ap = dict(police="Aptos")
    bloc(ws, 15, 1, 15, 16, "SOMMAIRE / CLIQUER SUR LA FAMILLE POUR VOIR SES DIAGNOSTICS", gras=True, couleur=BLANC, fond=C_NAVY, h=None, **ap)
    ent = ["Famille clinique", None, None, None, None, "Diagnostics\ndifférents\nMonkole", "Diagnostics\ncomptabilisés\nMonkole",
           "Part du\ntotal", "Diagnostics\ndifférents\nCSMKL2", "Diagnostics\ncomptabilisés\nCSMKL2", "Diagnostics\ndifférents\nCHME",
           "Diagnostics\ncomptabilisés\nCHME", "Diagnostics\ndifférents\nsans site", "Diagnostics\ncomptabilisés\nsans site",
           "Composition\ninterne", None]
    fusion(ws, 16, 1, 16, 5)
    fusion(ws, 16, 15, 16, 16)
    for c, t in enumerate(ent, start=1):
        ecrire(ws, (16, c), t, taille=9, gras=True, couleur=C_TXT, fond=C_CLAIR, h=None, **ap)
    fams = tri_familles(G)
    # positions des blocs
    pos = {}
    r = 40
    for f in fams:
        pos[f] = r
        r += 13 + sum(1 for x in G.groupes.values() if x["famille"] == f and x["total"] > 0)
    CS, CH, SS = R.sites["CSMKL2"], R.sites["CHME"], R.sites[SANS_SITE]
    for i, f in enumerate(fams):
        rr = 18 + i
        fond = zebre(rr + 1, C_CLAIR)
        c = bloc(ws, rr, 1, rr, 5, f, couleur=TEAL_C, fond=fond, h=None, **ap)
        lien(c, f"'Composition familles'!A{pos[f]}")
        vals = [G.familles[f]["differents"], G.familles[f]["total"], None, CS.familles[f]["differents"], CS.familles[f]["total"],
                CH.familles[f]["differents"], CH.familles[f]["total"], SS.familles[f]["differents"], SS.familles[f]["total"]]
        for k, v in enumerate(vals, start=6):
            ecrire(ws, (rr, k), v, couleur=C_TXT, fond=fond, h=None, **ap)
        ws.cell(row=rr, column=8, value=G.familles[f]["total"] / G.total if G.total else 0).number_format = PCT
        c = bloc(ws, rr, 15, rr, 16, "Voir les 100 %", couleur=TEAL_C, fond=fond, h=None, **ap)
        lien(c, f"'Composition familles'!A{pos[f]}")
        ws.row_dimensions[rr].height = 36
    rt = 18 + len(fams)
    bloc(ws, rt, 1, rt, 5, "TOTAL / MÊMES DONNEES QUE LE DASHBOARD", taille=9, gras=True, couleur=BLANC, fond=TEAL_C, h=None, **ap)
    tot = [sum(G.familles[f]["differents"] for f in fams), sum(G.familles[f]["total"] for f in fams), 1.0 if G.total else 0,
           sum(CS.familles[f]["differents"] for f in fams), sum(CS.familles[f]["total"] for f in fams),
           sum(CH.familles[f]["differents"] for f in fams), sum(CH.familles[f]["total"] for f in fams),
           sum(SS.familles[f]["differents"] for f in fams), sum(SS.familles[f]["total"] for f in fams)]
    for k, v in enumerate(tot, start=6):
        ecrire(ws, (rt, k), v, gras=True, couleur=BLANC, fond=TEAL_C, fmt=PCT if k == 8 else None, h=None, wrap=None, **ap)
    bloc(ws, rt, 15, rt, 16, "100 % des familles", gras=True, couleur=BLANC, fond=TEAL_C, h=None, **ap)
    ws.row_dimensions[rt].height = 38
    bloc(ws, rt + 2, 1, rt + 2, 16, "Diagnostics différents = groupes présents au moins une fois. Diagnostics comptabilisés = un "
                                    "compte par dossier et groupe, sans répétitions. Pas de patients uniques entre logiciels.",
         couleur=C_GRIS, fond=C_CLAIR, h=None, **ap)
    ws.row_dimensions[rt + 2].height = 40
    # Blocs par famille
    for num, f in enumerate(fams, start=1):
        r = pos[f]
        gs = sorted([x for x in G.groupes.values() if x["famille"] == f and x["total"] > 0],
                    key=lambda x: (-x["total"], x["groupe"].lower()))
        bloc(ws, r, 1, r, 12, f"{num:02d} / {f.upper()}", taille=12, gras=True, couleur=BLANC, fond=C_NAVY, h=None, **ap)
        c = bloc(ws, r, 13, r, 16, "Retour au sommaire", couleur=TEAL_C, fond=C_CLAIR, h=None, **ap)
        lien(c, "'Composition familles'!A15")
        bloc(ws, r + 1, 1, r + 1, 16, "Une ligne = un diagnostic regroupé. Les comptes sont ceux des dossiers, sans répétitions du même "
                                      "diagnostic dans le même dossier.", couleur=C_GRIS, fond=C_CLAIR, h=None, **ap)
        for c1, c2, t in ((1, 6, "INDICATEURS DE LA FAMILLE"), (7, 8, "MONKOLE / GLOBAL"), (9, 10, "CSMKL2"), (11, 12, "CHME"),
                          (13, 14, "SANS SITE"), (15, 16, "LOGICIELS / GLOBAL")):
            bloc(ws, r + 2, c1, r + 2, c2, t, taille=9, gras=True, couleur=BLANC, fond=TEAL_C, h=None, **ap)
        perims = [G, CS, CH, SS]
        lignes_ind = [("Nombre de diagnostics différents", [p.familles[f]["differents"] for p in perims], None, ("GPS", "Evolucare")),
                      ("Diagnostics comptabilisés = base des 100 %", [p.familles[f]["total"] for p in perims], None,
                       (G.familles[f]["gps"], G.familles[f]["evo"])),
                      ("Poids de cette famille dans l'ensemble du périmètre",
                       [p.familles[f]["total"] / p.total if p.total else 0 for p in perims], PCT, None)]
        for k, (lib, vals, fm, extra) in enumerate(lignes_ind):
            rr = r + 3 + k
            bloc(ws, rr, 1, rr, 6, lib, couleur=C_TXT, fond=C_CLAIR, h=None, **ap)
            for j, v in enumerate(vals):
                bloc(ws, rr, 7 + 2 * j, rr, 8 + 2 * j, v, gras=True, couleur=C_TXT, fond=C_CLAIR, fmt=fm, h=None, **ap)
            if extra:
                for j, v in enumerate(extra):
                    ecrire(ws, (rr, 15 + j), v, gras=True, couleur=C_TXT, fond=C_CLAIR, h=None, **ap)
            else:
                bloc(ws, rr, 15, rr, 16, "Pas de patients uniques", taille=9, couleur=C_GRIS, fond=C_CLAIR, h=None, **ap)
            ws.row_dimensions[rr].height = 26
        rh = r + 7
        ent = ["Diagnostic regroupé", None, None, None, None, None, "Monkole\ncomptabilisés", "% dans\nla famille", "CSMKL2\ncomptabilisés",
               "% famille\nCSMKL2", "CHME\ncomptabilisés", "% famille\nCHME", "Sans site\ncomptabilisés", "% famille\nsans site",
               "GPS", "Evolucare"]
        fusion(ws, rh, 1, rh, 6)
        for c, t in enumerate(ent, start=1):
            ecrire(ws, (rh, c), t, taille=9, gras=True, couleur=C_TXT, fond=C_CLAIR, h=None, **ap)
        ws.row_dimensions[rh].height = 42
        tf = [p.familles[f]["total"] for p in perims]
        for k, x in enumerate(gs):
            rr = rh + 1 + k
            fond = zebre(rr + 1, C_CLAIR)
            c = bloc(ws, rr, 1, rr, 6, x["groupe"], couleur=TEAL_C, fond=fond, h=None, **ap)
            if x["code"] in pos_dico:
                lien(c, f"'Dictionnaire'!A{pos_dico[x['code']]}")
            vs = [p.groupes.get(x["code"], {}).get("total", 0) for p in perims]
            for j, v in enumerate(vs):
                ecrire(ws, (rr, 7 + 2 * j), v, couleur=C_TXT, fond=fond, h="right", wrap=None, **ap)
                part = (v / tf[j]) if tf[j] else None
                ecrire(ws, (rr, 8 + 2 * j), part, couleur=C_TXT, fond=fond, fmt=PCT, h="right", wrap=None, **ap)
            ecrire(ws, (rr, 15), x["gps"], couleur=C_TXT, fond=fond, h="right", wrap=None, **ap)
            ecrire(ws, (rr, 16), x["evo"], couleur=C_TXT, fond=fond, h="right", wrap=None, **ap)
            ws.row_dimensions[rr].height = 29
        rtot = rh + 1 + len(gs)
        bloc(ws, rtot, 1, rtot, 6, "TOTAL DE LA FAMILLE / 100 %", gras=True, couleur=BLANC, fond=TEAL_C, h=None, **ap)
        for j, v in enumerate(tf):
            ecrire(ws, (rtot, 7 + 2 * j), v, gras=True, couleur=BLANC, fond=TEAL_C, h=None, wrap=None, **ap)
            ecrire(ws, (rtot, 8 + 2 * j), (1.0 if v else 0) if j < 3 else (1.0 if v else 0), gras=True, couleur=BLANC, fond=TEAL_C,
                   fmt=PCT, h=None, wrap=None, **ap)
        ecrire(ws, (rtot, 15), G.familles[f]["gps"], gras=True, couleur=BLANC, fond=TEAL_C, h=None, wrap=None, **ap)
        ecrire(ws, (rtot, 16), G.familles[f]["evo"], gras=True, couleur=BLANC, fond=TEAL_C, h=None, wrap=None, **ap)
        ws.row_dimensions[rtot].height = 29
        if gs:
            for col in "HJLN":
                ws.conditional_formatting.add(f"{col}{rh + 1}:{col}{rtot - 1}",
                                              DataBarRule(start_type="num", start_value=0, end_type="num", end_value=1, color="54A6A6"))
        bloc(ws, rtot + 1, 1, rtot + 1, 16, "Une famille vide dans un site ne forme pas un 100 % : parts internes laissees vides. Petits "
                                            "écarts possibles a l'affichage par arrondi.", taille=9, couleur=C_GRIS, fond=C_CLAIR,
             h=None, **ap)
        ws.row_dimensions[rtot + 1].height = 28
        hauteurs(ws, {r: 29, r + 1: 28, r + 2: 25})
    hauteurs(ws, {1: 24, 2: 14, 3: 23, 4: 24, 6: 23, 7: 20, 9: 22, 10: 18, 11: 18, 12: 18, 13: 24, 15: 26, 16: 60})
    return ws, pos


# ----------------------------------------------------------------------------------------------
def diagnostics_site(wb, R, nom, P, titre, onglet, pos_dico, masquer=False):
    ws = wb.create_sheet(nom)
    mise_en_page(ws, onglet, zoom=85, figer="E8")
    bloc(ws, 1, 1, 2, 13, titre, taille=21, gras=True, couleur=BLANC, fond=NAVY)
    bloc(ws, 3, 1, 3, 13, f"{periode_txt(R)} | GPS + Evolucare | Tous les diagnostics regroupés sont conservés, même rares",
         taille=11, couleur=GRIS, fond=CLAIR)
    bloc(ws, 4, 1, 4, 13, "Comptage : un diagnostic par dossier et logiciel, une seule fois sur la période. Hypothèses séparées. "
                          "Le detail quotidien se déplie avec +.", taille=11, couleur=AL_T, fond=AL_F)
    gs = tri_groupes([x for x in P.groupes.values() if x["total"] > 0 or x["hyp"] > 0])
    cols = ["Code interne", "Famille clinique", "Diagnostic / groupe proposé", "Nature", "GPS", "Evolucare",
            "Diagnostics\ncomptabilisés", "Part du total", "Hypothèses\nseules", "Jours présents", "Pic / jour", "1er jour du pic",
            "Correspondance"] + [f"{JOURS_COURTS[j.weekday()]} {j:%d/%m}" for j in R.jours]
    for c, t in enumerate(cols, start=1):
        ecrire(ws, (7, c), t, gras=True, couleur=BLANC, fond=NAVY, h=None)
    ecrire(ws, (6, 3), "TOTAL DU PÉRIMÈTRE", gras=True, couleur=NAVY, fond=BLANC, h=None, wrap=None)
    for c, v in ((5, sum(x["gps"] for x in gs)), (6, sum(x["evo"] for x in gs)), (7, sum(x["total"] for x in gs)),
                 (9, sum(x["hyp"] for x in gs))):
        ecrire(ws, (6, c), v, couleur=TXT, fond=BLANC, fmt=NB, h=None, wrap=None)
    for i, x in enumerate(gs):
        r = 8 + i
        f = zebre(r)
        vals = [x["code"], x["famille"], x["groupe"], x["nature"]]
        for c, v in enumerate(vals, start=1):
            ecrire(ws, (r, c), v, couleur=TXT, fond=f, h=None, wrap=c > 1)
        for c, v in ((5, x["gps"]), (6, x["evo"]), (7, x["total"]), (9, x["hyp"])):
            ecrire(ws, (r, c), v, couleur=TXT, fond=f, fmt=NB, h=None, wrap=None)
        ecrire(ws, (r, 8), x["total"] / P.total if P.total else 0, couleur=TXT, fond=f, fmt=PCT, h=None, wrap=None)
        ecrire(ws, (r, 10), x["jours_presents"], couleur=TXT, fond=f, fmt="0", h=None, wrap=None)
        ecrire(ws, (r, 11), x["pic"], couleur=TXT, fond=f, fmt="0", h=None, wrap=None)
        ecrire(ws, (r, 12), x["date_pic"], couleur=TXT, fond=f, fmt="dd/mm/yyyy", h=None, wrap=None)
        c = ecrire(ws, (r, 13), "Voir textes", couleur=TEAL_C, fond=f, h=None, wrap=None)
        if x["code"] in pos_dico:
            lien(c, f"'Dictionnaire'!A{pos_dico[x['code']]}")
        for k, j in enumerate(R.jours):
            ecrire(ws, (r, 14 + k), x["jours"].get(j, 0), couleur=TXT, fond=f, fmt="#,##0;[Red]-#,##0;–", h=None, wrap=None)
        ws.row_dimensions[r].height = 32
    der = 7 + len(gs)
    if gs:
        ws.conditional_formatting.add(f"H8:H{der}", DataBarRule(start_type="min", end_type="max", color="54A6A6"))
    ws.auto_filter.ref = f"A7:{L(len(cols))}{der}"
    for c, w in zip("ABCDEFGHIJKLM", (12, 28, 53, 22, 10, 11, 15, 15, 14, 11, 11, 14, 17)):
        ws.column_dimensions[c].width = w
    for k in range(len(R.jours)):
        cd = ws.column_dimensions[L(14 + k)]
        cd.width = 9
        cd.outlineLevel = 1
        cd.hidden = True
    hauteurs(ws, {1: 24, 2: 22, 3: 27, 4: 38, 5: 21, 6: 21, 7: 42})
    if masquer:
        ws.sheet_state = "hidden"
    return ws


# ----------------------------------------------------------------------------------------------
def dictionnaire(wb, R):
    """Une ligne par formulation et diagnostic regroupé, avec l'usage dans les exports de la période."""
    ws = wb.create_sheet("Dictionnaire")
    mise_en_page(ws, "72549B", zoom=85, figer="C7")
    d = R.dico
    bloc(ws, 1, 1, 2, 16, "DICTIONNAIRE / REGROUPEMENTS PROPOSÉS", taille=21, gras=True, couleur=BLANC, fond=NAVY)
    bloc(ws, 3, 1, 3, 16, "Texte original > diagnostic regroupé > famille | Regroupements conservés; nouvelles formulations "
                          "proposées pour validation", taille=11, couleur=GRIS, fond=CLAIR)
    bloc(ws, 4, 1, 4, 16, "Filtrer par groupe, famille ou statut. Une phrase composite peut apparaître plusieurs fois : ne pas "
                          "additionner la colonne « lignes source ». Les textes originaux complets restent dans Base sources.",
         taille=11, couleur=AL_T, fond=AL_F)
    cols = ["ID libellé", "Libellé original représentatif", "Groupe proposé", "Famille", "Nature du groupe", "Statut dans le texte",
            "Lignes source", "Logiciels", "Variantes orthographiques", "GPS longueur 50", "Méthode / prudence", "Code interne",
            "Validation médicale", "Commentaire de validation", "Sites sources", "Versions exactes"]
    for c, t in enumerate(cols, start=1):
        ecrire(ws, (6, c), t, gras=True, couleur=BLANC, fond=NAVY, h=None)
    usage = defaultdict(list)
    for s in R.sources:
        usage[s["id_dico"]].append(s)
    entrees = [(i, d.entrees[i], False) for i in d.entrees]
    jj = f"{R.fin:%d-%m}"
    for t, nv in R.nouvelles.items():
        lignes = nv["lignes"] if nv["complet"] else [(DD.CODE_NOUVEAU, "À clarifier")]
        meth = (f"NOUVELLE FORMULATION / {jj} : proposition automatique (morceaux déjà connus). À valider."
                if nv["complet"] else f"NOUVELLE FORMULATION / {jj} : non classée automatiquement. À classer dans le dictionnaire.")
        entrees.append((nv["id"], {"libelle": t, "versions": [t],
                                   "lignes": [(c, s, meth, "A valider", None) for c, s in lignes]}, True))
    entrees.sort(key=lambda e: e[0])
    pos = {}
    r = 7
    for i, e, nouveau in entrees:
        u = usage.get(i, [])
        logiciels = ", ".join(sorted({s["logiciel"] for s in u})) or None
        sites = ", ".join(sorted({s["site"] for s in u})) or None
        variantes = len({s["texte"] for s in u})
        l50 = sum(1 for s in u if s["logiciel"] == "GPS" and len(s["texte"]) == 50)
        versions = None if e["versions"] == [""] else "\n".join(e["versions"])
        for code, statut, meth, valid, comm in e["lignes"]:
            lib, fam, nat = d.groupes.get(code, (code, "À clarifier", "Qualité / ambiguïté"))
            pos.setdefault(code, r)
            fond = "FFF2CB" if nouveau else AL_F
            vals = [i, e["libelle"], lib, fam, nat, statut, len(u), logiciels, variantes, l50, meth, code, valid, comm,
                    sites, versions]
            for c, v in enumerate(vals, start=1):
                bleu = c in (13, 14)
                ecrire(ws, (r, c), v, couleur="0064CC" if bleu else TXT, fond=fond, h=None,
                       v="top" if c in (2, 3, 4, 5, 6, 11, 14, 16) else "center", wrap=True if c in (2, 3, 4, 5, 6, 11, 14, 16) else None,
                       police="Aptos" if bleu else "Calibri")
            ws.row_dimensions[r].height = 48
            r += 1
    ws.auto_filter.ref = f"A6:P{r - 1}"
    for c, w in zip("ABCDEFGHIJKLMNOP", (11, 78, 47, 30, 21, 26, 12, 18, 13, 13, 40, 13, 20, 50, 25, 60)):
        ws.column_dimensions[c].width = w
    hauteurs(ws, {1: 24, 2: 22, 3: 27, 4: 38, 5: 21, 6: 32})
    return ws, pos
