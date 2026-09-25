"""Actes : GPS sur Date_V, Evolucare sur DATEHEURE (série complémentaire), produits séparés."""
from .texte import cle, date_heure, dossier, jour, texte, texte_brut

GPS_SERIE = "GPS / Date_V"
EVO_SERIE = "Evolucare / DATEHEURE (hors série Date_V)"
PRODUITS = "Produits séparés"


def classer_actes(donnees, ref, profil_de, premiere_visite):
    """profil_de(cle_dossier) -> profil ; premiere_visite(cle) -> date de visite retrouvée (Evo, contrôle)."""
    categories = {cle(k): v for k, v in ref["EVO_CATEGORIES"].items()}
    produits = {cle(p) for p in ref["EVO_PRODUITS"]}
    actes = []
    inconnues = {}
    for r in donnees["actes_gps"]:
        site = texte(r["Site"]) or "Site non renseigné"
        num = dossier(r["Num_Dossier"])
        d = date_heure(r["Date_V"])
        spec, sub = texte_brut(r["Spécialité"]) or "Catégorie à préciser", texte_brut(r["Sous spécialité"]) or "Catégorie absente"
        actes.append({"logiciel": "GPS", "ligne": r["_ligne"], "site": site, "dossier": num,
                      "cle": f"GPS|{site}|{num}", "date_v": d, "dateheure": None, "date_visite": None,
                      "date": d, "jour": jour(d), "categorie": f"{spec} / {sub}", "specialite": spec,
                      "sous_specialite": sub, "libelle": texte_brut(r["Acte"]) or "(acte sans libellé)",
                      "code": None, "quantite": None, "produit": 0, "situation": GPS_SERIE,
                      "_brut": tuple(r.get(k) for k in sorted(k for k in r if k != "_ligne"))})
    for r in donnees["actes_evo"]:
        site = texte(r["Etablissement"]) or "Site non renseigné"
        num = dossier(r["Num_dossier"])
        d = date_heure(r["DATEHEURE"])
        cat = texte(r["Categorie_acte"])
        produit = 1 if cle(cat) in produits else 0
        if cat is None:
            spec, sub = "Catégorie à préciser", "Catégorie absente"
        elif produit:
            spec, sub = "Produits (hors actes)", cat
        elif cle(cat) in categories:
            spec, sub = categories[cle(cat)]
        else:
            morceaux = cat.split("-", 1)
            spec = morceaux[0].strip() or "Catégorie à préciser"
            sub = morceaux[1].strip() if len(morceaux) > 1 else cat
            inconnues[cat] = inconnues.get(cat, 0) + 1
        q = r["Quantité"]
        try:
            q = float(q) if q is not None else None
        except (TypeError, ValueError):
            q = None
        k = f"Evolucare|{site}|{num}"
        actes.append({"logiciel": "Evolucare", "ligne": r["_ligne"], "site": site, "dossier": num, "cle": k,
                      "date_v": None, "dateheure": d, "date_visite": premiere_visite(k), "date": d, "jour": jour(d),
                      "categorie": cat, "specialite": spec, "sous_specialite": sub,
                      "libelle": texte_brut(r["Nom_acte"]) or "(acte sans libellé)", "code": texte(r["Code_acte"]),
                      "quantite": q, "produit": produit, "situation": PRODUITS if produit else EVO_SERIE,
                      "_brut": tuple(r.get(k2) for k2 in sorted(k2 for k2 in r if k2 != "_ligne"))})
    vus = set()
    for a in actes:
        a["retenue"] = 0 if a["produit"] else 1
        a["profil"] = profil_de(a["cle"])
        lib = cle(a["libelle"])
        a["consultation"] = 1 if (not a["produit"] and ("consult" in lib or "resultat" in lib)) else 0
        empreinte = (a["logiciel"], a["cle"], a["_brut"])
        a["repetition"] = 1 if empreinte in vus else 0
        vus.add(empreinte)
        del a["_brut"]
    return actes, inconnues
