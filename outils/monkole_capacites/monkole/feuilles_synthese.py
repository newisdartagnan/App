"""Feuilles de synthèse : Dashboard, CSMKL2, CHME."""
from collections import defaultdict

from openpyxl.chart import LineChart, Reference, Series
from openpyxl.chart.axis import ChartLines
from openpyxl.comments import Comment
from openpyxl.drawing.spreadsheet_drawing import AnchorMarker, TwoCellAnchor
from openpyxl.styles import Alignment, Font, PatternFill

from . import visites as V
from .calculs import indicateurs
from .feuilles_detail import libelle_periode
from .styles import (ALERTE_F, ALERTE_T, BLANC, CLAIR, DATE, DEC, GRIS, NAVY, NB, PCT, ROUGE_F, ROUGE_T, TEAL, TEXTE, ZEBRE,
                     alerte, bandeau, ecrire, lien, mise_en_page)
from .texte import fr_nombre_espace as fr, fr_pct, nom_jour


def fr_dec(x):
    return f"{x:.1f}".replace(".", ",")


def zebre(r):
    return ZEBRE if r % 2 == 0 else BLANC


def fusion(ws, r, c1, c2, r2=None):
    if c2 > c1 or (r2 and r2 > r):
        ws.merge_cells(start_row=r, start_column=c1, end_row=r2 or r, end_column=c2)


def entete_tableau(ws, r, colonnes, hauteur=36, taille=10):
    """colonnes : liste de (col_debut, col_fin, texte)."""
    for c1, c2, t in colonnes:
        fusion(ws, r, c1, c2)
        for c in range(c1, c2 + 1):
            ecrire(ws, (r, c), t if c == c1 else None, taille=taille, gras=True, couleur=BLANC, fond=NAVY)
    ws.row_dimensions[r].height = hauteur


def cellule(ws, r, c1, valeur, fmt=NB, c2=None, h="right", fond=None, gras=False, couleur=TEXTE, taille=10):
    fond = fond or zebre(r)
    if c2:
        fusion(ws, r, c1, c2)
        for c in range(c1 + 1, c2 + 1):
            ecrire(ws, (r, c), taille=taille, gras=gras, couleur=couleur, fond=fond, fmt=fmt, h=h)
    if isinstance(valeur, str) and fmt in (NB, PCT, DEC):
        fmt = None
    return ecrire(ws, (r, c1), valeur, taille=taille, gras=gras, couleur=couleur, fond=fond, fmt=fmt, h=h)


def kpi(ws, c1, c2, titre, valeur, commentaire, fmt=NB, taille=27, lien_cible=None, lien_texte=None):
    if lien_texte:
        fusion(ws, 3, c1, c2)
        lien(ws, (3, c1), lien_texte, lien_cible)
        for c in range(c1 + 1, c2 + 1):
            ecrire(ws, (3, c), fond=ZEBRE)
    fusion(ws, 4, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (4, c), titre if c == c1 else None, gras=True, couleur=BLANC, fond=TEAL)
    fusion(ws, 5, c1, c2, r2=6)
    ecrire(ws, (5, c1), valeur, taille=taille, gras=True, couleur=TEAL, fond=BLANC, fmt=fmt if not isinstance(valeur, str) else None,
           h="center", wrap=None)
    fusion(ws, 7, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (7, c), commentaire if c == c1 else None, taille=9, couleur=GRIS, fond=CLAIR, h="center")


def haut_de_page(ws, titre, sous_titre, der):
    bandeau(ws, 1, titre, der, taille=19, hauteur=32)
    fusion(ws, 2, 1, der)
    ecrire(ws, "A2", sous_titre, fond=CLAIR)
    for r, h in ((2, 28), (3, 24), (4, 26), (5, 28), (6, 15), (7, 31)):
        ws.row_dimensions[r].height = h


def section(ws, r, texte, c1, c2, taille=11, hauteur=26):
    fusion(ws, r, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (r, c), texte if c == c1 else None, taille=taille, gras=True, couleur=BLANC, fond=NAVY)
    ws.row_dimensions[r].height = hauteur


def note_bloc(ws, r, texte, c1, c2, alerte=False, taille=10, hauteur=None):
    fusion(ws, r, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (r, c), texte if c == c1 else None, taille=taille, couleur=ALERTE_T if alerte else GRIS,
               fond=ALERTE_F if alerte else CLAIR)
    if hauteur:
        ws.row_dimensions[r].height = hauteur


def graphique(ws, ancre, source, premiere_ligne, n, series, col_etiquette, titre, axe="Visites", hauteur=7.5, largeur=15,
              zone=None):
    """zone = (col1, ligne1, col2, ligne2) : le graphique occupe exactement ces cellules (sans déborder sur les voisines)."""
    ch = LineChart()
    if titre:
        ch.title = titre
    if axe:
        ch.y_axis.title = axe
    ch.height = hauteur
    ch.width = largeur
    ch.legend.position = "b"
    ch.x_axis.delete = False        # axes visibles dans Excel (masqués par défaut sinon)
    ch.y_axis.delete = False
    ch.y_axis.number_format = "#,##0"
    ch.y_axis.majorGridlines = ChartLines()
    if zone:
        c1, r1, c2, r2 = zone
        ancre = TwoCellAnchor(_from=AnchorMarker(col=c1 - 1, row=r1 - 1, colOff=38100, rowOff=19050),
                              to=AnchorMarker(col=c2, row=r2, colOff=0, rowOff=0))
    for col, nom in series:
        data = Reference(source, min_col=col, min_row=premiere_ligne, max_row=premiere_ligne + n - 1)
        s = Series(data, title=nom)
        s.smooth = False
        ch.series.append(s)
    ch.set_categories(Reference(source, min_col=col_etiquette, min_row=premiere_ligne, max_row=premiere_ligne + n - 1))
    ws.add_chart(ch, ancre)


def colorer(ws, r, c, x, R, force=False):
    """Applique la couleur d'alerte (orange / vert / rouge) à une cellule déjà écrite."""
    fond, coul = (ROUGE_F, ROUGE_T) if force else alerte(x, R.seuil_bas, R.seuil_haut)
    if fond:
        cel = ws.cell(row=r, column=c)
        cel.fill = PatternFill("solid", fgColor=fond)
        f = cel.font
        cel.font = Font(name=f.name, size=f.size, bold=True, color=coul)


