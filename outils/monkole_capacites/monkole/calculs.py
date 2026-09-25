"""Agrégations communes aux feuilles : journées-intervenants, jours, cabinets, actes, hospitalisation."""
import datetime as dt
from collections import Counter, defaultdict

from . import actes as A
from . import hospi as H
from . import visites as V
from .texte import cle

SANS_MEDECIN = "Sans médecin renseigné"
UN_JOUR = dt.timedelta(days=1)


class Resultats:
    pass


def _compte():
    return {"cons": 0, "avant": 0, "apres": 0, "total": 0, "gps": 0, "evo": 0}


def _ajouter(c, v):
    k = {V.CONSULTATION: "cons", V.AVANT: "avant", V.APRES: "apres"}.get(v["classe"])
    if k:
        c[k] += 1
    c["total"] += 1
    c["gps" if v["logiciel"] == "GPS" else "evo"] += 1


def calculer(donnees, ref, debut, fin):
    """debut / fin : dates incluses de la période (datetime à minuit)."""
    R = Resultats()
    R.ref = ref
    R.donnees = donnees
    R.debut, R.fin = debut, fin
    R.fin_excl = fin + UN_JOUR
    R.jours = [debut + UN_JOUR * i for i in range((fin - debut).days + 1)]
    R.ndays = len(R.jours)
    p = ref["PARAMETRES"]
    R.cap_jour = p["visites_par_intervenant_jour"]
    R.seuil = p["seuil_visites_cabinet"]
    R.cabinets_csmkl2 = p["cabinets_physiques_csmkl2"]
    R.lits = ref["LITS"]
    R.total_lits = sum(n for _, n in R.lits)

    visites_toutes, R.rapprochements, R.uf_inconnues = V.classer_visites(donnees, ref)
    R.visites_hors_periode = [v for v in visites_toutes if v["jour"] is None or not (debut <= v["jour"] <= fin)]
    hors = {id(v) for v in R.visites_hors_periode}
    R.visites = [v for v in visites_toutes if id(v) not in hors]
    R.visites_source = len(visites_toutes)
    for v in R.visites:
        v["medecin_aff"] = v["medecin"] or SANS_MEDECIN

    evo_sites = [(str(r["Num_dossier"]).strip() if r["Num_dossier"] is not None else None, r["Etablissement"])
                 for r in donnees["actes_evo"]]
    R.base_hospi, R.dossiers, _ = H.construire(donnees, ref, R.visites, evo_sites, debut, R.fin_excl)

    # Profil exclusif des dossiers pour les actes
    hosp = {d["cle"] for d in R.dossiers}
    acts = defaultdict(set)
    premiere = {}
    for v in R.visites:
        acts[v["cle"]].add(v["activite"])
        if v["cle"] not in premiere or v["date"] < premiere[v["cle"]]:
            premiere[v["cle"]] = v["date"]

    def profil(k):
        if k in hosp:
            return "Hospitalisation"
        s = acts.get(k, set())
        if s & {V.AMBULATOIRE, V.PRINCIPALE, V.SECONDAIRE}:
            return "Ambulatoire"
        if V.URGENCES in s:
            return "Urgences"
        return "Sans correspondance"

    actes_tous, R.categories_inconnues = A.classer_actes(donnees, ref, profil, lambda k: premiere.get(k))
    R.actes_source = len(actes_tous)
    for a in actes_tous:
        a["dans_periode"] = 1 if (a["jour"] is not None and debut <= a["jour"] <= fin) else 0
    R.actes_tous = actes_tous
    R.actes = [a for a in actes_tous if a["retenue"] and a["dans_periode"]]
    R.produits = [a for a in actes_tous if a["produit"]]
    R.actes_hors_periode = [a for a in actes_tous if a["retenue"] and not a["dans_periode"]]

    _visites_par_activite(R)
    _hospitalisation(R)
    return R


