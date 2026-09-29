"""Horaires des rendez-vous (RDV_Horaire_*.xlsx, facultatif) : capacité horaire des médecins.

Le fichier liste les créneaux de rendez-vous (Date + heure, TYPE, Médecin). Pour chaque médecin :
durée d'une consultation = écart médian entre deux créneaux d'une même journée ; plage = médiane (dernier - premier
créneau + durée) ; créneaux par jour = médiane du nombre d'heures de rendez-vous distinctes dans une journée.
Les « créneaux par jour » deviennent la capacité du médecin (au lieu du repère de 24 visites).
Le résultat est conservé dans Referentiel_Monkole.xlsx (feuille « Capacité horaire ») et réutilisé sans le fichier.
"""
import datetime as dt
import os
import re
import statistics as st
from collections import Counter, defaultdict

from .classeur_brut import lignes_classeur
from .texte import cle
from .visites import nettoyer_nom

MOTIF = re.compile(r"^rdv", re.I)


def trouver(dossier):
    if not os.path.isdir(dossier):
        return None
    for nom in sorted(os.listdir(dossier)):
        base = re.sub(r"^[0-9a-f]{8}-", "", nom, flags=re.I)
        if nom.lower().endswith(".xlsx") and not nom.startswith("~$") and MOTIF.search(base):
            return os.path.join(dossier, nom)
    return None


def lire(chemin):
    """Renvoie la liste des créneaux {date, type, medecin (nettoyé), medecin_source}."""
    lignes = lignes_classeur(chemin)
    _, entete = lignes[0]
    cles = [cle(h) if h is not None else "" for h in entete]
    try:
        i_d, i_t, i_m = cles.index(cle("Date")), cles.index(cle("TYPE")), cles.index(cle("Médecin"))
    except ValueError:
        return []
    out = []
    for _, row in lignes[1:]:
        if not row or len(row) <= max(i_d, i_t, i_m):
            continue
        d, t, m = row[i_d], row[i_t], row[i_m]
        if not isinstance(d, dt.datetime) or not m:
            continue
        out.append({"date": d, "type": str(t).strip() if t else "", "medecin": nettoyer_nom(m), "medecin_source": str(m).strip()})
    return out


def capacites(creneaux):
    """Tableau par médecin : [médecin, type principal, durée (min), plage (h), créneaux par jour, jours avec RDV]."""
    par_jour = defaultdict(set)
    types = defaultdict(Counter)
    for c in creneaux:
        par_jour[(c["medecin"], c["date"].date())].add(c["date"])
        types[c["medecin"]][c["type"]] += 1
    par_med = defaultdict(lambda: {"ecarts": [], "plages": [], "n": []})
    for (m, _), heures in par_jour.items():
        h = sorted(heures)
        x = par_med[m]
        x["ecarts"] += [(b - a).total_seconds() / 60 for a, b in zip(h, h[1:])]
        x["plages"].append((h[-1] - h[0]).total_seconds() / 60)
        x["n"].append(len(h))
    tableau = []
    for m, x in sorted(par_med.items()):
        duree = st.median(x["ecarts"]) if x["ecarts"] else None
        plage = (st.median(x["plages"]) + (duree or 0)) / 60
        tableau.append([m, types[m].most_common(1)[0][0], round(duree) if duree else None, round(plage, 1),
                        int(round(st.median(x["n"]))), len(x["n"])])
    return tableau


def associer(noms_rdv, noms_visites, alias):
    """Nom RDV nettoyé -> nom regroupé des visites (exact, alias, ou tous les mots contenus dans un seul nom)."""
    ens = {n: set(n.split()) for n in noms_visites}
    out = {}
    for n in noms_rdv:
        n2 = alias.get(n, n)
        if n2 in ens:
            out[n] = n2
            continue
        mots = set(n2.split())
        cand = [v for v, e in ens.items() if mots <= e] or [v for v, e in ens.items() if len(e) >= 2 and e <= mots]
        if len(cand) == 1:
            out[n] = cand[0]
    return out
