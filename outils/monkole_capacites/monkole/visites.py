"""Classement des visites : site, activité, spécialité / unité, classe et intervenant regroupé."""
import re

from .texte import cle, date_heure, dossier, jour, sans_accents, texte, texte_brut

PRINCIPALE = "Activité principale"
SECONDAIRE = "Activité secondaire"          # affichée « MAISON ROSE »
AMBULATOIRE = "Ambulatoire"
URGENCES = "Urgences"
HOSPITALISATION = "Hospitalisation"

CONSULTATION = "Consultation"
AVANT = "Résultats avant une semaine"
APRES = "Résultats après une semaine"
TRACE = "Trace hospitalière"
CLASSES = [CONSULTATION, AVANT, APRES]

UNITES_CODES = {"chir": "Hospi CHIR", "go": "Hospi GO", "mi": "Hospi MI", "neonat": "Hospi Néonat",
                "ped": "Hospi PED", "urg": "Hospi URG", "sip": "Hospi SIP", "rea": "Hospi REA"}

TITRES = {"DR", "DRE", "PR", "PROF", "MS"}


def unite_hospi(libelle, hors_unites):
    """Unité d'hospitalisation harmonisée à partir d'une UF (GPS) ou d'une UH (Evolucare)."""
    k = cle(libelle)
    if not k:
        return hors_unites
    m = re.fullmatch(r"hospi (chir|go|mi|neonat|ped|urg|sip|rea)", k)
    if m:
        return UNITES_CODES[m.group(1)]
    for motif, code in (("soins intensifs", "sip"), ("reanimation", "rea"), ("neonat", "neonat"),
                        ("chirurg", "chir"), ("gyn", "go"), ("obstet", "go"), ("medecine interne", "mi"),
                        ("pediat", "ped"), ("urgence", "urg")):
        if motif in k:
            return UNITES_CODES[code]
    return hors_unites


def nettoyer_nom(v):
    """Titres, accents, ponctuation et espaces harmonisés ; None si absent."""
    t = texte(v)
    if t is None:
        return None
    mots = re.sub(r"[^A-Za-z0-9]+", " ", sans_accents(t)).upper().split()
    while mots and mots[0] in TITRES:
        mots = mots[1:]
    return " ".join(mots) or None


def regrouper_noms(noms, alias_confirmes):
    """Rapprochement : alias confirmés, puis noms dont tous les mots (au moins deux) se retrouvent
    dans un seul autre nom plus complet. Renvoie {nom nettoyé: nom regroupé} et la liste des rapprochements."""
    noms = sorted(set(n for n in noms if n))
    groupe = {}
    auto = []
    ensembles = {n: set(n.split()) for n in noms}
    for n in noms:
        if n in alias_confirmes:
            groupe[n] = alias_confirmes[n]
            continue
        mots = ensembles[n]
        if len(mots) < 2:
            continue
        candidats = [m for m in noms if m != n and mots < ensembles[m]]
        if len(candidats) == 1:
            groupe[n] = candidats[0]
            auto.append((n, candidats[0]))
    return groupe, auto


def classer_visites(donnees, ref):
    uf_spec = {cle(k): v for k, v in ref["UF_SPECIALITE"].items()}
    maison_rose = {cle(u) for u in ref["MAISON_ROSE_UF"]}
    hors_unites = ref["HORS_UNITES"]
    visites = []
    inconnues = {}
    for logiciel, lignes in (("GPS", donnees["visites_gps"]), ("Evolucare", donnees["visites_evo"])):
        for r in lignes:
            nature, motif, uf = texte(r["Nature"]), texte(r["Motif"]), texte(r["UF"])
            site = (texte(r["Etablissement"]) or "Site non renseigné").upper()
            if site not in ("CSMKL2", "CHME"):
                site = texte(r["Etablissement"]) or "Site non renseigné"
            champs = " | ".join(cle(x) for x in (uf, motif, nature) if x)
            if site == "CSMKL2":
                if cle(uf) in maison_rose:
                    activite = SECONDAIRE
                    spec = uf_spec.get(cle(uf), uf)
                else:
                    activite = PRINCIPALE
                    spec = "Médecine générale / famille"
            elif "hospi" in champs:
                activite = HOSPITALISATION
                spec = unite_hospi(uf, hors_unites)
            elif "urgence" in champs:
                activite = URGENCES
                spec = "Urgences générales"
            else:
                activite = AMBULATOIRE
                if uf is None:
                    spec = "UF non renseignée"
                elif cle(uf) in uf_spec:
                    spec = uf_spec[cle(uf)]
                else:
                    spec = uf
                    inconnues[uf] = inconnues.get(uf, 0) + 1
            if motif is not None:
                champ, source = "Motif", motif
            else:
                champ, source = "Nature (Motif absent)", nature
            if activite == HOSPITALISATION:
                classe = TRACE
            else:
                k = cle(source)
                if "resultat" in k and "apres" in k:
                    classe = APRES
                elif "resultat" in k and "avant" in k:
                    classe = AVANT
                else:
                    classe = CONSULTATION
            num = dossier(r["Num_Dossier"])
            d = date_heure(r["Date"])
            visites.append({
                "logiciel": logiciel, "ligne": r["_ligne"], "date": d, "jour": jour(d), "site": site,
                "dossier": num, "cle": f"{logiciel}|{site}|{num}",
                "medecin_source": texte_brut(r["Medecin"]), "medecin_nettoye": nettoyer_nom(r["Medecin"]),
                "nature": nature, "motif": motif, "uf": uf,
                "activite": activite, "specialite": spec, "classe": classe, "champ": champ,
            })
    groupe, auto = regrouper_noms([v["medecin_nettoye"] for v in visites], ref["ALIAS_MEDECINS"])
    for v in visites:
        n = v["medecin_nettoye"]
        v["medecin"] = groupe.get(n, n)
    return visites, auto, inconnues
