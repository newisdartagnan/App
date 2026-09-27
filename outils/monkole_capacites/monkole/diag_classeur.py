"""Classeur « Diagnostics et composition des familles » (présentation de la version retenue)."""
from collections import defaultdict

from openpyxl.chart import LineChart, PieChart, Reference, Series
from openpyxl.chart.label import DataLabelList
from openpyxl.chart.series import DataPoint
from openpyxl.formatting.rule import DataBarRule
from openpyxl.utils import get_column_letter as L

from . import diag_dictionnaire as DD
from .diag_calculs import SANS_SITE
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


def synthese(wb, R, nom, P, titre, onglet, lignes_source, note_source, serie_cols, pos_compo, pos_dico):
    ws = wb.create_sheet(nom)
    mise_en_page(ws, onglet, zoom=80, figer="A4")
    per = periode_txt(R)
    entete_commun(ws, R, titre, f"Médecin directeur | {per} | GPS + Evolucare | Diagnostics comptabilisés dans les dossiers",
                  [("CSMKL2 / synthèse", "'CSMKL2'!A1"), ("CHME / synthèse", "'CHME'!A1"),
                   ("Dictionnaire / correspondances", "'Dictionnaire'!A1"), ("Règles / contrôles", "'Notez bien'!A1")])
    sites_ok = not any(s["site"] == SANS_SITE for s in R.sources)
    bloc(ws, 6, 1, 7, 16, "REGROUPEMENTS PROPOSÉS : les formulations équivalentes sont rapprochées. Les hypothèses restent à part. "
         "Validation médicale requise. " + ("Tous les sites sont renseignés dans cet export." if sites_ok
                                          else "Des lignes sans site restent présentées à part."),
         taille=11, gras=True, couleur=AL_T, fond=AL_F)
    kpis = [("DOSSIERS DES LOGICIELS", len(P.dossiers), "Pas de patients uniques entre logiciels"),
            ("DIAGNOSTICS COMPTABILISÉS", P.total, f"{len(P.dossiers_cliniques):,} dossiers avec au moins un diagnostic comptabilisé".replace(",", " ")),
            ("DIAGNOSTICS RÉCURRENTS", P.recurrents, f"Sur {P.differents} diagnostics différents après regroupement"),
            ("HYPOTHÈSES SEULES", len(P.hyp), "Exclues du disque principal")]
    for i, (t, v, com) in enumerate(kpis):
        c1 = 1 + 4 * i
        bloc(ws, 9, c1, 9, c1 + 3, t, taille=11, gras=True, couleur=BLANC, fond=TEAL, h=None)
        bloc(ws, 10, c1, 12, c1 + 3, v, taille=29, gras=True, couleur=TEAL, fond=BLANC, fmt=NB, h="center", wrap=None)
        bloc(ws, 13, c1, 13, c1 + 3, com, couleur=GRIS, fond=CLAIR, h=None)
    bloc(ws, 15, 1, 15, 16, "Un diagnostic répété dans le même dossier et logiciel ne compte qu'une fois. Récurrent = au moins deux "
                            "dossiers, pas une rechute.", taille=11, couleur=GRIS, fond=CLAIR)
    bloc(ws, 17, 1, 17, 8, "01 / PROPORTION PAR FAMILLE CLINIQUE", taille=11, gras=True, couleur=BLANC, fond=NAVY)
    bloc(ws, 17, 9, 17, 16, "02 / LES 12 DIAGNOSTICS LES PLUS FRÉQUENTS", taille=11, gras=True, couleur=BLANC, fond=NAVY)
    # Top 12
    bloc(ws, 19, 9, 19, 13, "Diagnostic / situation clinique", gras=True, couleur=TXT, fond=CLAIR)
    for c, t in ((14, "Comptés"), (15, "Part"), (16, "Hyp.\nseules")):
        ecrire(ws, (19, c), t, gras=True, couleur=TXT, fond=CLAIR)
    top = tri_groupes([x for x in P.groupes.values() if x["total"] > 0])[:12]
    for i, x in enumerate(top):
        r = 20 + i
        c = bloc(ws, r, 9, r, 13, x["groupe"], couleur=TEAL_C, fond=zebre(r))
        if x["code"] in pos_dico:
            lien(c, f"'Dictionnaire'!A{pos_dico[x['code']]}")
        ecrire(ws, (r, 14), x["total"], couleur=TXT, fond=BLANC, fmt=NB, h="right", wrap=None)
        ecrire(ws, (r, 15), x["total"] / P.total if P.total else 0, couleur=TXT, fond=BLANC, fmt=PCT, h="right", wrap=None)
        ecrire(ws, (r, 16), x["hyp"], couleur=TXT, fond=BLANC, fmt=NB, h="right", wrap=None)
    if top:
        ws.conditional_formatting.add(f"N20:N{19 + len(top)}", DataBarRule(start_type="min", end_type="max", color="54A6A6"))
    bloc(ws, 33, 9, 34, 16, f"Tous les {P.differents} diagnostics différents sont détaillés. Sans marqueur de doute ne signifie pas "
                            "diagnostic confirmé.", couleur=AL_T, fond=AL_F)
    bloc(ws, 36, 9, 36, 16, "03 / DOSSIERS ET DIAGNOSTICS PAR SOURCE", taille=11, gras=True, couleur=BLANC, fond=NAVY)
    bloc(ws, 37, 9, 37, 12, "Site / logiciel", gras=True, couleur=TXT, fond=CLAIR)
    bloc(ws, 37, 13, 37, 14, "Dossiers", gras=True, couleur=TXT, fond=CLAIR)
    bloc(ws, 37, 15, 37, 16, "Diagnostics\ncomptés", gras=True, couleur=TXT, fond=CLAIR)
    for i, (lib, dos, dia) in enumerate(lignes_source):
        r = 38 + i
        bloc(ws, r, 9, r, 12, lib, taille=11, couleur=TXT, fond=zebre(r))
        bloc(ws, r, 13, r, 14, dos, couleur=TXT, fond=BLANC, fmt=NB)
        bloc(ws, r, 15, r, 16, dia, couleur=TXT, fond=BLANC, fmt=NB)
    if note_source:
        bloc(ws, 40, 9, 40, 16, note_source, taille=11, couleur=TXT, fond=zebre(40))
    # Familles
    bloc(ws, 40, 1, 40, 8, "16 FAMILLES / CLIQUER POUR LA COMPOSITION", gras=True, couleur=BLANC, fond=NAVY)
    bloc(ws, 41, 1, 41, 4, "Famille clinique", gras=True, couleur=TXT, fond=CLAIR)
    bloc(ws, 41, 5, 41, 6, "Diagnostics\ndifférents", gras=True, couleur=TXT, fond=CLAIR, h="center")
    ecrire(ws, (41, 7), "Diagnostics\ncomptés", gras=True, couleur=TXT, fond=CLAIR, h="center")
    ecrire(ws, (41, 8), "Part", gras=True, couleur=TXT, fond=CLAIR, h="center")
    fams = tri_familles(P)
    for i, f in enumerate(fams):
        r = 42 + i
        x = P.familles[f]
        c = bloc(ws, r, 1, r, 4, f, gras=True, couleur=COULEURS[f], fond=zebre(r))
        lien(c, f"'Composition familles'!A{pos_compo[f]}")
        bloc(ws, r, 5, r, 6, x["differents"], couleur=TXT, fond=BLANC, fmt='#,##0;[Red]-#,##0;"—"', h="center")
        ecrire(ws, (r, 7), x["total"], couleur=TXT, fond=BLANC, fmt=NB, h=None, wrap=None)
        ecrire(ws, (r, 8), x["total"] / P.total if P.total else 0, couleur=TXT, fond=BLANC, fmt=PCT, h=None, wrap=None)
    rt = 42 + len(fams)
    bloc(ws, rt, 1, rt, 4, "TOTAL / DIAGNOSTICS", taille=11, gras=True, couleur=BLANC, fond=TEAL)
    bloc(ws, rt, 5, rt, 6, sum(P.familles[f]["differents"] for f in fams), taille=11, gras=True, couleur=BLANC, fond=TEAL, fmt=NB, h="center")
    ecrire(ws, (rt, 7), sum(P.familles[f]["total"] for f in fams), taille=11, gras=True, couleur=BLANC, fond=TEAL, fmt=NB)
    ecrire(ws, (rt, 8), 1 if P.total else 0, taille=11, gras=True, couleur=BLANC, fond=TEAL, fmt=PCT)
    bloc(ws, rt + 1, 1, rt + 1, 8, "Cliquer sur une famille : diagnostics, nombres et parts internes à 100 %", couleur=GRIS, fond=CLAIR)
    bloc(ws, rt + 2, 1, rt + 3, 16, "Le disque inclut diagnostics cités, symptômes et situations cliniques. Une grossesse ou une "
                                    "naissance n'est pas une maladie. Ce ne sont pas des proportions de patients.", couleur=GRIS, fond=CLAIR)
    bloc(ws, 42, 9, 42, 16, "04 / DIAGNOSTICS COMPTABILISÉS PAR JOUR", taille=11, gras=True, couleur=BLANC, fond=NAVY)
    # Graphiques
    pie = PieChart()
    pie.height, pie.width = 10.5, 14.5
    data = Reference(ws, min_col=7, min_row=42, max_row=41 + len(fams))
    cats = Reference(ws, min_col=1, min_row=42, max_row=41 + len(fams))
    pie.add_data(data, titles_from_data=False)
    pie.set_categories(cats)
    s = pie.series[0]
    for i, f in enumerate(fams):
        pt = DataPoint(idx=i)
        pt.graphicalProperties.solidFill = COULEURS[f]
        pt.graphicalProperties.line.solidFill = "FFFFFF"
        s.dPt.append(pt)
    s.dLbls = DataLabelList()
    s.dLbls.showPercent = True
    s.dLbls.showVal = False
    s.dLbls.showCatName = False
    s.dLbls.showSerName = False
    s.dLbls.showLeaderLines = True
    pie.legend = None           # les couleurs des familles figurent dans le tableau sous le disque
    ws.add_chart(pie, "A18")
    cal = wb["Calculs"]
    lc = LineChart()
    lc.title = "Diagnostics comptabilises par jour"
    lc.height, lc.width = 7.5, 15
    lc.legend.position = "b"
    for col, t in serie_cols:
        se = Series(Reference(cal, min_col=col, min_row=R.ligne_quotidien, max_row=R.ligne_quotidien + len(R.jours) - 1), title=t)
        lc.series.append(se)
    lc.set_categories(Reference(cal, min_col=14, min_row=R.ligne_quotidien, max_row=R.ligne_quotidien + len(R.jours) - 1))
    ws.add_chart(lc, "I43")
    # Points de revue
    r0 = rt + 5
    bloc(ws, r0, 1, r0, 16, "05 / POINTS A PORTER A LA REVUE MÉDICALE", taille=11, gras=True, couleur=BLANC, fond=NAVY)
    g = P.groupes
    val = lambda code, k="total": g.get(code, {}).get(k, 0)
    sites_txt = "Aucun site manquant." if sites_ok else f"{sum(1 for s in R.sources if s['site'] == SANS_SITE)} lignes sans site."
    pts = [("CHRONIQUES / SUIVI", f"Hypertension : {val('HTA')} dossiers; diabète de type 2 : {val('DM2')}. Lire leur répartition par "
                                  "site et conserver les associations, sans additionner en patients uniques."),
           ("PROFIL / ACTIVITÉ", f"Grossesse / suivi prénatal : {val('PREG')} diagnostics comptabilisés. Les situations obstétricales "
                                 "et les maladies restent distinguées dans les détails."),
           ("DOUTE / VÉRIFICATION", f"Paludisme cité sans doute explicite : {val('PAL')}; hypothèses seules : {val('PAL', 'hyp')}. Ne pas "
                                    "les présenter comme des cas confirmés."),
           ("QUALITÉ / PRIORITÉ", f"{P.lignes_non_exploitables} lignes sans diagnostic exploitable; {P.lignes_a_clarifier} avec un "
                                  f"fragment ou sigle à clarifier. {P.lignes_gps_50} textes GPS de 50 caractères : vérifier une "
                                  f"éventuelle troncature. {sites_txt}")]
    for i, (lib, t) in enumerate(pts):
        r = r0 + 2 + 2 * i
        bloc(ws, r, 1, r + 1, 4, lib, gras=True, couleur=TEAL, fond=CLAIR)
        bloc(ws, r, 5, r + 1, 16, t, taille=11, couleur=TXT, fond=zebre(r))
    r = r0 + 11
    rep = P.somme_jours - P.total
    bloc(ws, r, 1, r + 1, 16, f"Somme des jours : {P.somme_jours}; diagnostics sur la période : {P.total}. Les {rep} répétitions "
                              "entre jours ne sont pas ajoutées au total. Ces exports remplacent les précédents, sans cumul.",
         couleur=GRIS, fond=CLAIR)
    h = {1: 24, 2: 22, 3: 27}
    h.update({r: 21 for r in range(4, 79)})
    h.update({9: 31, 13: 32, 15: 32, 17: 25, 19: 28, 33: 30, 36: 25, 37: 34, 40: 25, 41: 34, 42: 25, 59: 30, 60: 30, 63: 25, 74: 30})
    h.update({r: 29 for r in range(20, 32)})
    h.update({r: 23 for r in range(43, 58)})
    h[rt] = 27
    hauteurs(ws, h)
    return ws


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