def titre_periode(R):
    return f"{libelle_periode(R, '-')} {R.fin.year}"


# ----------------------------------------------------------------------------------------------
# CSMKL2
# ----------------------------------------------------------------------------------------------
def feuille_csmkl2(wb, R, positions_actes, arbre_actes, onglet):
    ws = wb.create_sheet("CSMKL2")
    mise_en_page(ws, onglet, zoom=85, figer="A4")
    der = 13
    ws.column_dimensions["A"].width = 30
    for c in "BCDEFGHIJKLM":
        ws.column_dimensions[c].width = 12
    ind = indicateurs(R, "CSMKL2", V.PRINCIPALE)
    lignes = R.jours_act[("CSMKL2", V.PRINCIPALE)]
    jours_plus = sum(1 for l in lignes if l["cab"] > R.cabinets_csmkl2)
    haut_de_page(ws, "CSMKL2 / CAPACITÉ DES ACTIVITÉS PRINCIPALES",
                 f"{titre_periode(R)} • GPS + Evolucare • MAISON ROSE et actes conservés en complément", der)
    kpi(ws, 1, 3, "CAPACITÉ UTILISÉE", ind["util"] if ind["util"] is not None else "N/D", f"sur {ind['cap']:,} visites théoriques",
        fmt=PCT, lien_cible="'Dashboard'!A1", lien_texte="Dashboard")
    fond, coul = alerte(ind["util"], R.seuil_bas, R.seuil_haut)
    if fond:
        ws.cell(row=5, column=1).fill = PatternFill("solid", fgColor=fond)
        ws.cell(row=5, column=1).font = Font(name="Calibri", size=27, bold=True, color=coul)
    kpi(ws, 4, 6, "VISITES PRINCIPALES", ind["total"], "Toutes les visites principales, isolées incluses",
        lien_cible="'CSMKL2 médecins'!A1", lien_texte="Médecins par jour")
    kpi(ws, 7, 9, "JOURS AU-DELÀ CAPACITÉ", ind["jour_audela"], f"{R.cap_jour} × cabinets principaux comptés / jour",
        lien_cible="'CSMKL2 jours'!A1", lien_texte="Jours par médecin")
    kpi(ws, 10, 13, f"JOURS AVEC PLUS DE {R.cabinets_csmkl2} CABINETS", jours_plus,
        f"Maximum : {max((l['cab'] for l in lignes), default=0)} cabinets comptés le même jour",
        lien_cible="'CSMKL2 actes'!A1", lien_texte="Actes détaillés")
    section(ws, 9, "VISITES PRINCIPALES ET CAPACITÉ DU MODÈLE", 1, der)
    for r in range(10, 27):
        ws.row_dimensions[r].height = 20
    graphique(ws, "A10", wb["_Jours"], R.lignes_jours[("CSMKL2", V.PRINCIPALE)], R.ndays, [(5, "Visites"), (12, "Capacité")], 20,
              titre_periode(R).replace(f" {R.fin.year}", ""))
    dp = ind["date_pic"]
    note_bloc(ws, 27, f"Pic : {ind['pic_jour']} visites le {dp.strftime('%d/%m') if dp else '–'} = {ind['gps_pic']} GPS + "
                      f"{ind['evo_pic']} Evolucare. {ind['isolees']} visites principales isolées et {ind['sans']} sans médecin restent comptées.",
              1, der, hauteur=34)
    # Lecture quotidienne
    section(ws, 29, "LECTURE QUOTIDIENNE / ACTIVITÉ PRINCIPALE", 1, der)
    cols = ["Jour", "GPS", "Evolucare", "Visites réelles", "Cabinets", "Capacité", "Utilisation", "Marge cumulée",
            f"Intervenants >{R.cap_jour}", "Sans médecin", "Visites isolées"]
    entete_tableau(ws, 30, [(i, i, t) for i, t in enumerate(cols, start=1)] + [(12, 13, "Cabinets toutes activités")])
    r = 31
    for l in lignes:
        j = l["jour"]
        cellule(ws, r, 1, f"{nom_jour(j)} {j.strftime('%d/%m')}", h="left", fmt=None)
        for c, v in enumerate([l["gps"], l["evo"], l["total"], l["cab"], l["cap"]], start=2):
            cellule(ws, r, c, v)
        cellule(ws, r, 7, l["util"] if l["util"] is not None else "N/D", fmt=PCT)
        colorer(ws, r, 7, l["util"], R)
        for c, v in enumerate([l["marge"], l["depass"], l["sans"], l["isolees"]], start=8):
            cellule(ws, r, c, v)
        cellule(ws, r, 12, R.cab_site["CSMKL2"][j], c2=13)
        if l["cab"] > R.cabinets_csmkl2:
            colorer(ws, r, 5, 1, R, force=True)
        ws.row_dimensions[r].height = 26
        r += 1
    # Charge individuelle : moyenne = visites / jours actifs ; classement sur la moyenne
    r += 2
    section(ws, r, "CHARGE INDIVIDUELLE / ACTIVITÉ PRINCIPALE", 1, der)
    r += 1
    cols = ["Intervenant", "Consultation", "Avant 1 sem.", "Après 1 sem.", "Visites", "Jours actifs", "Moyenne / jour actif",
            "Min / jour actif", "Max / jour", "GPS au pic", "Evo au pic", "Date du pic", f"Jours >{R.cap_jour}"]
    entete_tableau(ws, r, [(i, i, t) for i, t in enumerate(cols, start=1)])
    r += 1
    md = R.medjour[("CSMKL2", V.PRINCIPALE)]
    par_med = defaultdict(dict)
    for (j, m), c in md.items():
        par_med[m][j] = c
    total = {m: sum(c["total"] for c in jm.values()) for m, jm in par_med.items()}
    moyenne = {m: total[m] / len(par_med[m]) for m in par_med}
    ordre = sorted(par_med, key=lambda m: (-moyenne[m], -total[m], m))
    for m in ordre:
        jours_m = par_med[m]
        pic_j = min(jours_m, key=lambda j: (-jours_m[j]["total"], j))
        vals = [sum(c["cons"] for c in jours_m.values()), sum(c["avant"] for c in jours_m.values()),
                sum(c["apres"] for c in jours_m.values()), total[m], len(jours_m)]
        cellule(ws, r, 1, m, h="left", fmt=None)
        for c, v in enumerate(vals, start=2):
            cellule(ws, r, c, v)
        cellule(ws, r, 7, moyenne[m], fmt="0.0", gras=True)
        for c, v in enumerate([min(c["total"] for c in jours_m.values()), jours_m[pic_j]["total"], jours_m[pic_j]["gps"],
                               jours_m[pic_j]["evo"]], start=8):
            cellule(ws, r, c, v)
        cellule(ws, r, 12, pic_j, fmt=DATE)
        cellule(ws, r, 13, sum(c["audela"] for c in jours_m.values()))
        ws.row_dimensions[r].height = 30
        r += 1
    # MAISON ROSE
    r += 2
    section(ws, r, "MAISON ROSE / HORS CAPACITÉ PRINCIPALE", 1, der)
    r += 1
    entete_tableau(ws, r, [(1, 1, "Activité / UF"), (2, 2, "GPS"), (3, 3, "Evolucare"), (4, 4, "Total visites")])
    for c in range(5, der + 1):
        ecrire(ws, (r, c), gras=True, couleur=BLANC, fond=NAVY)
    r += 1
    mr = defaultdict(lambda: [0, 0])
    for v in R.visites:
        if v["site"] == "CSMKL2" and v["activite"] == V.SECONDAIRE:
            mr[v["specialite"]][0 if v["logiciel"] == "GPS" else 1] += 1
    for sp in sorted(mr, key=lambda s: s.lower()):
        g, e = mr[sp]
        cellule(ws, r, 1, sp, h="left", fmt=None)
        cellule(ws, r, 2, g)
        cellule(ws, r, 3, e)
        cellule(ws, r, 4, g + e)
        for c in range(5, der + 1):
            ecrire(ws, (r, c), fond=zebre(r), h="right")
        ws.row_dimensions[r].height = 24
        r += 1
    # Actes
    r += 2
    section(ws, r, "ACTES / PRESTATIONS DU SITE — SOURCES ET DATES DISTINGUÉES", 1, der)
    r += 1
    entete_tableau(ws, r, [(1, 1, "Spécialité"), (2, 2, "GPS / Date_V"), (3, 3, "Evo / DATEHEURE"), (4, 4, "Dossiers GPS"),
                           (5, 5, "Dossiers Evo")])
    for c in range(6, der + 1):
        ecrire(ws, (r, c), gras=True, couleur=BLANC, fond=NAVY)
    r += 1
    for sp in arbre_actes:
        a = sp["agg"]
        c = cellule(ws, r, 1, sp["nom"], h="left", fmt=None)
        c.hyperlink = f"#'CSMKL2 actes'!A{positions_actes[sp['nom']]}"
        for col, v in enumerate([a["gps"], a["evo"], a["dos_gps"], a["dos_evo"]], start=2):
            cellule(ws, r, col, v)
        for col in range(6, der + 1):
            ecrire(ws, (r, col), fond=zebre(r), h="right")
        ws.row_dimensions[r].height = 24
        r += 1
    r += 2
    note_bloc(ws, r, f"{ind['depass']} journées-intervenants dépassent {R.cap_jour} visites; pic individuel {ind['pic_medecin']}. "
                     f"{jours_plus} jour(s) avec plus de {R.cabinets_csmkl2} cabinets comptés.", 1, der, alerte=True, hauteur=30)
    return ws


