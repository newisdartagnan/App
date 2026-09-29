"""Feuilles d'analyse (hors Dashboard) : capacité horaire, plannings, séjours, prises en charge, origine des patients,
qualité de saisie, historique. Elles complètent le Dashboard sans le charger."""
import re
import statistics as st
from collections import Counter, defaultdict

from . import visites as V
from .calculs import SITES_HORAIRE
from .feuilles_synthese import cellule, colorer, entete_tableau, fusion, note_bloc, section
from .styles import BLANC, CLAIR, NB, PCT, TEAL, TEXTE, ecrire, lien, mise_en_page
from .texte import JOURS, cle, texte

ONGLET = "3B6E8F"
COMMUNES_KINSHASA = ["Bandalungwa", "Barumbu", "Bumbu", "Gombe", "Kalamu", "Kasa-Vubu", "Kimbanseke", "Kinshasa", "Kintambo",
                     "Kisenso", "Lemba", "Limete", "Lingwala", "Makala", "Maluku", "Masina", "Matete", "Mont-Ngafula", "Ndjili",
                     "Ngaba", "Ngaliema", "Ngiri-Ngiri", "Nsele", "Selembao"]
AUTRE_COMMUNE = "Hors Kinshasa / autre"
NON_IDENTIFIEE = "Adresse non identifiée"


def _feuille(wb, nom, titre, sous_titre, largeurs):
    ws = wb.create_sheet(nom)
    mise_en_page(ws, ONGLET, zoom=85, figer="A3")
    for i, w in enumerate(largeurs, start=1):
        ws.column_dimensions[chr(64 + i)].width = w
    der = len(largeurs)
    fusion(ws, 1, 1, der - 1)
    for c in range(1, der):
        ecrire(ws, (1, c), titre if c == 1 else None, taille=15, gras=True, couleur=BLANC, fond="143348")
    lien(ws, (1, der), "Dashboard", "'Dashboard'!A1", fond=CLAIR)
    fusion(ws, 2, 1, der)
    for c in range(1, der + 1):
        ecrire(ws, (2, c), sous_titre if c == 1 else None, taille=10, couleur=TEXTE, fond=CLAIR)
    ws.row_dimensions[1].height = 28
    ws.row_dimensions[2].height = 32
    return ws, der


def _tableau(ws, r, titre, der, colonnes, lignes, formats, hauteur_entete=34, largeur_titre=1):
    """Écrit un tableau : colonnes = libellés ; lignes = listes de valeurs ; formats = format par colonne (None = texte)."""
    section(ws, r, titre, 1, der, taille=10, hauteur=20)
    r += 1
    cols = [(1, largeur_titre, colonnes[0])] + [(largeur_titre + i, largeur_titre + i, t) for i, t in enumerate(colonnes[1:], start=1)]
    entete_tableau(ws, r, cols, hauteur=hauteur_entete, taille=9)
    r += 1
    debut = r
    for lg in lignes:
        cellule(ws, r, 1, lg[0], fmt=None, c2=largeur_titre if largeur_titre > 1 else None, h="left")
        for i, v in enumerate(lg[1:], start=1):
            f = formats[i] if i < len(formats) else NB
            cellule(ws, r, largeur_titre + i, v if v is not None else "–", fmt=f, h="right" if v is not None else "center")
        ws.row_dimensions[r].height = 17
        r += 1
    return r, debut


def _total(ws, r, valeurs, formats, largeur_titre=1):
    fusion(ws, r, 1, largeur_titre)
    for c in range(1, largeur_titre + 1):
        ecrire(ws, (r, c), valeurs[0] if c == 1 else None, gras=True, couleur=BLANC, fond=TEAL)
    for i, v in enumerate(valeurs[1:], start=1):
        f = formats[i] if i < len(formats) else NB
        ecrire(ws, (r, largeur_titre + i), v if v is not None else "–", gras=True, couleur=BLANC, fond=TEAL,
               fmt=f if not isinstance(v, str) else None, h="right")
    ws.row_dimensions[r].height = 18
    return r + 1


def _ratio(a, b):
    return a / b if b else None


def _brutes(R):
    """(logiciel, n° de ligne) -> ligne brute de l'export des visites (colonnes PEC, Utilisateur, adresses...)."""
    return {("GPS" if k == "visites_gps" else "Evolucare", r["_ligne"]): r
            for k in ("visites_gps", "visites_evo") for r in R.donnees[k]}


