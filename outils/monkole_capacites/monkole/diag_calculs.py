"""Diagnostics : lecture des deux exports, rattachement au dictionnaire, comptages."""
import datetime as dt
import os
import re
from collections import Counter, defaultdict

from . import diag_dictionnaire as DD
from .classeur_brut import lignes_classeur
from .texte import cle, dossier, jour

EXPORTS = {
    "GPS": (r"^diagnostic_gps", ["Date", "Num_Dossier", "Diagnostic", "Nature", "Etablissement"]),
    "Evolucare": (r"^diagnostic_evolucare", ["Date", "Num_Dossier", "Diagnostic", "Nature", "Etablissement"]),
}
OPTIONNELLES = ["Sexe", "DOB", "Unité Médicale", "UF"]      # lues si présentes (âge, sexe, spécialité)
SANS_SITE = "Site non renseigné"
SITES = ["CSMKL2", "CHME"]
UN_JOUR = dt.timedelta(days=1)

# Marqueurs de doute pour les propositions automatiques
DOUTE = re.compile(r"\?|\b(a|à) exclure\b|\bexclure\b|\bsuspicion\b|\bsuspect|\bprobable\b|\bpossible\b|\bnon exclue?\b|"
                   r"\br/o\b|\bа eliminer\b|\ba eliminer\b|\bdd\b|\bdiff[eé]rentiel", re.I)
SEPARATEURS = re.compile(r"\s*(?:/|;|\+|\n|,|:|\bet\b|\bvs\.?\b|\bou\b|\bavec\b|\bsur\b|\bassoci[ée]e?s? [àa]\b)\s*", re.I)


class ErreurExport(Exception):
    pass


def trouver(dossier_entrees):
    trouves = {}
    for nom in sorted(os.listdir(dossier_entrees)):
        if not nom.lower().endswith(".xlsx") or nom.startswith("~$"):
            continue
        base = re.sub(r"^[0-9a-f]{8}-", "", nom, flags=re.I).lower()
        for lg, (motif, _) in EXPORTS.items():
            if re.search(motif, base):
                if lg in trouves:
                    raise ErreurExport(f"Deux fichiers de diagnostics {lg} dans « entrees » : {os.path.basename(trouves[lg])} et {nom}.")
                trouves[lg] = os.path.join(dossier_entrees, nom)
    manquants = [lg for lg in EXPORTS if lg not in trouves]
    if manquants:
        raise ErreurExport("Export(s) de diagnostics introuvable(s) : " + ", ".join(f"Diagnostic_{m}_…xlsx" for m in manquants))
    return trouves


def lire(chemin, colonnes):
    lignes = lignes_classeur(chemin)
    n0, entete = lignes[0]
    cles = [cle(h) if h is not None else "" for h in entete]
    idx = {}
    for c in colonnes:
        if cle(c) not in cles:
            raise ErreurExport(f"Colonne « {c} » absente de {os.path.basename(chemin)}.")
        idx[c] = cles.index(cle(c))
    for c in OPTIONNELLES:
        if cle(c) in cles:
            idx[c] = cles.index(cle(c))
    out = []
    for n, row in lignes[1:]:
        if not row or all(v is None for v in row):
            continue
        d = {c: (row[i] if i < len(row) else None) for c, i in idx.items()}
        d["_ligne"] = n
        out.append(d)
    return out, n0


class Resultats:
    pass


def _texte_source(v):
    if v is None:
        return ""
    return v if isinstance(v, str) else str(v)


def proposer(texte, d):
    """Décomposition d'une formulation nouvelle en morceaux déjà connus du dictionnaire.
    Renvoie (lignes [(code, statut)], complet)."""
    morceaux = [m for m in SEPARATEURS.split(texte) if m and m.strip(" .?-")]
    if not morceaux:
        return [], False
    lignes, complet = [], True
    for m in morceaux:
        doute = bool(DOUTE.search(m))
        k = DD.cle_texte(re.sub(r"\?+", " ", m))
        k = re.sub(r"\s+", " ", k).strip()
        i = d.normal.get(k)
        if i is None:
            complet = False
            continue
        for code, statut, *_ in d.entrees[i]["lignes"]:
            s = DD.HYPOTHESE if (doute and statut == DD.DECRIT) else statut
            if (code, s) not in lignes:
                lignes.append((code, s))
    return lignes, complet and bool(lignes)


# ------------------------------------------------------------------------------------------------
# Âge et sexe
# ------------------------------------------------------------------------------------------------
TRANCHES = [("< 1 an", 0, 1), ("1-4 ans", 1, 5), ("5-14 ans", 5, 15), ("15-24 ans", 15, 25), ("25-49 ans", 25, 50),
            ("50-64 ans", 50, 65), ("65 ans et +", 65, 111)]
