"""Feuilles de synthèse : Dashboard, CSMKL2, CHME."""
from collections import defaultdict

from openpyxl.chart import LineChart, Reference, Series
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


def graphique(ws, ancre, source, premiere_ligne, n, series, col_etiquette, titre, axe="Visites", hauteur=7.5, largeur=15):
    ch = LineChart()
    if titre:
        ch.title = titre
    ch.y_axis.title = axe
    ch.height = hauteur
    ch.width = largeur
    ch.legend.position = "b"
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
    cols = ["Unité", "Lits\nlogiciel", "Dossiers\nGPS", "Dossiers\nEvolucare", "Sans entrée\nGPS", "Sans entrée\nEvolucare",
            "Jours\nGPS", "Jours\nEvolucare", "Lits occupés\nmoy. / jour", "Lits occupés\ndernier jour", "Sorties\nGPS",
            "Sorties\nEvolucare"]
    entete_tableau(ws, 30, [(i, i, x) for i, x in enumerate(cols, start=1)])
    r = 31
    for u in R.unites:
        cellule(ws, r, 1, u["unite"], h="left", fmt=None)
        cellule(ws, r, 2, u["lits"] if u["lits"] else "–")
        for c, v in enumerate([u["dos_gps"], u["dos_evo"], u["sans_gps"], u["sans_evo"]], start=3):
            cellule(ws, r, c, v)
        cellule(ws, r, 7, u["jours_gps"], fmt=DEC)
        cellule(ws, r, 8, u["jours_evo"], fmt=DEC)
        cellule(ws, r, 9, u["moy"], fmt="0.0")
        cellule(ws, r, 10, u["dernier"], fmt="0.0")
        cellule(ws, r, 11, u["sorties_gps"])
        cellule(ws, r, 12, u["sorties_evo"])
        ws.row_dimensions[r].height = 28
        r += 1
    r += 1
    ecrire(ws, (r, 1), f"TOTAL CHME / {R.lits_reels} LITS RÉELS", gras=True, couleur=BLANC, fond=TEAL)
    for c, (v, f) in enumerate([(t["lits"], NB), (t["dos_gps"], NB), (t["dos_evo"], NB), (t["sans_gps"], NB), (t["sans_evo"], NB),
                                (t["jours_gps"], DEC), (t["jours_evo"], DEC), (L["moy"], "0.0"), (L["dernier"], "0.0"),
                                (t["sorties_gps"], NB), (t["sorties_evo"], NB)], start=2):
        ecrire(ws, (r, c), v, gras=True, couleur=BLANC, fond=TEAL, fmt=f, h="right")
    ws.row_dimensions[r].height = 28
    r += 1
    note_bloc(ws, r, f"Lits logiciel = lits paramétrés dans GPS / Evolucare (lits fictifs inclus, total {t['lits']}) : répartition "
                     f"indicative. Le taux d’occupation se calcule sur les {R.lits_reels} lits réels.", 1, der, hauteur=30)
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


def _kpi_compact(ws, c1, c2, titre, valeur, sous, fmt=NB, couleur=None, taille=20):
    fusion(ws, 2, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (2, c), titre if c == c1 else None, taille=9, gras=True, couleur=BLANC, fond=TEAL)
    fond, coul = couleur if couleur else (BLANC, TEAL)
    fusion(ws, 3, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (3, c), valeur if c == c1 else None, taille=taille, gras=True, couleur=coul, fond=fond,
               fmt=fmt if not isinstance(valeur, str) else None, h="center", wrap=None)
    fusion(ws, 4, c1, c2)
    for c in range(c1, c2 + 1):
        ecrire(ws, (4, c), sous if c == c1 else None, taille=8, couleur=GRIS, fond=CLAIR, h="center")


def _titre_bloc(ws, r, texte, c1, c2):
    section(ws, r, texte, c1, c2, taille=9, hauteur=17)


def _entete(ws, r, colonnes):
    entete_tableau(ws, r, colonnes, hauteur=28, taille=8)


def _val(ws, r, c, v, fmt=NB, c2=None, h="right", gras=False, fond=None):
    return cellule(ws, r, c, v, fmt=fmt, c2=c2, h=h, gras=gras, taille=9, fond=fond)


def _total(ws, r, c, v, fmt=NB, c2=None, h="right"):
    if c2:
        fusion(ws, r, c, c2)
        for x in range(c + 1, c2 + 1):
            ecrire(ws, (r, x), fond=TEAL)
    return ecrire(ws, (r, c), v, taille=9, gras=True, couleur=BLANC, fond=TEAL, fmt=fmt if not isinstance(v, str) else None, h=h)