# ----------------------------------------------------------------------------------------------
# CHME
# ----------------------------------------------------------------------------------------------
def actes_lies(R, specialite, activite=None):
    cles = {v["cle"] for v in R.visites if v["site"] == "CHME" and v["specialite"] == specialite
            and (activite is None or v["activite"] == activite)}
    g = sum(1 for a in R.actes if a["cle"] in cles and a["logiciel"] == "GPS")
    e = sum(1 for a in R.actes if a["cle"] in cles and a["logiciel"] != "GPS")
    return g, e


def feuille_chme(wb, R, onglet):
    ws = wb.create_sheet("CHME")
    mise_en_page(ws, onglet, zoom=85, figer="A4")
    der = 12
    ws.column_dimensions["A"].width = 32
    for c in "BCDEFGHIJKL":
        ws.column_dimensions[c].width = 12
    t = R.unites_total
    L = R.lits_occ
    cab = [R.cab_chme_spec[j] for j in R.jours]
    ouvres = [n for n in cab if n]
    jours_plus = sum(1 for n in cab if n > R.cabinets_chme)
    haut_de_page(ws, "CHME / CAPACITÉ ET ACTIVITÉS",
                 f"{titre_periode(R)} • GPS + Evolucare • {R.lits_reels} lits réels • {R.cabinets_chme} cabinets de spécialistes", der)
    kpi(ws, 1, 3, f"OCCUPATION / {R.lits_reels} LITS RÉELS", L["taux"], f"{fr_dec(L['moy'])} lits occupés en moyenne (GPS + Evo)",
        fmt=PCT, lien_cible="'Dashboard'!A1", lien_texte="Dashboard")
    colorer(ws, 5, 1, L["taux"], R)
    kpi(ws, 4, 6, "LITS OCCUPÉS / DERNIER JOUR", f"{fr_dec(L['dernier'])} / {R.lits_reels}",
        f"{fr_pct(L['taux_dernier'] or 0)} le {R.fin.strftime('%d/%m')} ; pic {fr_dec(L['pic'])} le {L['date_pic'].strftime('%d/%m')}",
        taille=23, lien_cible="'CHME médecins'!A1", lien_texte="Médecins par jour")
    kpi(ws, 7, 9, "DOSSIERS HOSPI / GPS - EVO", f"{t['dos_gps']} / {t['dos_evo']}", f"dont {L['sans_entree']} sans date d’entrée (sans durée)",
        lien_cible="'CHME jours'!A1", lien_texte="Jours et séjours")
    kpi(ws, 10, 12, f"CABINETS SPÉCIALISTES / {R.cabinets_chme}", max(cab, default=0),
        f"maximum / jour ; moyenne {fr_dec(sum(ouvres) / len(ouvres) if ouvres else 0)} ; {jours_plus} jour(s) > {R.cabinets_chme}",
        lien_cible="'CHME actes'!A1", lien_texte="Actes détaillés")
    if max(cab, default=0) > R.cabinets_chme:
        colorer(ws, 5, 10, 1, R, force=True)
    section(ws, 9, f"HOSPITALISATION / LITS OCCUPÉS PAR JOUR (GPS + EVOLUCARE) ET {R.lits_reels} LITS RÉELS", 1, der)
    for r in range(10, 27):
        ws.row_dimensions[r].height = 20
    graphique(ws, "A10", wb["_Hospi jour"], 6, R.ndays, [(4, "Lits occupés GPS + Evo"), (2, "dont GPS"), (3, "dont Evo"),
                                                          (5, "Lits réels")], 11, "Moyenne sur 24 heures", axe="Lits occupés")
    note_bloc(ws, 27, f"Les séjours entrés avant le {R.debut.strftime('%d/%m')} n’ont pas de date d’entrée dans les exports "
                      f"({L['sans_entree']} dossiers) : les premiers jours sont sous-estimés. Les dates de sortie Evolucare sont "
                      "provisoires (proposées à l’admission).", 1, der, alerte=True, hauteur=34)
    section(ws, 29, "HOSPITALISATION / RÉPARTITION PAR UNITÉ", 1, der)
    cols = ["Unité", "Lits réels", "Dossiers\nGPS", "Dossiers\nEvolucare", "Sans entrée\nGPS", "Sans entrée\nEvolucare",
            "Lits occupés\nmoy. / jour", "Occupation\nmoyenne", "Lits occupés\ndernier jour", "Occupation\ndernier jour", "Sorties\nGPS",
            "Sorties\nEvolucare"]
    entete_tableau(ws, 30, [(i, i, x) for i, x in enumerate(cols, start=1)])
    r = 31
    for u in R.unites:
        cellule(ws, r, 1, u["unite"], h="left", fmt=None)
        cellule(ws, r, 2, u["lits"] if u["lits"] else "–")
        for c, v in enumerate([u["dos_gps"], u["dos_evo"], u["sans_gps"], u["sans_evo"]], start=3):
            cellule(ws, r, c, v)
        cellule(ws, r, 7, u["moy"], fmt="0.0")
        cellule(ws, r, 8, u["taux"] if u["lits"] else "–", fmt=PCT)
        cellule(ws, r, 9, u["dernier"], fmt="0.0")
        cellule(ws, r, 10, u["taux_dernier"] if u["lits"] else "–", fmt=PCT)
        colorer(ws, r, 8, u["taux"], R)
        colorer(ws, r, 10, u["taux_dernier"], R)
        cellule(ws, r, 11, u["sorties_gps"])
        cellule(ws, r, 12, u["sorties_evo"])
        ws.row_dimensions[r].height = 28
        r += 1
    r += 1
    ecrire(ws, (r, 1), "TOTAL CHME", gras=True, couleur=BLANC, fond=TEAL)
    for c, (v, f) in enumerate([(t["lits"], NB), (t["dos_gps"], NB), (t["dos_evo"], NB), (t["sans_gps"], NB), (t["sans_evo"], NB),
                                (L["moy"], "0.0"), (L["taux"], PCT), (L["dernier"], "0.0"), (L["taux_dernier"], PCT),
                                (t["sorties_gps"], NB), (t["sorties_evo"], NB)], start=2):
        ecrire(ws, (r, c), v, gras=True, couleur=BLANC, fond=TEAL, fmt=f, h="right")
    ws.row_dimensions[r].height = 28
    r += 1
    note_bloc(ws, r, "Les séjours sans unité renseignée comptent dans le total du CHME, sans taux d’unité. Journées GPS et Evolucare "
                     "détaillées dans « Dossiers hospitaliers ».", 1, der, hauteur=30)
    r += 1
    fusion(ws, r, 1, der)
    lien(ws, (r, 1), "Ouvrir la liste des dossiers hospitaliers, dates et durées", "'CHME jours'!A1")
    ws.row_dimensions[r].height = 24
    # Ambulatoire et urgences
    r += 2
    section(ws, r, "AMBULATOIRE ET URGENCES / VISITES ET CAPACITÉ", 1, der)
    r += 1
    cols = ["Activité", "GPS", "Evolucare", "Total visites", "Consultation", "Avant 1 sem.", "Après 1 sem.", "Cabinets-jours",
            "Capacité", "Utilisation", "Sans médecin", "Pic / jour"]
    entete_tableau(ws, r, [(i, i, x) for i, x in enumerate(cols, start=1)])
    r += 1
    for act in (V.AMBULATOIRE, V.URGENCES):
        ind = indicateurs(R, "CHME", act)
        cellule(ws, r, 1, act, h="left", fmt=None)
        for c, v in enumerate([ind["gps"], ind["evo"], ind["total"], ind["cons"], ind["avant"], ind["apres"], ind["cab"], ind["cap"]],
                              start=2):
            cellule(ws, r, c, v)
        cellule(ws, r, 10, ind["util"] if ind["util"] is not None else "N/D", fmt=PCT)
        cellule(ws, r, 11, ind["sans"])
        cellule(ws, r, 12, ind["pic_jour"])
        ws.row_dimensions[r].height = 30
        r += 1
    urg = indicateurs(R, "CHME", V.URGENCES)
    note_bloc(ws, r, f"Urgences : {urg['sans']} visites sans médecin renseigné restent dans le volume, sans ajouter de cabinet. "
                     "Le quotient affiché n’est pas une mesure fiable de la capacité réelle des urgences.", 1, der, alerte=True, hauteur=36)
    r += 2
    section(ws, r, "AMBULATOIRE / ÉVOLUTION DE LA CHARGE", 1, der)
    graphique(ws, f"A{r + 1}", wb["_Jours"], R.lignes_jours[("CHME", V.AMBULATOIRE)], R.ndays, [(5, "Visites"), (12, "Capacité")], 20,
              titre_periode(R).replace(f" {R.fin.year}", ""))
    for x in range(r + 1, r + 18):
        ws.row_dimensions[x].height = 20
    r += 19
    section(ws, r, "CONSULTATIONS PAR SPÉCIALITÉ / ACTIVITÉ ET ACTES DES DOSSIERS VUS", 1, der)
    r += 1
    cols = ["Spécialité / activité", "Consultation", "Avant 1 sem.", "Après 1 sem.", "Visites", "GPS", "Evolucare",
            f"Journées intervenants ≥{R.seuil}", "Repère visites", "Utilisation repère", "Actes liés GPS", "Actes liés Evo"]
    entete_tableau(ws, r, [(i, i, x) for i, x in enumerate(cols, start=1)])
    r += 1
    specs = defaultdict(list)
    for v in R.visites:
        if v["site"] == "CHME" and v["activite"] == V.AMBULATOIRE:
            specs[v["specialite"]].append(v)
    ordre = sorted(specs, key=lambda s: (-len(specs[s]), s))
    urg_vs = [v for v in R.visites if v["site"] == "CHME" and v["activite"] == V.URGENCES]
    for sp, vs, act in [(s, specs[s], V.AMBULATOIRE) for s in ordre] + [("Urgences générales", urg_vs, V.URGENCES)]:
        if not vs:
            continue
        cnt = defaultdict(int)
        for v in vs:
            if v["medecin"]:
                cnt[(v["jour"], v["medecin"])] += 1
        jint = sum(1 for n in cnt.values() if n >= R.seuil)
        rep = jint * R.cap_jour
        g, e = actes_lies(R, sp, act)
        vals = [sum(1 for v in vs if v["classe"] == V.CONSULTATION), sum(1 for v in vs if v["classe"] == V.AVANT),
                sum(1 for v in vs if v["classe"] == V.APRES), len(vs), sum(1 for v in vs if v["logiciel"] == "GPS"),
                sum(1 for v in vs if v["logiciel"] != "GPS"), jint, rep]
        cellule(ws, r, 1, sp, h="left", fmt=None)
        for c, v in enumerate(vals, start=2):
            cellule(ws, r, c, v)
        cellule(ws, r, 10, (len(vs) / rep) if rep else "N/D", fmt=PCT)
        cellule(ws, r, 11, g)
        cellule(ws, r, 12, e)
        ws.row_dimensions[r].height = 32
        r += 1
    r += 1
    note_bloc(ws, r, "Actes liés = tous les actes des dossiers vus dans cette spécialité, pas uniquement les actes produits par cette "
                     "spécialité. Un dossier multispecialités peut apparaître sur plusieurs lignes : ne pas les additionner.", 1, der,
              hauteur=40)
    r += 2
    note_bloc(ws, r, "Les repères par spécialité ne s’additionnent pas en capacité physique : un intervenant peut couvrir plusieurs "
                     "spécialités le même jour. Les soins, examens et pharmacie restent inclus selon le périmètre du modèle.", 1, der,
              hauteur=38)
    return ws