def _consultations(R):
    return [v for v in R.visites if v["activite"] != V.HOSPITALISATION and v["site"] in ("CSMKL2", "CHME")]


# ----------------------------------------------------------------------------------------------
# Capacité horaire
# ----------------------------------------------------------------------------------------------
def feuille_capacite_horaire(wb, R):
    if not R.horaire:
        return None
    ws, der = _feuille(wb, "Capacité horaire", "CHME AMBULATOIRE / CAPACITÉ HORAIRE DES MÉDECINS",
                       "Capacité d’un médecin = créneaux de rendez-vous par jour (fichier RDV_Horaire, conservé dans le référentiel). "
                       "Un médecin peut recevoir plus (patients sans rendez-vous) ou moins (absences) que ses créneaux. Le repère "
                       f"de {R.cap_jour} visites reste affiché pour comparaison.", [34, 26, 14, 12, 12, 13, 13, 12, 13, 13, 13, 13])
    par_nom = {str(l[0]): l for l in R.ref["CAPACITE_HORAIRE"]}
    inverse = {m: n for n, m in R.rdv_lien.items()}
    site, act = SITES_HORAIRE[0]
    md = R.medjour[(site, act)]
    par_med = defaultdict(list)
    for (j, m), c in md.items():
        if c["cab"]:
            par_med[m].append(c)
    # Par spécialité
    spec = defaultdict(lambda: {"med": set(), "rdv": set(), "jours": 0, "vis": 0, "cap": 0})
    for (s, a, m), (cap, src, sp) in R.cap_detail.items():
        if (s, a) != (site, act) or m not in par_med:
            continue
        x = spec[sp]
        x["med"].add(m)
        if src.startswith("Rendez-vous"):
            x["rdv"].add(m)
        x["jours"] += len(par_med[m])
        x["vis"] += sum(c["total"] for c in par_med[m])
        x["cap"] += sum(c["cap"] for c in par_med[m])
    lignes = []
    for sp, x in sorted(spec.items(), key=lambda kv: -kv[1]["vis"]):
        c24 = x["jours"] * R.cap_jour
        lignes.append([sp, len(x["med"]), len(x["rdv"]), x["jours"], x["vis"], x["cap"], c24,
                       x["vis"] / x["cap"] if x["cap"] else None, x["vis"] / c24 if c24 else None,
                       x["vis"] / x["jours"] if x["jours"] else None, x["cap"] / x["jours"] if x["jours"] else None])
    f = [None, NB, NB, NB, NB, NB, NB, PCT, PCT, "0.0", "0.0"]
    r, d0 = _tableau(ws, 4, "PAR SPÉCIALITÉ (JOURNÉES DE CABINET COMPTÉES, À PARTIR DE 2 VISITES)", der,
                     ["Spécialité", "Médecins", "dont avec\nrendez-vous", "Journées de\ncabinet", "Visites", "Capacité\nhoraire",
                      f"Capacité\nrepère {R.cap_jour}", "Utilisation\nhoraire", f"Utilisation\nrepère {R.cap_jour}",
                      "Visites par\njournée", "Créneaux par\njournée"], lignes, f)
    for i, lg in enumerate(lignes):
        colorer(ws, d0 + i, 8, lg[7], R)
    tot = [sum(l[k] for l in lignes) for k in (1, 2, 3, 4, 5, 6)]
    r = _total(ws, r, ["Total CHME ambulatoire", *tot, tot[3] / tot[4] if tot[4] else None, tot[3] / tot[5] if tot[5] else None,
                       tot[3] / tot[2] if tot[2] else None, tot[4] / tot[2] if tot[2] else None], f)
    # Par médecin
    lignes = []
    for m, cs in sorted(par_med.items(), key=lambda kv: (-sum(c["total"] for c in kv[1]), kv[0])):
        cap, src, sp = R.cap_detail.get((site, act, m), (R.cap_jour, f"Repère de {R.cap_jour}", "–"))
        l = par_nom.get(inverse.get(m, ""), [None] * 6)
        vis = sum(c["total"] for c in cs)
        moy = vis / len(cs)
        lignes.append([m, sp, src.replace("Rendez-vous du médecin", "Rendez-vous").replace("Médiane de la spécialité", "Médiane spécialité"),
                       l[2], l[3], cap, len(cs), vis, moy, moy / cap if cap else None, sum(c["audela"] for c in cs)])
    f = [None, None, None, NB, "0.0", NB, NB, NB, "0.0", PCT, NB]
    r, d0 = _tableau(ws, r + 2, "PAR MÉDECIN (CLASSÉ PAR VOLUME DE VISITES)", der,
                     ["Médecin", "Spécialité", "Source de la\ncapacité", "Durée d’une\nconsultation (min)", "Plage\n(heures)",
                      "Créneaux\npar jour", "Journées de\ncabinet", "Visites", "Visites par\njournée", "Remplissage\ndes créneaux",
                      "Journées au-\ndelà créneaux"], lignes, f)
    for i, lg in enumerate(lignes):
        colorer(ws, d0 + i, 10, lg[9], R)
    # Praticiens du fichier RDV sans visite retrouvée
    sans = [l for n, l in par_nom.items() if n not in R.rdv_lien]
    if sans:
        lignes = [[l[0], l[1], l[2], l[3], l[4], l[5]] for l in sorted(sans, key=lambda l: str(l[0]))]
        _tableau(ws, r + 2, "PRATICIENS / RESSOURCES DU FICHIER DES RENDEZ-VOUS SANS VISITE RETROUVÉE DANS LA PÉRIODE", der,
                 ["Nom (fichier des rendez-vous)", "Type de consultation", "Durée (min)", "Plage (h)", "Créneaux\npar jour",
                  "Jours avec\nrendez-vous"], lignes, [None, None, NB, "0.0", NB, NB])
    return ws


