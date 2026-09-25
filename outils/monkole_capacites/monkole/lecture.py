"""Lecture des six exports GPS / Evolucare (fichiers .xlsx, première feuille)."""
import datetime as dt
import os
import re

from .classeur_brut import lignes_classeur
from .texte import cle

# Nom logique -> (motif du nom de fichier, colonnes obligatoires)
EXPORTS = {
    "visites_gps": (r"^visites_", ["Date", "Num_Dossier", "Nature", "Motif", "UF", "Medecin", "Etablissement"]),
    "visites_evo": (r"^visite_evolucare", ["Date", "Num_Dossier", "Nature", "Motif", "UF", "Medecin", "Etablissement"]),
    "actes_gps": (r"^actesnd_(?!evolucare)", ["Date_V", "Num_Dossier", "Spécialité", "Sous spécialité", "Acte", "Site"]),
    "actes_evo": (r"^actesnd_evolucare", ["DATEHEURE", "Categorie_acte", "Code_acte", "Nom_acte", "Quantité", "Num_dossier", "Etablissement"]),
    "hospi_gps": (r"^hospi_(?!evolucare)", ["Date Entrée", "Date Sortie", "Num_Dossier", "UF", "Site"]),
    "hospi_evo": (r"^hospi_evolucare", ["Num_Dossier", "Date_Entree", "Date_Sortie", "UH"]),
}

LIBELLES = {
    "visites_gps": "Visites GPS",
    "visites_evo": "Visites Evolucare",
    "actes_gps": "Actes GPS (ActesND)",
    "actes_evo": "Actes Evolucare",
    "hospi_gps": "Hospitalisations GPS",
    "hospi_evo": "Hospitalisations Evolucare",
}


class ErreurExport(Exception):
    pass


def trouver_exports(dossier):
    """Repère les six exports dans le dossier d'après leur nom."""
    trouves = {}
    for nom in sorted(os.listdir(dossier)):
        if not nom.lower().endswith(".xlsx") or nom.startswith("~$"):
            continue
        # Les fichiers téléchargés peuvent être préfixés (ex. « a9db9673-Visite_... »)
        base = re.sub(r"^[0-9a-f]{8}-", "", nom, flags=re.I).lower()
        for cle_export, (motif, _) in EXPORTS.items():
            if re.search(motif, base):
                if cle_export in trouves:
                    raise ErreurExport(
                        f"Deux fichiers correspondent à « {LIBELLES[cle_export]} » : "
                        f"{os.path.basename(trouves[cle_export])} et {nom}. Laissez un seul fichier par export.")
                trouves[cle_export] = os.path.join(dossier, nom)
    manquants = [LIBELLES[k] for k in EXPORTS if k not in trouves]
    if manquants:
        raise ErreurExport("Export(s) introuvable(s) dans « entrees » : " + ", ".join(manquants))
    return trouves


def periode_depuis_nom(chemin):
    m = re.search(r"(\d{8})-(\d{8})", os.path.basename(chemin))
    if not m:
        return None
    debut = dt.datetime.strptime(m.group(1), "%Y%m%d")
    fin = dt.datetime.strptime(m.group(2), "%Y%m%d")
    return debut, fin


def lire_export(chemin, colonnes):
    """Renvoie la liste des lignes ; chaque ligne est un dict nom_colonne -> valeur + '_ligne' (n° de ligne Excel)."""
    lignes_brutes = lignes_classeur(chemin)
    it = iter(lignes_brutes)
    try:
        _, entete = next(it)
    except StopIteration:
        raise ErreurExport(f"{os.path.basename(chemin)} est vide.")
    entete = list(entete)
    index = {}
    cles = [cle(h) if h is not None else "" for h in entete]
    for col in colonnes:
        k = cle(col)
        if k not in cles:
            raise ErreurExport(f"Colonne « {col} » absente de {os.path.basename(chemin)}.")
        index[col] = cles.index(k)
    extras = {h: i for i, h in enumerate(entete) if h is not None and h not in index}
    lignes = []
    for n, row in it:
        if row is None or all(v is None for v in row):
            continue
        d = {"_ligne": n}
        for col, i in index.items():
            d[col] = row[i] if i < len(row) else None
        for col, i in extras.items():
            d.setdefault(col, row[i] if i < len(row) else None)
        lignes.append(d)
    return lignes


def lire_tout(dossier):
    chemins = trouver_exports(dossier)
    donnees = {}
    for k, chemin in chemins.items():
        donnees[k] = lire_export(chemin, EXPORTS[k][1])
    return chemins, donnees