# ----------------------------------------------------------------------------------------------
# DASHBOARD : tout sur un écran (zoom 85 %), sans défilement
# ----------------------------------------------------------------------------------------------
H_LIGNE = 15
TAILLE = 10          # police des tableaux du Dashboard


def expliquer(ws, r, c, texte):
    """Explication d'un terme : note Excel affichée au survol de la cellule (petit triangle rouge)."""
    note = Comment(texte, "Monkole")
    note.width, note.height = 320, 130
    ws.cell(row=r, column=c).comment = note


def _kpi_compact(ws, c1, c2, titre, valeur, sous, fmt=NB, couleur=None, taille=22, aide=None):
    fusion(ws, 2, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (2, c), titre if c == c1 else None, taille=10, gras=True, couleur=BLANC, fond=TEAL)
    if aide:
        expliquer(ws, 2, c1, aide)
    fond, coul = couleur if couleur else (BLANC, TEAL)
    fusion(ws, 3, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (3, c), valeur if c == c1 else None, taille=taille, gras=True, couleur=coul, fond=fond,
               fmt=fmt if not isinstance(valeur, str) else None, h="center", wrap=None)
    fusion(ws, 4, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (4, c), sous if c == c1 else None, taille=9, couleur=TEXTE, fond=CLAIR, h="center")