# ----------------------------------------------------------------------------------------------
# Plannings
# ----------------------------------------------------------------------------------------------
def feuille_plannings(wb, R):
    ws, der = _feuille(wb, "Plannings", "PLANNINGS / JOURS DE LA SEMAINE, HEURES ET SPÉCIALITÉS",
                       "Moyennes par jour de la semaine sur la période. Heures = heure d’enregistrement de la visite dans le logiciel "
                       "(pas forcément l’heure de consultation). Cabinets CHME = médecins spécialistes ayant au moins 2 visites dans "
                       "la journée.", [30, 12, 12, 12, 12, 12, 12, 12, 12, 12])
    nb_jours = Counter(j.weekday() for j in R.jours)
    cs = {l["jour"]: l for l in R.jours_act[("CSMKL2", V.PRINCIPALE)]}
    amb = {l["jour"]: l for l in R.jours_act[("CHME", V.AMBULATOIRE)]}
    urg = {l["jour"]: l for l in R.jours_act[("CHME", V.URGENCES)]}
    prog = Counter()
    if getattr(R, "creneaux", None):
        par_jour = defaultdict(set)
        for c in R.creneaux:
            if R.debut <= c["date"] < R.fin_excl:
                par_jour[c["date"].date()].add(c["medecin"])
        for j in R.jours:
            prog[j.weekday()] += len(par_jour.get(j.date(), ()))
    lignes = []
    for w in range(7):
        js = [j for j in R.jours if j.weekday() == w]
        if not js:
            continue
        n = len(js)
        cab_ch = [R.cab_chme_spec[j] for j in js]
        lignes.append([JOURS[w], n, sum(cs[j]["total"] for j in js) / n, sum(cs[j]["cab"] for j in js) / n,
                       sum(amb[j]["total"] for j in js) / n, _ratio(sum(amb[j]["total"] for j in js), sum(amb[j]["cap"] for j in js)),
                       sum(cab_ch) / n, max(cab_ch), prog[w] / n if prog else None, sum(urg[j]["total"] for j in js) / n])
    f = [None, NB, "0.0", "0.0", "0.0", PCT, "0.0", NB, "0.0", "0.0"]
    r, d0 = _tableau(ws, 4, "PAR JOUR DE LA SEMAINE (MOYENNE PAR JOUR)", der,
                     ["Jour", "Jours dans\nla période", "CSMKL2\nvisites", "CSMKL2\ncabinets", "CHME amb.\nvisites",
                      "CHME amb.\nutilisation", f"CHME cabinets\n(sur {R.cabinets_chme})", "CHME cabinets\nmaximum",
                      "CHME médecins\nprogrammés (RDV)", "Urgences\nvisites"], lignes, f)
    for i, lg in enumerate(lignes):
        colorer(ws, d0 + i, 6, lg[5], R)
        if lg[6] > R.cabinets_chme:
            colorer(ws, d0 + i, 7, 1, R, force=True)
        if lg[7] > R.cabinets_chme:
            colorer(ws, d0 + i, 8, 1, R, force=True)
    # Par heure
    heures = defaultdict(Counter)
    for v in _consultations(R):
        k = {("CSMKL2", V.PRINCIPALE): "cs", ("CHME", V.AMBULATOIRE): "amb", ("CHME", V.URGENCES): "urg"}.get((v["site"], v["activite"]))
        if k and v["date"]:
            heures[k][v["date"].hour] += 1
    tot = {k: sum(c.values()) or 1 for k, c in heures.items()}
    hs = sorted({h for c in heures.values() for h in c})
    lignes = [[f"{h:02d} h - {h + 1:02d} h", heures["cs"][h], heures["cs"][h] / tot.get("cs", 1), heures["amb"][h],
               heures["amb"][h] / tot.get("amb", 1), heures["urg"][h], heures["urg"][h] / tot.get("urg", 1)] for h in hs]
    r, d0 = _tableau(ws, r + 2, "PAR HEURE D’ENREGISTREMENT (VISITES DE LA PÉRIODE)", der,
                     ["Heure", "CSMKL2\nvisites", "CSMKL2\npart", "CHME amb.\nvisites", "CHME amb.\npart", "Urgences\nvisites",
                      "Urgences\npart"], lignes, [None, NB, PCT, NB, PCT, NB, PCT])
    # Spécialités x jour de la semaine
    md = R.medjour[("CHME", V.AMBULATOIRE)]
    spec_med = defaultdict(Counter)
    for v in R.visites:
        if v["site"] == "CHME" and v["activite"] == V.AMBULATOIRE and v["medecin"]:
            spec_med[(v["jour"], v["medecin"])][v["specialite"]] += 1
    grille = defaultdict(Counter)
    for (j, m), c in md.items():
        if not c["cab"]:
            continue
        sp = spec_med[(j, m)].most_common(1)[0][0]
        if sp in R.hors_cabinets:
            continue
        grille[sp][j.weekday()] += 1
    jours_ouverts = [w for w in range(6) if nb_jours.get(w)]
    lignes = []
    for sp, c in sorted(grille.items(), key=lambda kv: -sum(kv[1].values())):
        lignes.append([sp] + [c[w] / nb_jours[w] for w in jours_ouverts] + [sum(c.values())])
    f = [None] + ["0.0"] * len(jours_ouverts) + [NB]
    r, d0 = _tableau(ws, r + 2, "CHME / MÉDECINS SPÉCIALISTES PRÉSENTS PAR JOUR DE LA SEMAINE (MOYENNE) — BASE DES ROTATIONS DE SALLES", der,
                     ["Spécialité"] + [JOURS[w] for w in jours_ouverts] + ["Journées de\ncabinet"], lignes, f)
    tot = ["Cabinets occupés (moyenne)"] + [sum(g[w] for g in grille.values()) / nb_jours[w] for w in jours_ouverts] + \
          [sum(sum(g.values()) for g in grille.values())]
    r = _total(ws, r, tot, f)
    ecart = [f"Écart aux {R.cabinets_chme} cabinets"] + [t - R.cabinets_chme for t in tot[1:-1]] + [None]
    cellule(ws, r, 1, ecart[0], fmt=None, h="left", gras=True)
    for i, v in enumerate(ecart[1:-1], start=2):
        cellule(ws, r, i, v, fmt="+0.0;-0.0;0", gras=True)
        if v > 0:
            colorer(ws, r, i, 1, R, force=True)
    note_bloc(ws, r + 2, "Lecture : un écart positif signifie qu’en moyenne plus de spécialistes consultent ce jour-là que de "
                         "cabinets disponibles. Déplacer une partie des spécialités chargées vers les jours à écart négatif "
                         "réduit le besoin de salles sans en ouvrir.", 1, der, hauteur=34)
    return ws