AGE_INCONNU = "Âge inconnu"
SEXES = ["F", "M", "Inconnu"]


def _sexe(v):
    v = str(v).strip().upper() if v not in (None, "") else ""
    return v[0] if v[:1] in ("F", "M") else "Inconnu"


def _naissance(v):
    if isinstance(v, dt.datetime):
        return v
    if isinstance(v, dt.date):
        return dt.datetime(v.year, v.month, v.day)
    if isinstance(v, str):
        for f in ("%d/%m/%Y", "%Y-%m-%d", "%d-%m-%Y", "%d/%m/%Y %H:%M:%S", "%Y-%m-%d %H:%M:%S"):
            try:
                return dt.datetime.strptime(v.strip(), f)
            except ValueError:
                pass
    return None


def tranche(naissance, jour_ref):
    if naissance is None or jour_ref is None or naissance > jour_ref:
        return AGE_INCONNU
    age = jour_ref.year - naissance.year - ((jour_ref.month, jour_ref.day) < (naissance.month, naissance.day))
    for nom, a, b in TRANCHES:
        if a <= age < b:
            return nom
    return AGE_INCONNU


def _profils(R):
    """Sexe et tranche d'âge de chaque dossier (première valeur renseignée ; âge au premier jour du dossier dans la période)."""
    R.profils = {}
    for s in sorted(R.sources, key=lambda s: (s["jour"], s["ligne"])):
        p = R.profils.setdefault(s["cle"], {"sexe": "Inconnu", "naissance": None, "jour": s["jour"]})
        if p["sexe"] == "Inconnu":
            p["sexe"] = s["sexe"]
        if p["naissance"] is None:
            p["naissance"] = s["naissance"]
    for p in R.profils.values():
        p["tranche"] = tranche(p["naissance"], p["jour"])


def age_sexe(R, P):
    """Dossiers et diagnostics comptabilisés par tranche d'âge et par sexe pour un périmètre."""
    noms = [t[0] for t in TRANCHES] + [AGE_INCONNU]
    A = {n: {"dos": Counter(), "diag": 0, "groupes": Counter(), "familles": Counter()} for n in noms}
    S = {x: {"dos": 0, "diag": 0, "groupes": Counter(), "familles": Counter()} for x in SEXES}
    for k in P.dossiers:
        p = R.profils[k]
        A[p["tranche"]]["dos"][p["sexe"]] += 1
        S[p["sexe"]]["dos"] += 1
    for (k, code), m in P.decrits.items():
        p = R.profils[k]
        for x in (A[p["tranche"]], S[p["sexe"]]):
            x["diag"] += 1
            x["groupes"][m["groupe"]] += 1
            x["familles"][m["famille"]] += 1
    return A, S


def calculer(chemins, d, debut=None, fin=None):
    R = Resultats()
    R.dico = d
    R.chemins = chemins
    R.sources = []
    R.fichiers = {}
    for lg, chemin in chemins.items():
        rows, _ = lire(chemin, EXPORTS[lg][1])
        R.fichiers[lg] = (os.path.basename(chemin), len(rows))
        for r in rows:
            j = jour(r["Date"])
            site = (str(r["Etablissement"]).strip() if r["Etablissement"] not in (None, "") else SANS_SITE)
            num = dossier(r["Num_Dossier"])
            R.sources.append({"id": f"{lg}-{r['_ligne']}", "ligne": r["_ligne"], "logiciel": lg, "jour": j, "site": site,
                              "dossier": num, "cle": f"{lg}|{site}|{num}", "texte": _texte_source(r["Diagnostic"]),
                              "nature": r["Nature"], "fichier": os.path.basename(chemin),
                              "sexe": _sexe(r.get("Sexe")), "naissance": _naissance(r.get("DOB")),
                              "uf": r.get("Unité Médicale") if r.get("Unité Médicale") is not None else r.get("UF")})
    jours = [s["jour"] for s in R.sources if s["jour"]]
    R.debut = debut or min(jours)
    R.fin = fin or max(jours)
    R.hors_periode = [s for s in R.sources if not s["jour"] or not (R.debut <= s["jour"] <= R.fin)]
    if R.hors_periode:
        hors = {id(s) for s in R.hors_periode}
        R.sources = [s for s in R.sources if id(s) not in hors]
    R.jours = [R.debut + UN_JOUR * i for i in range((R.fin - R.debut).days + 1)]
    _profils(R)

    # Rattachement au dictionnaire
    R.nouvelles = {}     # texte -> {"id", "lignes", "complet", "n", "suggestion"}
    prochain = int(d.prochain_id()[1:])
    idx = None
    for s in R.sources:
        t = s["texte"]
        i = d.exact.get(t)
        origine = "dictionnaire"
        if i is None and t.strip() == "":
            i = d.exact.get("")
        if i is None:
            i = d.normal.get(DD.cle_texte(t))
            origine = "variante reconnue" if i else origine
        if i is not None:
            s["id_dico"] = i
            s["lignes"] = [(code, statut) for code, statut, *_ in d.entrees[i]["lignes"]]
            s["origine"] = origine
            continue
        if t not in R.nouvelles:
            lignes, complet = proposer(t, d)
            sugg = []
            if not complet:
                idx = idx if idx is not None else index_expressions(d)
                sugg = suggerer(t, d, idx)
            R.nouvelles[t] = {"id": f"D{prochain:04d}", "lignes": lignes, "complet": complet, "n": 0, "logiciels": set(),
                              "sites": set(), "suggestion": sugg}
            prochain += 1
        nv = R.nouvelles[t]
        nv["n"] += 1
        nv["logiciels"].add(s["logiciel"])
        nv["sites"].add(s["site"])
        s["id_dico"] = nv["id"]
        if nv["complet"]:
            s["lignes"] = list(nv["lignes"])
            s["origine"] = "proposition automatique"
        else:
            s["lignes"] = [(DD.CODE_NOUVEAU, "À clarifier")]
            s["origine"] = "à classer"

    # Mentions
    R.mentions = []
    for s in R.sources:
        for code, statut in s["lignes"]:
            lib, fam, nat = d.groupes.get(code, (code, "À clarifier", "Qualité / ambiguïté"))
            R.mentions.append({"s": s, "code": code, "statut": statut, "groupe": lib, "famille": fam, "nature": nat})
    _agreger(R)
    return R