def _titre_bloc(ws, r, texte, c1, c2, aide=None):
    section(ws, r, texte, c1, c2, taille=10, hauteur=18)
    if aide:
        expliquer(ws, r, c1, aide)


def _entete(ws, r, colonnes, aides=None):
    """colonnes : (col1, col2, texte) ; aides : {texte: explication}."""
    entete_tableau(ws, r, colonnes, hauteur=30, taille=9)
    for c1, _, t in colonnes:
        if aides and t in aides:
            expliquer(ws, r, c1, aides[t])


def _val(ws, r, c, v, fmt=NB, c2=None, h="right", gras=False, fond=None):
    return cellule(ws, r, c, v, fmt=fmt, c2=c2, h=h, gras=gras, taille=TAILLE, fond=fond)


def _total(ws, r, c, v, fmt=NB, c2=None, h="right"):
    if c2:
        fusion(ws, r, c, c2)
        for x in range(c + 1, c2 + 1):
            ecrire(ws, (r, x), fond=TEAL)
    return ecrire(ws, (r, c), v, taille=TAILLE, gras=True, couleur=BLANC, fond=TEAL, fmt=fmt if not isinstance(v, str) else None,
                  h=h)


def lexique_dashboard(ws, r, termes, der):
    """Lexique placé sous l'écran du Dashboard (les mêmes explications s'affichent au survol des titres)."""
    section(ws, r, "LEXIQUE / EXPLICATION DES TERMES (aussi au survol des titres marqués d’un petit triangle rouge)", 1, der,
            taille=10, hauteur=20)
    for i, (terme, texte) in enumerate(termes):
        rr = r + 1 + i
        fond = zebre(rr)
        fusion(ws, rr, 1, 3)
        for c in range(1, 4):
            ecrire(ws, (rr, c), terme if c == 1 else None, taille=TAILLE, gras=True, couleur=TEAL, fond=fond)
        fusion(ws, rr, 4, der)
        for c in range(4, der + 1):
            ecrire(ws, (rr, c), texte if c == 4 else None, taille=TAILLE, couleur=TEXTE, fond=fond)
        ws.row_dimensions[rr].height = 30 if len(texte) > 150 else 17
    return r + 1 + len(termes)