# ----------------------------------------------------------------------------------------------
# Séjours
# ----------------------------------------------------------------------------------------------
def feuille_sejours(wb, R):
    ws, der = _feuille(wb, "Séjours", "CHME / DURÉE DES SÉJOURS ET SÉJOURS OUVERTS",
                       "Durée moyenne de séjour (DMS) = sortie - entrée, sur les séjours dont l’entrée et la sortie sont connues et la "
                       "sortie dans la période. Les sorties Evolucare sont des dates provisoires proposées à l’admission.",
                       [30, 12, 12, 12, 12, 12, 12, 12, 13, 13])
    chme = [d for d in R.dossiers if d["site"] == "CHME"]
    lignes = []
    for u in [x["unite"] for x in R.unites]:
        ds = [d for d in chme if d["unite"] == u]

        def dms(lg):
            durees = [(d["sortie"] - d["entree"]).total_seconds() / 86400 for d in ds
                      if d["logiciel"] == lg and d["entree"] and d["sortie"] and d["sortie"] < R.fin_excl and d["sortie"] >= R.debut]
            return len(durees), (st.mean(durees) if durees else None), (st.median(durees) if durees else None)
        ng, mg, medg = dms("GPS")
        ne, me, _ = dms("Evolucare")
        ouverts = [d for d in ds if d["date_connue"] and d["sortie"] is None]
        anciens = sum(1 for d in ouverts if (R.fin_excl - d["entree"]).days > 7)
        lignes.append([u.replace("Hors unités / UF absente", "Sans unité"), sum(1 for d in ds if d["entree_periode"]),
                       sum(d["sortie_periode"] for d in ds), ng, mg, medg, ne, me, len(ouverts), anciens])
    f = [None, NB, NB, NB, "0.0", "0.0", NB, "0.0", NB, NB]
    r, d0 = _tableau(ws, 4, "PAR UNITÉ", der,
                     ["Unité", "Entrées dans\nla période", "Sorties dans\nla période", "Séjours clos\nGPS", "DMS GPS\n(jours)",
                      "Médiane GPS\n(jours)", "Séjours clos\nEvolucare", "DMS Evo\n(provisoire)", "Séjours sans\nsortie", "dont entrés\n> 7 jours"],
                     lignes, f)
    ng = sum(l[3] for l in lignes)
    mg = (sum(l[3] * l[4] for l in lignes if l[4] is not None) / ng) if ng else None
    ne = sum(l[6] for l in lignes)
    me = (sum(l[6] * l[7] for l in lignes if l[7] is not None) / ne) if ne else None
    r = _total(ws, r, ["Total CHME", sum(l[1] for l in lignes), sum(l[2] for l in lignes), ng, mg, None, ne, me,
                       sum(l[8] for l in lignes), sum(l[9] for l in lignes)], f)
    for i, lg in enumerate(lignes):
        if lg[9]:
            colorer(ws, d0 + i, 10, 1, R, force=True)
    note_bloc(ws, r + 1, "Séjours sans sortie entrés depuis plus de 7 jours : à vérifier avec le service (patient encore présent ou "
                         "sortie non enregistrée). Ils gonflent l’occupation des lits.", 1, der, alerte=True, hauteur=30)
    return ws


