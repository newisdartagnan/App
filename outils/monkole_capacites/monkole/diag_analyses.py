"""Analyses complémentaires des diagnostics (hors Dashboard) : évolution par semaine (surveillance), diagnostics par
spécialité (UF), historique des périodes."""
import datetime as dt
import re
from collections import Counter, defaultdict

from openpyxl.formatting.rule import ColorScaleRule
from openpyxl.styles import Font
from openpyxl.utils import get_column_letter as L

from .texte import cle

from .diag_classeur import (BLANC, CLAIR, COULEURS, NAVY, NB, TEAL, TXT, _cel, _entete, _titre_bloc, _tot, bloc, lien,
                            mise_en_page, periode_txt, tri_groupes)

ONGLET = "3B6E8F"
SURVEILLANCE = ["PAL", "PGRIP", "GRIP", "SALM"]      # paludisme, paludo-grippal, grippe, typhoïde


def _feuille(wb, nom, titre, sous_titre, largeurs):
    ws = wb.create_sheet(nom)
    mise_en_page(ws, ONGLET, zoom=85, figer="A3")
    for i, w in enumerate(largeurs, start=1):
        ws.column_dimensions[L(i)].width = w
    der = len(largeurs)
    bloc(ws, 1, 1, 1, der - 1, titre, taille=15, gras=True, couleur=BLANC, fond=NAVY, wrap=False)
    lien(bloc(ws, 1, der, 1, der, "Dashboard", taille=10, gras=True, couleur=TEAL, fond=CLAIR, h="center"), "'Dashboard'!A1")
    bloc(ws, 2, 1, 2, der, sous_titre, taille=10, couleur=TXT, fond=CLAIR)
    ws.row_dimensions[1].height = 28
    ws.row_dimensions[2].height = 32
    return ws, der


def semaines(R):
    """[(libellé, [jours])] du lundi au dimanche, coupées aux bornes de la période."""
    g = defaultdict(list)
    for j in R.jours:
        g[j.isocalendar()[:2]].append(j)
    return [(f"S{k[1]} · {v[0]:%d/%m}-{v[-1]:%d/%m}", v) for k, v in sorted(g.items())]


def _par_semaine(R, P):
    """Diagnostics comptabilisés (premier jour du diagnostic dans le dossier) et hypothèses seules, par semaine."""
    sem = semaines(R)
    idx = {j: i for i, (_, js) in enumerate(sem) for j in js}
    premier = {}
    for (k, code, j) in P.jours_decrits:
        if (k, code) not in premier or j < premier[(k, code)]:
            premier[(k, code)] = j
    dec = defaultdict(lambda: [0] * len(sem))
    fam = defaultdict(lambda: [0] * len(sem))
    for (k, code), j in premier.items():
        i = idx.get(j)
        if i is not None:
            dec[code][i] += 1
            fam[P.groupes[code]["famille"]][i] += 1
    hyp = defaultdict(lambda: [0] * len(sem))
    for (k, code), m in P.hyp.items():
        i = idx.get(m["s"]["jour"])
        if i is not None:
            hyp[code][i] += 1
    return sem, dec, hyp, fam