def termes_capacites(R):
    hors = ", ".join(sorted(R.hors_cabinets)).lower()
    return {
        "Visites": "Lignes de visite enregistrées dans GPS et Evolucare sur la période (une visite n’est pas un patient unique).",
        "GPS": "Visites enregistrées dans l’ancien logiciel GPS.",
        "Evolucare": "Visites enregistrées dans le nouveau logiciel Evolucare (migration en cours ; un patient est dans l’un ou "
                     "l’autre le jour de sa consultation).",
        "Capacité": f"{R.cap_jour} visites × cabinets comptés. Un cabinet est compté quand un médecin a au moins {R.seuil} visites "
                    "dans la journée.",
        "Utilisation": f"Visites ÷ capacité. Orange sous {fr_pct(R.seuil_bas, 0)}, vert entre les deux, rouge à partir de "
                       f"{fr_pct(R.seuil_haut, 0)}.",
        f"Journées\nmédecin >{R.cap_jour}": f"Nombre de fois où un médecin a fait plus de {R.cap_jour} visites dans une journée.",
        "Pic par\nmédecin": "Le plus grand nombre de visites faites par un seul médecin en un jour.",
        "Cabinets\nmoy. / jour": "Moyenne des cabinets comptés par jour, sur les jours où le site a eu de l’activité.",
        "Cabinets\nmax / jour": "Le plus grand nombre de cabinets comptés un même jour. En rouge s’il dépasse les cabinets physiques.",
        "Cabinets\nphysiques": f"Salles de consultation disponibles : {R.cabinets_csmkl2} à CSMKL2, {R.cabinets_chme} de spécialistes au "
                               f"CHME (sans {hors}).",
        "Jours >\ncabinets": "Jours où les cabinets comptés dépassent les cabinets physiques. Des spécialités se relaient "
                                       "parfois dans une même salle : c’est un maximum.",
        "MAISON ROSE": "Activités secondaires de CSMKL2 (prénatal, imagerie, laboratoire, sage-femme) : visites comptées, hors capacité.",
        "Urgences": "Visites des urgences du CHME. Celles sans médecin renseigné ne donnent pas de cabinet : la capacité des "
                    "urgences n’est pas mesurable tant que ce champ est vide.",
        "Lits réels": f"Lits disponibles par unité (total {R.lits_reels}). Les lits paramétrés dans les logiciels, fictifs inclus, "
                      "ne sont pas utilisés.",
        "Lits occupés\nmoy. / jour": "Journées d’hospitalisation GPS + Evolucare de la période ÷ nombre de jours. Les dossiers sans "
                                     "date d’entrée ne sont pas comptés.",
        "Occupation\nmoyenne": "Lits occupés en moyenne ÷ lits réels de l’unité. Au-delà de 100 % : patients enregistrés dans l’unité "
                               "mais couchés ailleurs, ou séjours sans date de sortie restés ouverts.",
        "Occupation\ndernier jour": "Lits occupés le dernier jour de la période ÷ lits réels. Les dates de sortie Evolucare sont "
                                    "provisoires.",
        "Actes": "Actes GPS (datés par Date_V) et prestations Evolucare (datées par DATEHEURE). Les produits (médicaments, "
                 "consommables) ne sont pas comptés.",
        "Semaine": "Du lundi au dimanche, coupée au début et à la fin de la période (colonne Jours).",
        "Cabinets max": "Le plus grand nombre de cabinets comptés un même jour de la semaine.",
        "Sans date d’entrée": "Séjours présents dans les exports sans date d’entrée (souvent entrés avant la période) : "
                              "comptés en dossiers, pas en lits occupés.",
    }