# ----------------------------------------------------------------------------------------------
# Prises en charge
# ----------------------------------------------------------------------------------------------
def organisme(pec):
    t = texte(pec)
    if not t or t.upper() in ("NONE", "0"):
        return "Non renseignée"
    t = re.split(r"\s+-\s*|\s*-\s+", t)[0]
    t = re.sub(r"\s*\([^)]*\)\s*", " ", t).strip()
    return t or "Non renseignée"


def feuille_prises_en_charge(wb, R):
    ws, der = _feuille(wb, "Prises en charge", "PRISES EN CHARGE / VISITES PAR ORGANISME",
                       "Colonne PEC des exports de visites (hors suivi d’hospitalisation). Organisme = libellé avant le tiret "
                       "(ex. « MONKOLE(AMO) -Catégorie B » → MONKOLE). Détail complet dans le second tableau.",
                       [40, 12, 12, 12, 12, 12, 12, 12])
    brut = _brutes(R)
    org = defaultdict(Counter)
    detail = defaultdict(Counter)
    for v in _consultations(R):
        p = brut.get((v["logiciel"], v["ligne"]), {}).get("PEC")
        org[organisme(p)][v["site"]] += 1
        detail[(texte(p) or "Non renseignée", v["logiciel"])][v["site"]] += 1
    total = sum(sum(c.values()) for c in org.values()) or 1
    lignes = [[o, c["CSMKL2"], c["CHME"], sum(c.values()), sum(c.values()) / total]
              for o, c in sorted(org.items(), key=lambda kv: -sum(kv[1].values()))]
    f = [None, NB, NB, NB, PCT]
    r, _ = _tableau(ws, 4, "PAR ORGANISME", der, ["Organisme / prise en charge", "CSMKL2", "CHME", "Total visites", "Part"],
                    lignes[:30], f)
    if len(lignes) > 30:
        reste = lignes[30:]
        cellule(ws, r, 1, f"Autres organismes ({len(reste)})", fmt=None, h="left")
        for i in range(1, 4):
            cellule(ws, r, 1 + i, sum(l[i] for l in reste))
        cellule(ws, r, 5, sum(l[3] for l in reste) / total, fmt=PCT)
        r += 1
    r = _total(ws, r, ["Total", sum(l[1] for l in lignes), sum(l[2] for l in lignes), sum(l[3] for l in lignes), 1], f)
    lignes = [[p, lg, c["CSMKL2"], c["CHME"], sum(c.values())] for (p, lg), c in
              sorted(detail.items(), key=lambda kv: (-sum(kv[1].values()), kv[0]))][:40]
    _tableau(ws, r + 2, "DÉTAIL DES LIBELLÉS (40 PLUS FRÉQUENTS)", der, ["Libellé PEC", "Logiciel", "CSMKL2", "CHME", "Total"],
             lignes, [None, None, NB, NB, NB])
    return ws