def feuille_dashboard(wb, R, pos_chme, arbre_chme, arbre_cs, onglet):
    ws = wb.create_sheet("Dashboard", 0)
    mise_en_page(ws, onglet, zoom=85)
    ws.page_setup.fitToHeight = 1
    der = 20
    for i in range(1, der + 1):
        ws.column_dimensions[chr(64 + i)].width = 9.5
    ws.column_dimensions["N"].width = 2
    cs = indicateurs(R, "CSMKL2", V.PRINCIPALE)
    mr = indicateurs(R, "CSMKL2", V.SECONDAIRE)
    amb = indicateurs(R, "CHME", V.AMBULATOIRE)
    urg = indicateurs(R, "CHME", V.URGENCES)
    t = R.unites_total
    L = R.lits_occ
    seuils = (R.seuil_bas, R.seuil_haut)

    # Titre et navigation
    fusion(ws, 1, 1, 14)
    ecrire(ws, (1, 1), f"MONKOLE / ACTIVITÉS ET CAPACITÉS — {titre_periode(R)}", taille=15, gras=True, couleur=BLANC, fond=NAVY)
    for c1, texte, cible in ((15, "CSMKL2", "'CSMKL2'!A1"), (17, "CHME", "'CHME'!A1"), (19, "Notez bien", "'Notez bien'!A1")):
        fusion(ws, 1, c1, c1 + 1)
        ecrire(ws, (1, c1 + 1), fond=CLAIR)
        lien(ws, (1, c1), texte, cible, fond=CLAIR, taille=9).alignment = Alignment(horizontal="center", vertical="center")
    ws.row_dimensions[1].height = 26

    # Indicateurs clés
    act = lambda site, lg: sum(1 for a in R.actes if a["site"] == site and (a["logiciel"] == "GPS") == (lg == "GPS"))
    _kpi_compact(ws, 1, 4, "CSMKL2 · UTILISATION", cs["util"] if cs["util"] is not None else "N/D",
                 f"{fr(cs['total'])} visites / {fr(cs['cap'])} de capacité", fmt=PCT,
                 couleur=alerte(cs["util"], *seuils))
    _kpi_compact(ws, 5, 8, "CHME AMBULATOIRE · UTILISATION", amb["util"] if amb["util"] is not None else "N/D",
                 f"{fr(amb['total'])} visites / {fr(amb['cap'])} de capacité", fmt=PCT,
                 couleur=alerte(amb["util"], *seuils))
    _kpi_compact(ws, 9, 12, "URGENCES CHME · VISITES", urg["total"], f"dont {fr(urg['sans'])} sans médecin renseigné",
)
    _kpi_compact(ws, 13, 16, f"LITS CHME · OCCUPATION / {R.lits_reels}", L["taux"],
                 f"{fr_dec(L['moy'])} lits / jour ; dernier jour {fr_dec(L['dernier'])} ({fr_pct(L['taux_dernier'] or 0, 0)})",
                 fmt=PCT, couleur=alerte(L["taux"], *seuils))
    _kpi_compact(ws, 17, 20, "ACTES · GPS / EVOLUCARE", f"{fr(act('CSMKL2', 'GPS') + act('CHME', 'GPS'))} / "
                 f"{fr(act('CSMKL2', 'Evo') + act('CHME', 'Evo'))}",
                 f"CSMKL2 {fr(act('CSMKL2', 'GPS'))} / {fr(act('CSMKL2', 'Evo'))} • CHME {fr(act('CHME', 'GPS'))} / {fr(act('CHME', 'Evo'))}",
                 taille=16)
    for r, h in ((2, 16), (3, 30), (4, 14), (5, 5)):
        ws.row_dimensions[r].height = h

    # Consultations (gauche, colonnes 1-13)
    _titre_bloc(ws, 6, f"CONSULTATIONS / VISITES ET CAPACITÉ ({R.cap_jour} VISITES PAR MÉDECIN EN CABINET ET PAR JOUR)", 1, 13)
    _entete(ws, 7, [(1, 2, "Site / activité"), (3, 3, "Visites"), (4, 4, "GPS"), (5, 5, "Evolucare"), (6, 6, "Capacité"),
                    (7, 7, "Utilisation"), (8, 8, f"Journées\nmédecin >{R.cap_jour}"), (9, 9, "Pic\nmédecin / jour"),
                    (10, 10, "Cabinets\nmoy. / jour"), (11, 11, "Cabinets\nmax / jour"), (12, 12, "Cabinets\nphysiques"),
                    (13, 13, "Jours au-delà\ndes cabinets")])
    cab_cs = [l["cab"] for l in R.jours_act[("CSMKL2", V.PRINCIPALE)]]
    cab_ch = [R.cab_chme_spec[j] for j in R.jours]
    cab_urg = [l["cab"] for l in R.jours_act[("CHME", V.URGENCES)]]
    moy = lambda xs: (sum(x for x in xs if x) / sum(1 for x in xs if x)) if any(xs) else 0
    lignes = [("CSMKL2 / principale", cs, cab_cs, R.cabinets_csmkl2), ("CSMKL2 / MAISON ROSE", mr, None, None),
              ("CHME / ambulatoire", amb, cab_ch, R.cabinets_chme), ("CHME / urgences", urg, cab_urg, None)]
    for i, (nom, d, cab, phys) in enumerate(lignes):
        r = 8 + i
        _val(ws, r, 1, nom, fmt=None, c2=2, h="left")
        for c, v in ((3, d["total"]), (4, d["gps"]), (5, d["evo"])):
            _val(ws, r, c, v)
        if cab is None:
            for c in range(6, 14):
                _val(ws, r, c, "Hors repère" if c == 6 else "–", fmt=None, h="center")
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
        ws.row_dimensions[r].height = H_LIGNE + 2

    # Graphiques quotidiens (gauche)
    _titre_bloc(ws, 13, "CSMKL2 / VISITES ET CAPACITÉ PAR JOUR", 1, 6)
    _titre_bloc(ws, 13, "CHME AMBULATOIRE / VISITES ET CAPACITÉ PAR JOUR", 7, 13)
    for r in range(14, 25):
        ws.row_dimensions[r].height = H_LIGNE
    graphique(ws, "A14", wb["_Jours"], R.lignes_jours[("CSMKL2", V.PRINCIPALE)], R.ndays, [(5, "Visites"), (12, "Capacité")], 21,
              None, hauteur=5.8, largeur=11.3)
    graphique(ws, "G14", wb["_Jours"], R.lignes_jours[("CHME", V.AMBULATOIRE)], R.ndays, [(5, "Visites"), (12, "Capacité")], 21,
              None, hauteur=5.8, largeur=13.2)

    # Hospitalisation par unité (droite, colonnes 15-20)
    _titre_bloc(ws, 6, f"HOSPITALISATION CHME / {R.lits_reels} LITS RÉELS", 15, 20)
    _entete(ws, 7, [(15, 16, "Unité"), (17, 17, "Dossiers\nGPS"), (18, 18, "Dossiers\nEvolucare"),
                    (19, 19, "Lits occupés\nmoy. / jour"), (20, 20, "Lits occupés\ndernier jour")])
    r = 8
    for u in R.unites:
        _val(ws, r, 15, u["unite"].replace("Hors unités / UF absente", "Sans unité"), fmt=None, c2=16, h="left")
        _val(ws, r, 17, u["dos_gps"])
        _val(ws, r, 18, u["dos_evo"])
        _val(ws, r, 19, u["moy"], fmt="0.0")
        _val(ws, r, 20, u["dernier"], fmt="0.0")
        r += 1
    _total(ws, r, 15, "Total CHME", c2=16, h="left")
    _total(ws, r, 17, t["dos_gps"])
    _total(ws, r, 18, t["dos_evo"])
    _total(ws, r, 19, L["moy"], fmt="0.0")
    _total(ws, r, 20, L["dernier"], fmt="0.0")
    r += 1
    _val(ws, r, 15, f"Occupation / {R.lits_reels} lits", fmt=None, c2=18, h="left", gras=True)
    _val(ws, r, 19, L["taux"], fmt=PCT, gras=True)
    colorer(ws, r, 19, L["taux"], R)
    _val(ws, r, 20, L["taux_dernier"], fmt=PCT, gras=True)
    colorer(ws, r, 20, L["taux_dernier"], R)
    r += 1
    _val(ws, r, 15, f"Sans date d’entrée (non comptés dans les lits) : {L['sans_entree']} dossiers", fmt=None, c2=20, h="left")
    ws.cell(row=r, column=15).font = Font(name="Calibri", size=8, italic=True, color=GRIS)
    r_lits = r + 2
    _titre_bloc(ws, r_lits, f"CHME / LITS OCCUPÉS PAR JOUR ET {R.lits_reels} LITS RÉELS", 15, 20)
    graphique(ws, f"O{r_lits + 1}", wb["_Hospi jour"], 6, R.ndays, [(4, "Lits occupés"), (5, "Lits réels")], 12, None,
              axe="Lits", hauteur=5.8, largeur=11.3)

    # Lecture par semaine (gauche)
    r = 26
    _titre_bloc(ws, r, "PAR SEMAINE (LUNDI-DIMANCHE)", 1, 13)
    _entete(ws, r + 1, [(1, 2, "Semaine"), (3, 3, "Jours"), (4, 4, "CSMKL2\nvisites"), (5, 5, "CSMKL2\nutilisation"),
                        (6, 6, "CSMKL2\ncabinets max"), (7, 7, "CHME amb.\nvisites"), (8, 8, "CHME amb.\nutilisation"),
                        (9, 9, "CHME\ncabinets max"), (10, 10, "Urgences\nvisites"), (11, 11, "Lits occupés\nmoy. / jour"),
                        (12, 13, "Occupation\ndes lits")])
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
    r += 1
    fusion(ws, r, 1, 13)
    ecrire(ws, (r, 1), f"Couleurs : orange < {fr_pct(R.seuil_bas, 0)} ≤ vert < {fr_pct(R.seuil_haut, 0)} ≤ rouge "
                       "(seuils modifiables dans Referentiel_Monkole.xlsx) ; rouge aussi quand les cabinets comptés dépassent "
                       "les cabinets physiques.", taille=8, couleur=GRIS, fond=CLAIR)
    for x in range(26, r_lits + 14):
        if ws.row_dimensions[x].height is None:
            ws.row_dimensions[x].height = H_LIGNE
    return ws