def feuille_evolution(wb, R):
    G = R.global_
    sem, dec, hyp, fam = _par_semaine(R, G)
    n = len(sem)
    ws, der = _feuille(wb, "Évolution hebdo", f"DIAGNOSTICS PAR SEMAINE — {periode_txt(R)}",
                       "Diagnostics comptabilisés comptés la semaine où ils apparaissent pour la première fois dans le dossier. "
                       "Semaines du lundi au dimanche (une semaine incomplète compte moins de jours). Hypothèses seules à part.",
                       [52] + [13] * n + [12, 12])
    cols = [(1, 1, "Diagnostic / famille")] + [(2 + i, 2 + i, lib.replace(" · ", "\n")) for i, (lib, _) in enumerate(sem)] + \
           [(2 + n, 2 + n, "Total"), (3 + n, 3 + n, "Par jour\n(dernière sem.)")]
    jours_der = len(sem[-1][1]) if sem else 1

    def ligne(r, lib, valeurs, couleur=TXT, gras=False):
        _cel(ws, r, 1, 1, lib, h="left", couleur=couleur, gras=gras)
        for i, v in enumerate(valeurs):
            _cel(ws, r, 2 + i, 2 + i, v)
        _cel(ws, r, 2 + n, 2 + n, sum(valeurs), gras=True)
        _cel(ws, r, 3 + n, 3 + n, valeurs[-1] / jours_der if valeurs else 0, fmt="0.0")
        ws.row_dimensions[r].height = 17

    r = 4
    _titre_bloc(ws, r, "SURVEILLANCE / PALUDISME, SYNDROME PALUDO-GRIPPAL, GRIPPE, TYPHOÏDE", 1, der)
    _entete(ws, r + 1, cols, hauteur=32)
    r += 2
    r0 = r
    for code in SURVEILLANCE:
        if code not in R.dico.groupes:
            continue
        lib = R.dico.groupes[code][0]
        ligne(r, lib, dec.get(code, [0] * n), gras=True)
        r += 1
        ligne(r, "   dont hypothèses seules (non comptées)", hyp.get(code, [0] * n), couleur="8A6D3B")
        r += 1
    ws.conditional_formatting.add(f"B{r0}:{L(1 + n)}{r - 1}",
                                  ColorScaleRule(start_type="min", start_color="FFFFFF", end_type="max", end_color="E9A03B"))
    r += 1
    _titre_bloc(ws, r, "LES 15 DIAGNOSTICS LES PLUS FRÉQUENTS DE LA PÉRIODE", 1, der)
    _entete(ws, r + 1, cols, hauteur=32)
    r += 2
    r0 = r
    for x in tri_groupes([x for x in G.groupes.values() if x["total"] > 0])[:15]:
        ligne(r, x["groupe"], dec.get(x["code"], [0] * n))
        r += 1
    ws.conditional_formatting.add(f"B{r0}:{L(1 + n)}{r - 1}",
                                  ColorScaleRule(start_type="min", start_color="FFFFFF", end_type="max", end_color="54A6A6"))
    r += 1
    _titre_bloc(ws, r, "FAMILLES CLINIQUES", 1, der)
    _entete(ws, r + 1, cols, hauteur=32)
    r += 2
    for f in sorted(fam, key=lambda f: -sum(fam[f])):
        if f not in COULEURS:
            continue
        ligne(r, f, fam[f], couleur=COULEURS[f], gras=True)
        r += 1
    tot = [sum(fam[f][i] for f in fam if f in COULEURS) for i in range(n)]
    _tot(ws, r, 1, 1, "Total", h="left")
    for i, v in enumerate(tot):
        _tot(ws, r, 2 + i, 2 + i, v)
    _tot(ws, r, 2 + n, 2 + n, sum(tot))
    _tot(ws, r, 3 + n, 3 + n, tot[-1] / jours_der if tot else 0, fmt="0.0")
    return ws


# ----------------------------------------------------------------------------------------------
def specialite(uf):
    """UF / unité médicale -> libellé commun GPS / Evolucare (sans « Consultation »)."""
    if uf is None or str(uf).strip().upper() in ("", "NULL", "NONE"):
        return "UF non renseignée"
    t = re.sub(r"^\s*consultations?\s+", "", str(uf).strip(), flags=re.I)
    t = t.strip()
    return t[:1].upper() + t[1:] if t else "UF non renseignée"


MOTS_VIDES_UF = {"de", "du", "des", "la", "le", "les", "et", "d", "l"}


def _regroupeur_uf(sources):
    """Libellés d'UF écrits différemment dans GPS et Evolucare (ordre des mots, ponctuation, accents) -> un seul libellé."""
    def cle_uf(lib):
        mots = set(re.findall(r"[a-z0-9]+", cle(lib.replace("-", "")))) - MOTS_VIDES_UF
        return " ".join(sorted(mots))
    variantes = defaultdict(Counter)
    for s in sources:
        lib = specialite(s.get("uf"))
        variantes[cle_uf(lib)][lib] += 1
    choix = {k: c.most_common(1)[0][0] for k, c in variantes.items()}
    return lambda uf: choix.get(cle_uf(specialite(uf)), specialite(uf))