def _perimetre(R, site=None, logiciel=None):
    """Agrégats pour l'ensemble (site=None) ou un site, éventuellement pour un seul logiciel."""
    P = Resultats()
    garder = lambda s: (site is None or s["site"] == site) and (logiciel is None or s["logiciel"] == logiciel)
    src = [s for s in R.sources if garder(s)]
    men = [m for m in R.mentions if garder(m["s"])]
    P.sources = src
    P.dossiers = {s["cle"] for s in src}
    decrit = {}
    for m in men:
        if m["statut"] == DD.DECRIT:
            decrit.setdefault((m["s"]["cle"], m["code"]), m)
    hyp = {}
    for m in men:
        k = (m["s"]["cle"], m["code"])
        if m["statut"] == DD.HYPOTHESE and k not in decrit:
            hyp.setdefault(k, m)
    P.hyp_toutes = len({(m["s"]["cle"], m["code"]) for m in men if m["statut"] == DD.HYPOTHESE})
    jours = {}
    for m in men:
        if m["statut"] == DD.DECRIT:
            jours.setdefault((m["s"]["cle"], m["code"], m["s"]["jour"]), m)
    P.decrits, P.hyp, P.jours_decrits = decrit, hyp, jours
    P.dossiers_cliniques = {k for k, _ in decrit}
    # Par groupe
    g = defaultdict(lambda: {"gps": 0, "evo": 0, "total": 0, "hyp": 0, "jours": Counter(), "dossiers": set()})
    for (k, code), m in decrit.items():
        x = g[code]
        x["total"] += 1
        x["gps" if m["s"]["logiciel"] == "GPS" else "evo"] += 1
        x["dossiers"].add(k)
    for (k, code), m in hyp.items():
        g[code]["hyp"] += 1
    for (k, code, j), m in jours.items():
        g[code]["jours"][j] += 1
    for code, x in g.items():
        lib, fam, nat = R.dico.groupes.get(code, (code, "À clarifier", "Qualité / ambiguïté"))
        x.update({"code": code, "groupe": lib, "famille": fam, "nature": nat})
        x["jours_presents"] = sum(1 for j in R.jours if x["jours"].get(j))
        x["pic"] = max((x["jours"].get(j, 0) for j in R.jours), default=0)
        x["date_pic"] = next((j for j in R.jours if x["pic"] and x["jours"].get(j, 0) == x["pic"]), None)
    P.groupes = dict(g)
    P.total = len(decrit)
    P.differents = sum(1 for x in g.values() if x["total"] > 0)
    P.recurrents = sum(1 for x in g.values() if len(x["dossiers"]) >= 2)
    # Par famille (16 familles du disque)
    P.familles = {}
    for f in DD.FAMILLES:
        gs = [x for x in g.values() if x["famille"] == f and x["total"] > 0]
        P.familles[f] = {"differents": len(gs), "total": sum(x["total"] for x in gs),
                         "gps": sum(x["gps"] for x in gs), "evo": sum(x["evo"] for x in gs)}
    # Qualité des lignes
    statuts = defaultdict(set)
    for m in men:
        statuts[m["s"]["id"]].add(m["statut"])
    P.lignes_non_exploitables = sum(1 for s in src if "Non exploitable" in statuts[s["id"]])
    P.lignes_a_clarifier = sum(1 for s in src if "À clarifier" in statuts[s["id"]])
    P.lignes_contexte = sum(1 for s in src if statuts[s["id"]] & {"Contexte / antécédent", "Explicitement écarté"})
    P.lignes_gps_50 = sum(1 for s in src if s["logiciel"] == "GPS" and len(s["texte"]) == 50)
    par_dossier = defaultdict(list)
    for s in src:
        par_dossier[s["cle"]].append(statuts[s["id"]])
    P.dossiers_non_exploitables = sum(1 for l in par_dossier.values() if all(x == {"Non exploitable"} for x in l))
    P.somme_jours = len(jours)
    return P


