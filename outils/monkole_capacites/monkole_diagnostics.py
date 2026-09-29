"""Monkole — Diagnostics et composition des familles (programme hors connexion).

Utilisation : déposer Diagnostic_GPS_AAAAMMJJ-AAAAMMJJ.xlsx et Diagnostic_Evolucare_AAAAMMJJ-AAAAMMJJ.xlsx dans « entrees »,
puis lancer LANCER_DIAGNOSTICS.bat (ou : python monkole_diagnostics.py [dossier_des_exports]).
Le dictionnaire des correspondances est Dictionnaire_Diagnostics.xlsx (modifiable dans Excel).
"""
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

from monkole import (classeur_brut, diag_analyses, diag_assemblage, diag_calculs, diag_dictionnaire, historique,  # noqa: E402
                     lecture)
from monkole.diag_notez import fichier_a_classer  # noqa: E402
from monkole.texte import MOIS  # noqa: E402

VERSION = "1.0"


def nom_periode(debut, fin):
    if debut.month == fin.month:
        return f"{debut.day:02d}-{fin.day:02d}_{MOIS[debut.month - 1].capitalize()}_{fin.year}"
    return f"{debut:%d%m}-{fin:%d%m}_{fin.year}"


def main(argv):
    t0 = time.time()
    entrees = argv[1] if len(argv) > 1 else os.path.join(ICI, "entrees")
    sorties = os.path.join(ICI, "sorties")
    os.makedirs(sorties, exist_ok=True)
    print(f"Monkole — Diagnostics et composition des familles (v{VERSION})")
    chemin_dico = os.path.join(ICI, "Dictionnaire_Diagnostics.xlsx")
    if not os.path.exists(chemin_dico):
        print(f"ERREUR : dictionnaire introuvable : {chemin_dico}")
        return 2
    try:
        dico = diag_dictionnaire.charger(chemin_dico)
    except PermissionError:
        print("ERREUR : fermez Dictionnaire_Diagnostics.xlsx dans Excel puis relancez.")
        return 3
    print(f"Dictionnaire : {len(dico.entrees)} formulations, {len(dico.groupes)} diagnostics regroupés")
    try:
        chemins = diag_calculs.trouver(entrees)
    except diag_calculs.ErreurExport as e:
        print(f"\nERREUR : {e}")
        return 2
    periodes = {lecture.periode_depuis_nom(c) for c in chemins.values()}
    periode = next(iter(periodes)) if len(periodes) == 1 and None not in periodes else None
    if len(periodes) > 1:
        print("ATTENTION : les deux exports n'ont pas la même période dans leur nom ; période lue dans les dates.")
    debut, fin = periode if periode else (None, None)
    R = diag_calculs.calculer(chemins, dico, debut, fin)
    for lg, (nom, n) in R.fichiers.items():
        print(f"   Diagnostics {lg:<10} {n:>6} lignes   {nom}")
    for avis in classeur_brut.AVERTISSEMENTS:
        print(f"   NOTE : {avis}")
    print(f"Période : du {R.debut:%d/%m/%Y} au {R.fin:%d/%m/%Y}")
    per = nom_periode(R.debut, R.fin)
    nom = f"Monkole_Diagnostics_Composition_Familles_{per}.xlsx"
    chemin = os.path.join(sorties, nom)
    R.historique = historique.mettre_a_jour(os.path.join(ICI, "historique", "Historique_diagnostics.xlsx"),
                                            f"{R.debut:%Y%m%d}-{R.fin:%Y%m%d}", diag_analyses.ligne_historique(R))
    try:
        diag_assemblage.construire(R, chemin)
        nom_ac = f"Diagnostics_a_classer_{per}.xlsx"
        a_classer = fichier_a_classer(os.path.join(sorties, nom_ac), R)
    except PermissionError:
        print("\nERREUR : un fichier de sortie est ouvert dans Excel. Fermez-le puis relancez.")
        return 3
    G = R.global_
    print(f"\nDossiers des logiciels : {len(G.dossiers)} | diagnostics comptabilisés : {G.total} | différents : {G.differents} "
          f"| récurrents : {G.recurrents} | hypothèses seules : {len(G.hyp)}")
    for s in ("CSMKL2", "CHME"):
        P = R.sites[s]
        print(f"   {s:<7} dossiers {len(P.dossiers):>5} | diagnostics {P.total:>5} | hypothèses seules {len(P.hyp):>4}")
    ecarts = [c for c in R.controles_notez if abs(c[1]) > 1e-9]
    print("Contrôles de cohérence : " + ("tous à zéro." if not ecarts else "ÉCART : " + ", ".join(c[0] for c in ecarts)))
    if R.nouvelles:
        auto = sum(1 for n in R.nouvelles.values() if n["complet"])
        sugg = sum(1 for n in R.nouvelles.values() if not n["complet"] and n["suggestion"])
        reste = len(R.nouvelles) - auto
        lignes = sum(n["n"] for n in R.nouvelles.values() if not n["complet"])
        print(f"\nNOUVELLES FORMULATIONS : {len(R.nouvelles)} textes absents du dictionnaire.")
        print(f"   {auto} proposés automatiquement (comptés, à valider) ; {reste} à classer ({lignes} lignes hors disque), "
              f"dont {sugg} avec une suggestion pré-remplie.")
        if a_classer:
            print(f"   À compléter : sorties/{nom_ac}  puis copier les lignes dans Dictionnaire_Diagnostics.xlsx et relancer.")
    else:
        print("Toutes les formulations sont connues du dictionnaire.")
    print(f"\nClasseur écrit : {chemin}  ({time.time() - t0:.0f} s)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