def feuille_specialites(wb, R):
    G = R.global_
    specialite = _regroupeur_uf(G.sources)
    ws, der = _feuille(wb, "Par spécialité", f"DIAGNOSTICS PAR SPÉCIALITÉ / UF — {periode_txt(R)}",
                       "UF de la ligne de diagnostic (colonne « Unité Médicale » GPS, « UF » Evolucare, sans le mot Consultation). "
                       "Un dossier vu dans plusieurs UF apparaît dans chacune : ne pas additionner les lignes.",
                       [34, 11, 12, 11, 60, 30])
    uf_dos = defaultdict(set)
    for s in G.sources:
        uf_dos[specialite(s.get("uf"))].add(s["cle"])
    groupes = defaultdict(Counter)
    familles = defaultdict(Counter)
    hyp = Counter()
    for (k, code), m in G.decrits.items():
        u = specialite(m["s"].get("uf"))
        groupes[u][m["groupe"]] += 1
        familles[u][m["famille"]] += 1
    for (k, code), m in G.hyp.items():
        hyp[specialite(m["s"].get("uf"))] += 1
    lignes = sorted(uf_dos, key=lambda u: (-sum(groupes[u].values()), u))
    _titre_bloc(ws, 4, "DIAGNOSTICS COMPTABILISÉS PAR SPÉCIALITÉ / UF", 1, der)
    _entete(ws, 5, [(1, 1, "Spécialité / UF"), (2, 2, "Dossiers"), (3, 3, "Diagnostics\ncomptés"), (4, 4, "Hypothèses\nseules"),
                    (5, 5, "Trois diagnostics les plus fréquents"), (6, 6, "Famille principale")], hauteur=32, a_gauche=(5, 6))
    r = 6
    for u in lignes:
        top = " · ".join(f"{g} ({n})" for g, n in sorted(groupes[u].items(), key=lambda kv: (-kv[1], kv[0].lower()))[:3])
        fp = familles[u].most_common(1)
        _cel(ws, r, 1, 1, u, h="left", gras=True)
        _cel(ws, r, 2, 2, len(uf_dos[u]))
        _cel(ws, r, 3, 3, sum(groupes[u].values()))
        _cel(ws, r, 4, 4, hyp[u])
        c = _cel(ws, r, 5, 5, top or "–", h="left")
        c.font = Font(name="Calibri", size=9, color=TXT)
        _cel(ws, r, 6, 6, fp[0][0] if fp else "–", h="left", couleur=COULEURS.get(fp[0][0], TXT) if fp else TXT)
        ws.row_dimensions[r].height = 17
        r += 1
    return ws


# ----------------------------------------------------------------------------------------------
def ligne_historique(R):
    G = R.global_
    a_classer = sum(1 for s in G.sources if s.get("origine") == "à classer")
    val = lambda code: G.groupes.get(code, {}).get("total", 0)
    return {"Période": f"{R.debut:%d/%m/%Y} - {R.fin:%d/%m/%Y}", "Jours": len(R.jours), "Dossiers": len(G.dossiers),
            "Diagnostics\ncomptés": G.total, "Diagnostics\npar dossier": G.total / len(G.dossiers) if G.dossiers else 0,
            "Hypothèses\nseules": len(G.hyp), "Lignes\nà classer": a_classer, "Lignes sans\ndiagnostic": G.lignes_non_exploitables,
            "Paludisme": val("PAL"), "Grippe": val("GRIP"), "Hypertension": val("HTA"), "Diabète\ntype 2": val("DM2"),
            "Grossesse": val("PREG"), "Calculé le": dt.datetime.now().replace(microsecond=0)}


def feuille_historique(wb, R, historique):
    if not historique:
        return None
    cols = list(ligne_historique(R).keys())
    ws, der = _feuille(wb, "Historique", "HISTORIQUE / DIAGNOSTICS D’UNE PÉRIODE À L’AUTRE",
                       "Une ligne par période traitée sur cet ordinateur (fichier historique/Historique_diagnostics.xlsx). Comparer "
                       "de préférence des périodes de même durée.", [26] + [12] * (len(cols) - 1))
    _titre_bloc(ws, 4, "PÉRIODES TRAITÉES", 1, der)
    _entete(ws, 5, [(i + 1, i + 1, c) for i, c in enumerate(cols)], hauteur=32)
    r = 6
    for h in historique:
        for i, c in enumerate(cols):
            v = h.get(c)
            f = "0.00" if c.startswith("Diagnostics\npar") else ("dd/mm/yyyy" if c == "Calculé le" else NB)
            _cel(ws, r, i + 1, i + 1, v if v is not None else "–", fmt=f, h="left" if i == 0 else "right")
        r += 1
    return ws
