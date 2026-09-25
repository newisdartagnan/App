"""Hospitalisation : séjours GPS et Evolucare séparés, intervalles consolidés et occupation."""
import datetime as dt
from collections import Counter, defaultdict

from .texte import date_heure, dossier, texte
from .visites import HOSPITALISATION, unite_hospi

UN_JOUR = dt.timedelta(days=1)


def _fusionner(intervalles):
    out = []
    for a, b in sorted(intervalles):
        if out and a <= out[-1][1]:
            out[-1][1] = max(out[-1][1], b)
        else:
            out.append([a, b])
    return out


def construire(donnees, ref, visites, actes_evo_sites, debut, fin_excl):
    """Renvoie (base_hospi, dossiers). debut / fin_excl : bornes de période [debut, fin_excl[."""
    hors = ref["HORS_UNITES"]
    ndays = (fin_excl - debut).days
    jours = [debut + UN_JOUR * i for i in range(ndays)]

    # Site Evolucare : retrouvé seulement si le Num_Dossier a un site unique dans visites/actes Evolucare
    sites_evo = defaultdict(set)
    for v in visites:
        if v["logiciel"] == "Evolucare" and v["site"] in ("CSMKL2", "CHME"):
            sites_evo[v["dossier"]].add(v["site"])
    for num, site in actes_evo_sites:
        if site in ("CSMKL2", "CHME"):
            sites_evo[num].add(site)

    base = []
    for r in donnees["hospi_gps"]:
        e, s = date_heure(r["Date Entrée"]), date_heure(r["Date Sortie"])
        base.append({"logiciel": "GPS", "ligne": r["_ligne"], "site": texte(r["Site"]) or "Site non renseigné",
                     "dossier": dossier(r["Num_Dossier"]), "entree": e, "sortie": s, "uf": texte(r["UF"]),
                     "unite": unite_hospi(r["UF"], hors), "origine_site": "Site du fichier Hospi"})
    for r in donnees["hospi_evo"]:
        e, s = date_heure(r["Date_Entree"]), date_heure(r["Date_Sortie"])
        num = dossier(r["Num_Dossier"])
        ss = sites_evo.get(num, set())
        if len(ss) == 1:
            site, origine = next(iter(ss)), "Site retrouvé par Num_Dossier dans visites/actes Evolucare"
        else:
            site, origine = "Site non renseigné", ("Aucune correspondance de site" if not ss else "Sites multiples : non attribué")
        base.append({"logiciel": "Evolucare", "ligne": r["_ligne"], "site": site, "dossier": num, "entree": e,
                     "sortie": s, "uf": texte(r["UH"]), "unite": unite_hospi(r["UH"], hors), "origine_site": origine})
    for b in base:
        e, s = b["entree"], b["sortie"]
        b["invalide"] = 1 if (e is not None and s is not None and s < e) else 0
        fin = s if s is not None else fin_excl
        b["dans_periode"] = 1 if (e is not None and not b["invalide"] and e < fin_excl and fin > debut) else 0
        b["sortie_apres"] = 1 if (s is not None and s >= fin_excl) else 0

    # Dossiers : union des séjours couvrant la période et des dossiers hospitaliers des visites
    dossiers = {}
    ordre = []

    def dossier_de(logiciel, site, num):
        k = f"{logiciel}|{site}|{num}"
        if k not in dossiers:
            dossiers[k] = {"cle": k, "logiciel": logiciel, "site": site, "dossier": num, "lignes": [],
                           "traces": [], "unites_traces": Counter()}
            ordre.append(k)
        return dossiers[k]

    for b in base:
        if b["dans_periode"]:
            dossier_de(b["logiciel"], b["site"], b["dossier"])["lignes"].append(b)
    for v in visites:
        if v["activite"] == HOSPITALISATION:
            d = dossier_de(v["logiciel"], v["site"], v["dossier"])
            d["traces"].append(v)
            d["unites_traces"][v["specialite"]] += 1

    for k in ordre:
        d = dossiers[k]
        lignes = d["lignes"]
        if lignes:
            unites = [l["unite"] for l in lignes]
            d["unite"] = Counter(unites).most_common(1)[0][0]
            d["origine_unite"] = "Hospi prioritaire"
            d["uf_contradictoires"] = 1 if len(set(unites)) > 1 else 0
            d["entree"] = min(l["entree"] for l in lignes)
            ouvertes = [l for l in lignes if l["sortie"] is None]
            d["sortie"] = None if ouvertes else max(l["sortie"] for l in lignes)
            ints = _fusionner([(max(l["entree"], debut), min(l["sortie"] or fin_excl, fin_excl)) for l in lignes])
            d["intervalles"] = ints
            d["date_connue"] = 1
        else:
            d["unite"] = d["unites_traces"].most_common(1)[0][0] if d["unites_traces"] else hors
            d["origine_unite"] = "Visites hospitalières sans entrée"
            d["uf_contradictoires"] = 1 if len(d["unites_traces"]) > 1 else 0
            d["entree"] = d["sortie"] = None
            d["intervalles"] = []
            d["date_connue"] = 0
        e, s = d["entree"], d["sortie"]
        d["entree_periode"] = 1 if (e is not None and debut <= e < fin_excl) else 0
        d["sortie_periode"] = 1 if (s is not None and debut <= s < fin_excl) else 0
        d["en_cours"] = 1 if (e is not None and (s is None or s >= fin_excl)) else 0
        d["par_jour"] = []
        for j in jours:
            tot = 0.0
            for a, b2 in d["intervalles"]:
                tot += max(0.0, (min(b2, j + UN_JOUR) - max(a, j)).total_seconds() / 86400)
            d["par_jour"].append(tot)
        d["jours_connus"] = sum(d["par_jour"]) if d["date_connue"] else None
        d["n_traces"] = len(d["traces"])
        d["n_lignes"] = len(lignes)
        d["jours_traces"] = {t["jour"] for t in d["traces"]}
    liste = [dossiers[k] for k in ordre]
    liste.sort(key=lambda d: (d["logiciel"] != "Evolucare", d["site"], d["dossier"]))
    return base, liste, jours