# ----------------------------------------------------------------------------------------------
# Origine des patients
# ----------------------------------------------------------------------------------------------
_CLES_COMMUNES = sorted(((re.sub(r"[^a-z]", "", cle(c)), c) for c in COMMUNES_KINSHASA), key=lambda x: -len(x[0]))


def commune(pat1, pat2):
    """Commune de Kinshasa : Adresse_PAT1 (GPS), « quartier - commune » (Evolucare), sinon recherche dans le texte."""
    for t in ([pat1] if pat1 else []) + ([str(pat2).rsplit(" - ", 1)[-1]] if pat2 and " - " in str(pat2) else []) + [f"{pat1} {pat2}"]:
        k = re.sub(r"[^a-z]", "", cle(str(t)))
        if not k or k in ("none", "0"):
            continue
        for kc, c in _CLES_COMMUNES:
            if kc == k or (len(kc) >= 5 and kc in k):
                return c
        if k == "autre" or k == "autreautre":
            return AUTRE_COMMUNE
    return NON_IDENTIFIEE


def feuille_origine(wb, R):
    ws, der = _feuille(wb, "Origine patients", "ORIGINE DES PATIENTS / COMMUNES DE KINSHASA",
                       "Dossiers distincts par logiciel (pas de patients uniques entre logiciels), selon l’adresse des exports de "
                       "visites : commune (GPS) ou « quartier - commune » (Evolucare).", [34, 13, 13, 13, 12, 12, 12])
    brut = _brutes(R)
    par_dos = {}
    for v in _consultations(R):
        if v["cle"] in par_dos:
            continue
        b = brut.get((v["logiciel"], v["ligne"]), {})
        par_dos[v["cle"]] = (v["site"], commune(b.get("Adresse_PAT1"), b.get("Adresse_PAT2")))
    comptes = defaultdict(Counter)
    for site, c in par_dos.values():
        comptes[c][site] += 1
    total = len(par_dos) or 1
    lignes = [[c, x["CSMKL2"], x["CHME"], sum(x.values()), sum(x.values()) / total]
              for c, x in sorted(comptes.items(), key=lambda kv: (kv[0] in (AUTRE_COMMUNE, NON_IDENTIFIEE), -sum(kv[1].values())))]
    f = [None, NB, NB, NB, PCT]
    r, _ = _tableau(ws, 4, "DOSSIERS PAR COMMUNE", der, ["Commune", "CSMKL2", "CHME", "Total dossiers", "Part"], lignes, f)
    _total(ws, r, ["Total", sum(l[1] for l in lignes), sum(l[2] for l in lignes), len(par_dos), 1], f)
    return ws