def _agreger(R):
    R.global_ = _perimetre(R)
    R.sites = {s: _perimetre(R, s) for s in SITES + [SANS_SITE]}
    R.tableau = {(s, lg): _perimetre(R, None if s == "Total" else s, None if lg == "Ensemble" else lg)
                 for s in ["Total"] + SITES + [SANS_SITE] for lg in ["Ensemble"] + list(EXPORTS)}
    R.par_logiciel = {}
    for lg in EXPORTS:
        P = Resultats()
        src = [s for s in R.sources if s["logiciel"] == lg]
        P.lignes = len(src)
        P.dossiers = len({s["cle"] for s in src})
        P.decrits = sum(1 for (k, _) in R.global_.decrits if k.startswith(lg + "|"))
        P.hyp = sum(1 for (k, _) in R.global_.hyp if k.startswith(lg + "|"))
        R.par_logiciel[lg] = P
    # Série quotidienne (diagnostics comptabilisés, au plus une fois par dossier et par jour)
    R.quotidien = []
    for j in R.jours:
        l = {"jour": j}
        for site in [None] + SITES + [SANS_SITE]:
            for lg in EXPORTS:
                l[(site, lg)] = sum(1 for (k, _, jj) in R.global_.jours_decrits
                                    if jj == j and k.startswith(lg + "|") and (site is None or k.split("|")[1] == site))
        R.quotidien.append(l)


# ------------------------------------------------------------------------------------------------
# Suggestions (non comptées) : expressions déjà connues retrouvées à l'intérieur d'un texte nouveau
# ------------------------------------------------------------------------------------------------
MOTS_VIDES = {"de", "du", "des", "la", "le", "les", "a", "au", "aux", "et", "en", "sur", "avec", "sans", "un", "une", "d", "l"}


def _mots(t):
    return re.findall(r"[a-z0-9]+", DD.cle_texte(t))


def index_expressions(d):
    """Expressions (suites de mots) des formulations à un seul diagnostic, hors qualité / contexte."""
    idx = {}
    for i, e in d.entrees.items():
        lignes = [l for l in e["lignes"] if d.groupes.get(l[0], ("", "À clarifier", ""))[1] in DD.FAMILLES]
        if len(e["lignes"]) != 1 or not lignes:
            continue
        code, statut = lignes[0][0], lignes[0][1]
        for v in e["versions"]:
            mots = tuple(m for m in _mots(re.sub(r"\?+", " ", v)))
            if not mots or len("".join(mots)) < 4 or all(m in MOTS_VIDES for m in mots):
                continue
            idx.setdefault(mots, (code, DD.DECRIT if statut == DD.HYPOTHESE else statut))
    return idx


def suggerer(texte, d, idx, longueur_max=6):
    """Suggestions [(code, statut)] par plus longue correspondance d'expressions connues."""
    mots = _mots(texte)
    brut = DD.cle_texte(texte)
    sugg = []
    i = 0
    while i < len(mots):
        trouve = None
        for n in range(min(longueur_max, len(mots) - i), 0, -1):
            cle_n = tuple(mots[i:i + n])
            if cle_n in idx:
                trouve = (n, idx[cle_n])
                break
        if trouve:
            n, (code, statut) = trouve
            fenetre = " ".join(mots[max(0, i - 3):i + n + 2])
            doute = bool(DOUTE.search(fenetre)) or ("?" in brut and re.search(re.escape(mots[i + n - 1]) + r"\W*\?", brut))
            s = DD.HYPOTHESE if (doute and statut == DD.DECRIT) else statut
            if code not in [c for c, _ in sugg]:
                sugg.append((code, s))
            i += n
        else:
            i += 1
    return sugg