def _visites_par_activite(R):
    seuil, cap = R.seuil, R.cap_jour
    # Journées-intervenants par site / activité (identités absentes exclues des cabinets)
    R.medjour = {}
    R.jours_act = {}
    for site, act in (("CSMKL2", V.PRINCIPALE), ("CSMKL2", V.SECONDAIRE), ("CHME", V.AMBULATOIRE), ("CHME", V.URGENCES)):
        vs = [v for v in R.visites if v["site"] == site and v["activite"] == act]
        md = defaultdict(_compte)
        par_jour = {j: _compte() for j in R.jours}
        sans = Counter()
        for v in vs:
            _ajouter(par_jour[v["jour"]], v)
            if v["medecin"]:
                _ajouter(md[(v["jour"], v["medecin"])], v)
            else:
                sans[v["jour"]] += 1
        for c in md.values():
            c["cab"] = 1 if c["total"] >= seuil else 0
            c["cap"] = c["cab"] * cap
            c["marge"] = max(cap - c["total"], 0) if c["cab"] else 0
            c["audela"] = 1 if c["total"] > cap else 0
            c["isolee"] = 1 if c["total"] == 1 else 0
        R.medjour[(site, act)] = md
        lignes = []
        for j in R.jours:
            c = dict(par_jour[j])
            dj = [x for (jj, _), x in md.items() if jj == j]
            c["jour"] = j
            c["cab"] = sum(x["cab"] for x in dj)
            c["cap"] = sum(x["cap"] for x in dj)
            c["util"] = c["total"] / c["cap"] if c["cap"] else None
            c["marge"] = sum(x["marge"] for x in dj)
            c["depass"] = sum(x["audela"] for x in dj)
            c["sans"] = sans[j]
            c["isolees"] = sum(x["isolee"] for x in dj)
            c["jour_audela"] = 1 if (c["cap"] > 0 and c["total"] > c["cap"]) else 0
            lignes.append(c)
        R.jours_act[(site, act)] = lignes

    # Cabinets toutes activités (hors hospitalisation) par site et jour
    R.cab_site = {}
    for site, acts in (("CSMKL2", (V.PRINCIPALE, V.SECONDAIRE)), ("CHME", (V.AMBULATOIRE, V.URGENCES))):
        cnt = Counter()
        for v in R.visites:
            if v["site"] == site and v["activite"] in acts and v["medecin"]:
                cnt[(v["jour"], v["medecin"])] += 1
        R.cab_site[site] = {j: sum(1 for (jj, _), n in cnt.items() if jj == j and n >= seuil) for j in R.jours}

    # Journées intervenants par spécialité (CHME ambulatoire et urgences)
    R.spec_jour = defaultdict(Counter)
    for v in R.visites:
        if v["site"] == "CHME" and v["activite"] in (V.AMBULATOIRE, V.URGENCES) and v["medecin"]:
            R.spec_jour[v["specialite"]][(v["jour"], v["medecin"])] += 1


def indicateurs(R, site, act):
    lignes = R.jours_act[(site, act)]
    md = R.medjour[(site, act)]
    tot = lambda k: sum(l[k] for l in lignes)
    out = {k: tot(k) for k in ("total", "gps", "evo", "cons", "avant", "apres", "cab", "cap", "marge", "depass", "sans", "isolees", "jour_audela")}
    out["util"] = out["total"] / out["cap"] if out["cap"] else None
    out["pic_medecin"] = max((c["total"] for c in md.values()), default=0)
    pic = max(lignes, key=lambda l: l["total"]) if lignes else None   # première date en cas d'égalité
    out["pic_jour"] = pic["total"] if pic else 0
    out["date_pic"] = pic["jour"] if pic else None
    out["gps_pic"] = pic["gps"] if pic else 0
    out["evo_pic"] = pic["evo"] if pic else 0
    return out


def _hospitalisation(R):
    unites = [u for u, _ in R.lits]
    lits = dict(R.lits)
    hors = R.ref["HORS_UNITES"]
    R.unites = []
    chme = [d for d in R.dossiers if d["site"] == "CHME"]
    for u in unites + [hors]:
        g = [d for d in chme if d["logiciel"] == "GPS" and d["unite"] == u]
        e = [d for d in chme if d["logiciel"] == "Evolucare" and d["unite"] == u]
        jg = sum(d["jours_connus"] or 0 for d in g)
        je = sum(d["jours_connus"] or 0 for d in e)
        n = lits.get(u)
        R.unites.append({
            "unite": u, "lits": n, "dos_gps": len(g), "dos_evo": len(e),
            "sans_gps": sum(1 for d in g if not d["date_connue"]), "sans_evo": sum(1 for d in e if not d["date_connue"]),
            "jours_gps": jg, "jours_evo": je,
            "occ_gps": (jg / (n * R.ndays)) if n else None, "occ_evo": (je / (n * R.ndays)) if n else None,
            "sorties_gps": sum(d["sortie_periode"] for d in g), "sorties_evo": sum(d["sortie_periode"] for d in e),
        })
    huit = [x for x in R.unites if x["lits"]]
    t = {k: sum(x[k] for x in R.unites) for k in ("dos_gps", "dos_evo", "sans_gps", "sans_evo", "jours_gps", "jours_evo", "sorties_gps", "sorties_evo")}
    t["lits"] = R.total_lits
    t["occ_gps"] = sum(x["jours_gps"] for x in huit) / (R.total_lits * R.ndays)
    t["occ_evo"] = sum(x["jours_evo"] for x in huit) / (R.total_lits * R.ndays)
    R.unites_total = t
    # Série quotidienne (moyenne sur 24 h)
    R.hospi_jour = []
    for i, j in enumerate(R.jours):
        g = sum(d["par_jour"][i] for d in chme if d["logiciel"] == "GPS" and d["unite"] in lits)
        e = sum(d["par_jour"][i] for d in chme if d["logiciel"] == "Evolucare" and d["unite"] in lits)
        R.hospi_jour.append({
            "jour": j, "gps": g, "evo": e, "lits": R.total_lits,
            "ent_gps": sum(1 for d in chme if d["logiciel"] == "GPS" and d["entree"] and d["entree_periode"] and d["entree"].date() == j.date()),
            "ent_evo": sum(1 for d in chme if d["logiciel"] == "Evolucare" and d["entree"] and d["entree_periode"] and d["entree"].date() == j.date()),
            "sor_gps": sum(1 for d in chme if d["logiciel"] == "GPS" and d["sortie"] and d["sortie_periode"] and d["sortie"].date() == j.date()),
            "sor_evo": sum(1 for d in chme if d["logiciel"] == "Evolucare" and d["sortie"] and d["sortie_periode"] and d["sortie"].date() == j.date()),
            "sans_trace": sum(1 for d in chme if not d["date_connue"] and j in d["jours_traces"]),
        })
    R.sans_site = [d for d in R.dossiers if d["site"] not in ("CHME", "CSMKL2")]
    R.hospi_evo_non_attribues = [b for b in R.base_hospi if b["logiciel"] == "Evolucare" and b["site"] not in ("CHME", "CSMKL2")]