def feuille_dashboard(wb, R, pos_chme, arbre_chme, arbre_cs, onglet):
    ws = wb.create_sheet("Dashboard", 0)
    mise_en_page(ws, onglet, zoom=78)
    ws.page_setup.fitToHeight = 0
    der = 20
    for i in range(1, der + 1):
        ws.column_dimensions[chr(64 + i)].width = 9.8
    ws.column_dimensions["A"].width = 11
    ws.column_dimensions["N"].width = 2
    cs = indicateurs(R, "CSMKL2", V.PRINCIPALE)
    mr = indicateurs(R, "CSMKL2", V.SECONDAIRE)
    amb = indicateurs(R, "CHME", V.AMBULATOIRE)
    urg = indicateurs(R, "CHME", V.URGENCES)
    L = R.lits_occ
    seuils = (R.seuil_bas, R.seuil_haut)
    T = termes_capacites(R)

    # Titre et navigation
    fusion(ws, 1, 1, 14)
    ecrire(ws, (1, 1), f"MONKOLE / ACTIVITÉS ET CAPACITÉS — {titre_periode(R)}", taille=16, gras=True, couleur=BLANC, fond=NAVY)
    for c1, texte, cible in ((15, "CSMKL2", "'CSMKL2'!A1"), (17, "CHME", "'CHME'!A1"), (19, "Notez bien", "'Notez bien'!A1")):
        fusion(ws, 1, c1, c1 + 1)
        ecrire(ws, (1, c1 + 1), fond=CLAIR)
        lien(ws, (1, c1), texte, cible, fond=CLAIR, taille=10).alignment = Alignment(horizontal="center", vertical="center")
    ws.row_dimensions[1].height = 27

    # Indicateurs clés
    act = lambda site, lg: sum(1 for a in R.actes if a["site"] == site and (a["logiciel"] == "GPS") == (lg == "GPS"))
    _kpi_compact(ws, 1, 4, "CSMKL2 · UTILISATION", cs["util"] if cs["util"] is not None else "N/D",
                 f"{fr(cs['total'])} visites / {fr(cs['cap'])} de capacité", fmt=PCT, couleur=alerte(cs["util"], *seuils),
                 aide="Activité principale de CSMKL2 (hors MAISON ROSE). " + T["Utilisation"] + " " + T["Capacité"])
    _kpi_compact(ws, 5, 8, "CHME AMBULATOIRE · UTILISATION", amb["util"] if amb["util"] is not None else "N/D",
                 f"{fr(amb['total'])} visites / {fr(amb['cap'])} de capacité", fmt=PCT, couleur=alerte(amb["util"], *seuils),
                 aide="Consultations ambulatoires du CHME. " + T["Utilisation"] + " " + T["Capacité"])
    _kpi_compact(ws, 9, 12, "URGENCES CHME · VISITES", urg["total"], f"dont {fr(urg['sans'])} sans médecin renseigné",
                 aide=T["Urgences"])
    _kpi_compact(ws, 13, 16, f"LITS CHME · OCCUPATION / {R.lits_reels}", L["taux"],
                 f"{fr_dec(L['moy'])} lits / jour ; dernier jour {fr_dec(L['dernier'])} ({fr_pct(L['taux_dernier'] or 0, 0)})",
                 fmt=PCT, couleur=alerte(L["taux"], *seuils),
                 aide=f"Lits occupés en moyenne (GPS + Evolucare) ÷ {R.lits_reels} lits réels. " + T["Occupation\ndernier jour"])
    _kpi_compact(ws, 17, 20, "ACTES · GPS / EVOLUCARE", f"{fr(act('CSMKL2', 'GPS') + act('CHME', 'GPS'))} / "
                 f"{fr(act('CSMKL2', 'Evo') + act('CHME', 'Evo'))}",
                 f"CSMKL2 {fr(act('CSMKL2', 'GPS'))} / {fr(act('CSMKL2', 'Evo'))} • CHME {fr(act('CHME', 'GPS'))} / {fr(act('CHME', 'Evo'))}",
                 taille=17, aide=T["Actes"])
    for r, h in ((2, 17), (3, 30), (4, 15)):
        ws.row_dimensions[r].height = h

    # Consultations (gauche, colonnes 1-13)
    _titre_bloc(ws, 5, f"CONSULTATIONS / VISITES ET CAPACITÉ ({R.cap_jour} VISITES PAR MÉDECIN EN CABINET ET PAR JOUR)", 1, 13,
                aide=T["Capacité"])
    _entete(ws, 6, [(1, 2, "Site / activité"), (3, 3, "Visites"), (4, 4, "GPS"), (5, 5, "Evolucare"), (6, 6, "Capacité"),
                    (7, 7, "Utilisation"), (8, 8, f"Journées\nmédecin >{R.cap_jour}"), (9, 9, "Pic par\nmédecin"),
                    (10, 10, "Cabinets\nmoy. / jour"), (11, 11, "Cabinets\nmax / jour"), (12, 12, "Cabinets\nphysiques"),
                    (13, 13, "Jours >\ncabinets")], T)
    cab_cs = [l["cab"] for l in R.jours_act[("CSMKL2", V.PRINCIPALE)]]
    cab_ch = [R.cab_chme_spec[j] for j in R.jours]
    cab_urg = [l["cab"] for l in R.jours_act[("CHME", V.URGENCES)]]
    moy = lambda xs: (sum(x for x in xs if x) / sum(1 for x in xs if x)) if any(xs) else 0
    lignes = [("CSMKL2 / principale", cs, cab_cs, R.cabinets_csmkl2), ("CSMKL2 / MAISON ROSE", mr, None, None),
              ("CHME / ambulatoire", amb, cab_ch, R.cabinets_chme), ("CHME / urgences", urg, cab_urg, None)]
    for i, (nom, d, cab, phys) in enumerate(lignes):
        r = 7 + i
        _val(ws, r, 1, nom, fmt=None, c2=2, h="left")
        for c, v in ((3, d["total"]), (4, d["gps"]), (5, d["evo"])):
            _val(ws, r, c, v)
        if cab is None:
            for c in range(6, 14):
                _val(ws, r, c, "Hors repère" if c == 6 else "–", fmt=None, h="center")
            expliquer(ws, r, 1, T["MAISON ROSE"])
        else:
            _val(ws, r, 6, d["cap"])
            _val(ws, r, 7, d["util"] if d["util"] is not None else "N/D", fmt=PCT)
            colorer(ws, r, 7, d["util"], R)
            _val(ws, r, 8, d["depass"])
            _val(ws, r, 9, d["pic_medecin"])
            _val(ws, r, 10, moy(cab), fmt="0.0")
            _val(ws, r, 11, max(cab, default=0))
            _val(ws, r, 12, phys if phys else "–", h="right" if phys else "center")
            plus = sum(1 for x in cab if x > phys) if phys else "–"
            _val(ws, r, 13, plus, h="right" if phys else "center")
            if phys and max(cab, default=0) > phys:
                colorer(ws, r, 11, 1, R, force=True)
                colorer(ws, r, 13, 1, R, force=True)
            if d["depass"]:
                colorer(ws, r, 8, 1, R, force=True)
        if nom.endswith("urgences"):
            expliquer(ws, r, 1, T["Urgences"])
        ws.row_dimensions[r].height = H_LIGNE + 2

    # Graphiques quotidiens (gauche) : chacun dans ses cellules, sans recouvrir les tableaux
    _titre_bloc(ws, 12, "CSMKL2 / VISITES ET CAPACITÉ PAR JOUR", 1, 6,
                aide="Courbe bleue : visites de l’activité principale par jour. Courbe rouge : capacité du jour "
                     f"({R.cap_jour} × cabinets comptés).")
    _titre_bloc(ws, 12, "CHME AMBULATOIRE / VISITES ET CAPACITÉ PAR JOUR", 7, 13,
                aide="Courbe bleue : visites ambulatoires du CHME par jour. Courbe rouge : capacité du jour "
                     f"({R.cap_jour} × cabinets comptés).")
    r_g1, r_g2 = 13, 23
    for r in range(r_g1, r_g2 + 1):
        ws.row_dimensions[r].height = 14
    graphique(ws, "A13", wb["_Jours"], R.lignes_jours[("CSMKL2", V.PRINCIPALE)], R.ndays, [(5, "Visites"), (12, "Capacité")], 21,
              None, axe=None, zone=(1, r_g1, 6, r_g2))
    graphique(ws, "G13", wb["_Jours"], R.lignes_jours[("CHME", V.AMBULATOIRE)], R.ndays, [(5, "Visites"), (12, "Capacité")], 21,
              None, axe=None, zone=(7, r_g1, 13, r_g2))

    # Hospitalisation par unité (droite, colonnes 15-20)
    _titre_bloc(ws, 5, f"HOSPITALISATION CHME / {R.lits_reels} LITS RÉELS", 15, 20, aide=T["Lits réels"])
    _entete(ws, 6, [(15, 16, "Unité"), (17, 17, "Lits réels"), (18, 18, "Lits occupés\nmoy. / jour"),
                    (19, 19, "Occupation\nmoyenne"), (20, 20, "Occupation\ndernier jour")], T)
    r = 7
    for u in R.unites:
        _val(ws, r, 15, u["unite"].replace("Hors unités / UF absente", "Sans unité"), fmt=None, c2=16, h="left")
        _val(ws, r, 17, u["lits"] if u["lits"] else "–", h="right")
        _val(ws, r, 18, u["moy"], fmt="0.0")
        _val(ws, r, 19, u["taux"] if u["lits"] else "–", fmt=PCT, h="right")
        _val(ws, r, 20, u["taux_dernier"] if u["lits"] else "–", fmt=PCT, h="right")
        colorer(ws, r, 19, u["taux"], R)
        colorer(ws, r, 20, u["taux_dernier"], R)
        if not u["lits"]:
            expliquer(ws, r, 15, "Séjours sans unité renseignée : comptés dans le total du CHME, sans taux d’unité.")
        r += 1
    _total(ws, r, 15, "Total CHME", c2=16, h="left")
    _total(ws, r, 17, R.lits_reels)
    _total(ws, r, 18, L["moy"], fmt="0.0")
    _total(ws, r, 19, L["taux"], fmt=PCT)
    _total(ws, r, 20, L["taux_dernier"], fmt=PCT)
    r += 1
    _val(ws, r, 15, f"Sans date d’entrée : {L['sans_entree']} dossiers (non comptés en lits)", fmt=None, c2=20, h="left")
    ws.cell(row=r, column=15).font = Font(name="Calibri", size=9, italic=True, color=TEXTE)
    expliquer(ws, r, 15, T["Sans date d’entrée"])
    r_lits = r + 2

    # Lecture par semaine (gauche)
    r = r_g2 + 2
    ws.row_dimensions[r - 1].height = 6
    _titre_bloc(ws, r, "PAR SEMAINE (LUNDI-DIMANCHE)", 1, 13, aide=T["Semaine"])
    aides_sem = {"Jours": "Nombre de jours de la semaine compris dans la période.",
                 "CSMKL2\nvisites": "Visites de l’activité principale de CSMKL2 dans la semaine.",
                 "CSMKL2\nutilisation": "Visites ÷ capacité de la semaine (CSMKL2).", "CSMKL2\ncab. max": T["Cabinets max"],
                 "CHME amb.\nvisites": "Visites ambulatoires du CHME dans la semaine.",
                 "CHME amb.\nutilisation": "Visites ÷ capacité de la semaine (CHME ambulatoire).",
                 "CHME\ncab. max": T["Cabinets max"] + f" Cabinets de spécialistes, à comparer aux {R.cabinets_chme}.",
                 "Urgences\nvisites": "Visites des urgences du CHME dans la semaine.",
                 "Lits occupés\nmoy. / jour": T["Lits occupés\nmoy. / jour"],
                 "Occupation\ndes lits": f"Lits occupés en moyenne dans la semaine ÷ {R.lits_reels} lits réels."}
    _entete(ws, r + 1, [(1, 2, "Semaine"), (3, 3, "Jours"), (4, 4, "CSMKL2\nvisites"), (5, 5, "CSMKL2\nutilisation"),
                        (6, 6, "CSMKL2\ncab. max"), (7, 7, "CHME amb.\nvisites"), (8, 8, "CHME amb.\nutilisation"),
                        (9, 9, "CHME\ncab. max"), (10, 10, "Urgences\nvisites"), (11, 11, "Lits occupés\nmoy. / jour"),
                        (12, 13, "Occupation\ndes lits")], aides_sem)
    r += 2
    for s in R.semaines:
        _val(ws, r, 1, f"S{s['num']} · {s['debut'].strftime('%d/%m')}-{s['fin'].strftime('%d/%m')}", fmt=None, c2=2, h="left")
        vals = [(3, s["n"], NB), (4, s["cs"], NB), (5, s["cs_util"], PCT), (6, s["cab_cs_max"], NB), (7, s["amb"], NB),
                (8, s["amb_util"], PCT), (9, s["cab_chme_max"], NB), (10, s["urg"], NB), (11, s["lits"], "0.0")]
        for c, v, f in vals:
            _val(ws, r, c, v if v is not None else "N/D", fmt=f)
        _val(ws, r, 12, s["occ"], fmt=PCT, c2=13)
        colorer(ws, r, 5, s["cs_util"], R)
        colorer(ws, r, 8, s["amb_util"], R)
        colorer(ws, r, 12, s["occ"], R)
        if s["cab_cs_max"] > R.cabinets_csmkl2:
            colorer(ws, r, 6, 1, R, force=True)
        if s["cab_chme_max"] > R.cabinets_chme:
            colorer(ws, r, 9, 1, R, force=True)
        ws.row_dimensions[r].height = H_LIGNE + 1
        r += 1
    _total(ws, r, 1, "Période", c2=2, h="left")
    for c, v, f in ((3, R.ndays, NB), (4, cs["total"], NB), (5, cs["util"], PCT), (6, max(cab_cs, default=0), NB),
                    (7, amb["total"], NB), (8, amb["util"], PCT), (9, max(cab_ch, default=0), NB), (10, urg["total"], NB),
                    (11, L["moy"], "0.0")):
        _total(ws, r, c, v if v is not None else "N/D", fmt=f)
    _total(ws, r, 12, L["taux"], fmt=PCT, c2=13)
    ws.row_dimensions[r].height = H_LIGNE + 1
    r_fin = r

    # Lits occupés par jour (droite), jusqu'au bas du tableau hebdomadaire
    _titre_bloc(ws, r_lits, f"CHME / LITS OCCUPÉS PAR JOUR ET {R.lits_reels} LITS RÉELS", 15, 20,
                aide="Courbe bleue : lits occupés chaque jour (GPS + Evolucare). Ligne rouge : lits réels du CHME.")
    graphique(ws, f"O{r_lits + 1}", wb["_Hospi jour"], 6, R.ndays, [(4, "Lits occupés"), (5, "Lits réels")], 12, None,
              axe=None, zone=(15, r_lits + 1, 20, max(r_fin, r_lits + 10)))
    r = max(r_fin, r_lits + 10) + 1
    fusion(ws, r, 1, der)
    ecrire(ws, (r, 1), f"Couleurs : orange < {fr_pct(R.seuil_bas, 0)} ≤ vert < {fr_pct(R.seuil_haut, 0)} ≤ rouge ; rouge aussi quand "
                       "les cabinets comptés dépassent les cabinets physiques. Survoler un titre (triangle rouge) pour son explication ; "
                       "lexique complet ci-dessous.", taille=9, couleur=TEXTE, fond=CLAIR)
    ws.row_dimensions[r].height = 16
    for x in range(5, r):
        if ws.row_dimensions[x].height is None:
            ws.row_dimensions[x].height = H_LIGNE
    # Lexique sous l'écran
    termes = [(k.replace("\n", " "), v) for k, v in T.items()]
    lexique_dashboard(ws, r + 3, termes, der)
    return ws
