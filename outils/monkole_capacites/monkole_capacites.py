"""Monkole — Activités et capacités (programme hors connexion).

Utilisation : déposer les six exports GPS / Evolucare dans le dossier « entrees », puis lancer
LANCER_MONKOLE.bat (ou : python monkole_capacites.py [dossier_des_exports]).
Le classeur est écrit dans le dossier « sorties ».
"""
import datetime as dt
import os
import sys
import time

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ICI)

try:
    import openpyxl  # noqa: F401  (vérifie seulement la présence du module)
except ImportError:
    print("Le module openpyxl manque. Installez-le avec :  py -m pip install openpyxl")
    sys.exit(1)

from monkole import calculs, classeur, lecture, referentiel_excel  # noqa: E402
from monkole.resume import ecrire_resume  # noqa: E402
from monkole.texte import MOIS  # noqa: E402

VERSION = "1.0"


def periode(chemins, donnees):
    """Période lue dans les noms de fichiers (AAAAMMJJ-AAAAMMJJ), sinon dans les dates des visites."""
    trouvees = {k: lecture.periode_depuis_nom(c) for k, c in chemins.items()}
    valeurs = {v for v in trouvees.values() if v}
    if len(valeurs) > 1:
        print("ATTENTION : les exports n'ont pas tous la même période dans leur nom :")
        for k, v in trouvees.items():
            print(f"   {lecture.LIBELLES[k]} : {v[0]:%d/%m/%Y} - {v[1]:%d/%m/%Y}" if v else f"   {lecture.LIBELLES[k]} : non lue")
        print("   La période des visites GPS est retenue.")
    p = trouvees.get("visites_gps")
    if p:
        return p
    dates = [r["Date"] for k in ("visites_gps", "visites_evo") for r in donnees[k] if isinstance(r["Date"], dt.datetime)]
    d0, d1 = min(dates), max(dates)
    return dt.datetime(d0.year, d0.month, d0.day), dt.datetime(d1.year, d1.month, d1.day)


def nom_sortie(debut, fin):
    if debut.month == fin.month:
        per = f"{debut.day:02d}-{fin.day:02d}_{MOIS[debut.month - 1].capitalize()}_{fin.year}"
    else:
        per = f"{debut:%d%m}-{fin:%d%m}_{fin.year}"
    return f"Monkole_Activites_Capacites_{per}.xlsx"


def main(argv):
    t0 = time.time()
    entrees = argv[1] if len(argv) > 1 else os.path.join(ICI, "entrees")
    sorties = os.path.join(ICI, "sorties")
    os.makedirs(sorties, exist_ok=True)
    print(f"Monkole — Activités et capacités (v{VERSION})")
    print(f"Lecture des exports dans : {entrees}")
    ref = referentiel_excel.charger(os.path.join(ICI, "Referentiel_Monkole.xlsx"))
    try:
        chemins, donnees = lecture.lire_tout(entrees)
    except lecture.ErreurExport as e:
        print(f"\nERREUR : {e}")
        print("Les six fichiers attendus : Visites_*, Visite_Evolucare_*, ActesND_*, ActesND_Evolucare_*, Hospi_*, Hospi_Evolucare_*")
        return 2
    for k, c in chemins.items():
        print(f"   {lecture.LIBELLES[k]:<28} {len(donnees[k]):>7} lignes   {os.path.basename(c)}")
    debut, fin = periode(chemins, donnees)
    print(f"Période : du {debut:%d/%m/%Y} au {fin:%d/%m/%Y}")
    R = calculs.calculer(donnees, ref, debut, fin)
    R.chemins = chemins
    nom = nom_sortie(debut, fin)
    chemin = os.path.join(sorties, nom)
    try:
        classeur.construire(R, chemin)
    except PermissionError:
        print(f"\nERREUR : impossible d'écrire {nom}. Fermez ce fichier dans Excel puis relancez.")
        return 3
    texte = ecrire_resume(R, os.path.join(sorties, nom.replace(".xlsx", "_resume.txt")), nom)
    print("\n" + texte)
    ecarts = [c for c in R.controles if abs(c[1] - c[2]) > 1e-6]
    a_valider = len(R.uf_inconnues) + len(R.categories_inconnues)
    if ecarts:
        print("ATTENTION : contrôles en écart :", ", ".join(c[0] for c in ecarts))
    if a_valider:
        print(f"À VALIDER : {a_valider} nouveau(x) libellé(s) listé(s) dans « Notez bien » (compléter Referentiel_Monkole.xlsx).")
    if R.rapprochements:
        print(f"Noms rapprochés automatiquement : {len(R.rapprochements)} (liste dans « Notez bien »).")
    print(f"\nClasseur écrit : {chemin}  ({time.time() - t0:.0f} s)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