def actes_site(R, site):
    return [a for a in R.actes if a["site"] == site]


def arbre_actes(R, site):
    """Spécialité -> sous-spécialité -> acte, avec colonnes du modèle. Tri : Laboratoire, Imagerie, puis volume."""
    acts = actes_site(R, site)
    idx = {j: i for i, j in enumerate(R.jours)}

    def agreger(liste):
        g = [a for a in liste if a["logiciel"] == "GPS"]
        e = [a for a in liste if a["logiciel"] == "Evolucare"]
        jg = [0] * R.ndays
        je = [0] * R.ndays
        for a in g:
            jg[idx[a["jour"]]] += 1
        for a in e:
            je[idx[a["jour"]]] += 1
        pg = max(jg) if jg else 0
        pe = max(je) if je else 0
        return {
            "gps": len(g), "evo": len(e),
            "dos_gps": len({a["cle"] for a in g}), "dos_evo": len({a["cle"] for a in e}),
            "qte": sum(a["quantite"] or 0 for a in e),
            "hosp_gps": sum(1 for a in g if a["profil"] == "Hospitalisation"),
            "hosp_evo": sum(1 for a in e if a["profil"] == "Hospitalisation"),
            "sans_gps": sum(1 for a in g if a["profil"] == "Sans correspondance"),
            "sans_evo": sum(1 for a in e if a["profil"] == "Sans correspondance"),
            "cons_gps": sum(a["consultation"] for a in g), "cons_evo": sum(a["consultation"] for a in e),
            "jours_gps": jg, "jours_evo": je,
            "pic_gps": pg, "date_pic_gps": R.jours[jg.index(pg)] if pg else None,
            "pic_evo": pe, "date_pic_evo": R.jours[je.index(pe)] if pe else None,
        }

    ordre_a = {}
    for i, a in enumerate(acts):
        ordre_a.setdefault((a["specialite"], a["sous_specialite"], a["libelle"]), i)
    specs = defaultdict(lambda: defaultdict(lambda: defaultdict(list)))
    for a in acts:
        specs[a["specialite"]][a["sous_specialite"]][a["libelle"]].append(a)
    prioritaires = {"Laboratoire": 0, "Imagerie Médicale": 1}

    def cle_vol(nom, liste_actes):
        g = sum(1 for a in liste_actes if a["logiciel"] == "GPS")
        return (-len(liste_actes), -g, nom)

    arbre = []
    for sp, subs in specs.items():
        tout_sp = [a for s in subs.values() for l in s.values() for a in l]
        noeud_sp = {"nom": sp, "agg": agreger(tout_sp), "subs": [], "_n": tout_sp}
        for sb, libs in subs.items():
            tout_sb = [a for l in libs.values() for a in l]
            noeud_sb = {"nom": sb, "agg": agreger(tout_sb), "actes": [], "_n": tout_sb}
            for lib, l in libs.items():
                noeud_sb["actes"].append({"nom": lib, "agg": agreger(l), "_n": l, "_o": ordre_a[(sp, sb, lib)]})
            noeud_sb["actes"].sort(key=lambda x: (-len(x["_n"]), cle(x["nom"])))
            noeud_sp["subs"].append(noeud_sb)
        noeud_sp["subs"].sort(key=lambda x: cle_vol(x["nom"], x["_n"]))
        arbre.append(noeud_sp)
    arbre.sort(key=lambda x: (prioritaires.get(x["nom"], 9),) + cle_vol(x["nom"], x["_n"]))
    return arbre, agreger(acts)