# ----------------------------------------------------------------------------------------------
# Qualité de saisie
# ----------------------------------------------------------------------------------------------
def feuille_qualite(wb, R):
    ws, der = _feuille(wb, "Qualité saisie", "QUALITÉ DE SAISIE / VISITES PAR UTILISATEUR",
                       "Champs manquants dans les visites enregistrées par chaque utilisateur (colonne Utilisateur des exports). "
                       "Sert à cibler la formation, pas à évaluer les personnes.", [30, 12, 12, 12, 12, 12, 12, 12, 13])
    brut = _brutes(R)
    q = defaultdict(Counter)
    for v in _consultations(R):
        b = brut.get((v["logiciel"], v["ligne"]), {})
        u = (texte(b.get("Utilisateur")) or "Utilisateur non renseigné", v["logiciel"])
        x = q[u]
        x["n"] += 1
        manque = {"med": not v["medecin"], "uf": not v["uf"], "motif": not v["motif"] and not v["nature"],
                  "dob": texte(b.get("DOB")) is None, "sexe": texte(b.get("Sexe")) is None}
        for k, m in manque.items():
            x[k] += m
        x["inc"] += any(manque.values())
    lignes = [[u, lg, x["n"], x["med"], x["uf"], x["motif"], x["dob"], x["sexe"], x["inc"] / x["n"]]
              for (u, lg), x in sorted(q.items(), key=lambda kv: (-kv[1]["inc"], -kv[1]["n"], kv[0]))]
    f = [None, None, NB, NB, NB, NB, NB, NB, PCT]
    r, d0 = _tableau(ws, 4, "PAR UTILISATEUR (CLASSÉ PAR NOMBRE DE VISITES INCOMPLÈTES)", der,
                     ["Utilisateur", "Logiciel", "Visites\nsaisies", "Sans\nmédecin", "Sans UF", "Sans motif\nni nature",
                      "Sans date de\nnaissance", "Sans sexe", "Visites\nincomplètes"], lignes, f)
    for i, lg in enumerate(lignes):
        if lg[8] >= 0.2:
            colorer(ws, d0 + i, 9, 1, R, force=True)
    tot = [sum(l[k] for l in lignes) for k in range(2, 8)]
    _total(ws, r, ["Total", "", *tot, sum(x["inc"] for x in q.values()) / (tot[0] or 1)], f)
    return ws


# ----------------------------------------------------------------------------------------------
# Historique
# ----------------------------------------------------------------------------------------------
def feuille_historique(wb, R, historique):
    if not historique:
        return None
    ws, der = _feuille(wb, "Historique", "HISTORIQUE / ÉVOLUTION D’UNE PÉRIODE À L’AUTRE",
                       "Une ligne par période traitée sur cet ordinateur (fichier historique/Historique_activites.xlsx). Les valeurs "
                       "par jour permettent de comparer des périodes de longueurs différentes.", [26] + [12] * 12)
    cols = ["Période", "Jours", "CSMKL2\nvisites / jour", "CSMKL2\nutilisation", "CHME amb.\nvisites / jour", "CHME amb.\nutilisation",
            "Urgences\nvisites / jour", "Lits occupés\nmoy. / jour", "Occupation\ndes lits", "Actes GPS\npar jour",
            "Actes Evo\npar jour", "Capacité\nCHME", "Calculé le"]
    lignes = [[h.get(c) for c in cols] for h in historique]
    f = [None, NB, "0.0", PCT, "0.0", PCT, "0.0", "0.0", PCT, "0.0", "0.0", None, "dd/mm/yyyy"]
    r, d0 = _tableau(ws, 4, "PÉRIODES TRAITÉES", der, cols, lignes, f)
    for i, lg in enumerate(lignes):
        colorer(ws, d0 + i, 4, lg[3], R)
        colorer(ws, d0 + i, 6, lg[5], R)
        colorer(ws, d0 + i, 9, lg[8], R)
    if len(lignes) >= 2:
        a, b = lignes[-2], lignes[-1]
        ev = ["Évolution (dernière / précédente)", None] + [(b[i] / a[i] - 1) if (isinstance(a[i], (int, float)) and a[i]) else None
                                                             for i in range(2, 11)] + [None, None]
        for i, v in enumerate(ev):
            cellule(ws, r, i + 1, v if v is not None else ("" if i else ev[0]), fmt="+0.0%;-0.0%;0.0%" if i else None,
                    h="left" if i == 0 else "right", gras=True)
    return ws
