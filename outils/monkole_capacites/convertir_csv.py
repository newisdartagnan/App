"""Monkole — conversion des extractions .csv (Evolucare, GPS) en .xlsx, sans perte.

Utilisation : déposer les extractions .csv brutes (sans les renommer) dans « entrees_csv », puis lancer
LANCER_CONVERSION_CSV.bat (ou : python convertir_csv.py [dossier_csv] [dossier_xlsx]).
Les .xlsx sont écrits dans « entrees » sous les noms attendus (Visite_Evolucare_…, ActesND_Evolucare_…, Hospi_Evolucare_…,
Diagnostic_Evolucare_… : les trois Actes_diagnostic_web… regroupés), prêts pour LANCER_MONKOLE.bat et LANCER_DIAGNOSTICS.bat.
Chaque CSV converti sans écart est rangé dans « entrees_csv/convertis ». Compte rendu : entrees_csv/conversion_rapport.txt.
"""
import datetime as dt
import os
import sys

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ICI)

try:
    import openpyxl  # noqa: F401  (vérifie seulement la présence du module)
except ImportError:
    print("Le module openpyxl manque. Installez-le avec :  py -m pip install openpyxl")
    sys.exit(1)

from monkole import referentiel_excel  # noqa: E402
from monkole.conversion_csv import convertir_dossier  # noqa: E402


def main(argv):
    dossier_csv = argv[1] if len(argv) > 1 else os.path.join(ICI, "entrees_csv")
    dossier_xlsx = argv[2] if len(argv) > 2 else os.path.join(ICI, "entrees")
    os.makedirs(dossier_csv, exist_ok=True)
    print("Monkole — conversion CSV -> XLSX sans perte")
    print(f"Dossier des CSV : {dossier_csv}")
    try:
        ref = referentiel_excel.charger(os.path.join(ICI, "Referentiel_Monkole.xlsx"))
        comptes = convertir_dossier(dossier_csv, dossier_xlsx, os.path.join(dossier_csv, "convertis"), ref["COLONNES_CSV"])
    except PermissionError as e:
        print(f"\nERREUR : fichier ouvert dans Excel ? Fermez-le puis relancez. ({e.filename})")
        return 3
    if not comptes:
        print("\nAucun fichier .csv dans le dossier. Déposez les extractions puis relancez.")
        return 0
    lignes = [f"Conversion du {dt.datetime.now():%d/%m/%Y %H:%M}", ""]
    for cr in comptes:
        if cr["ecarts"]:
            etat = f"ÉCART : {cr['ecarts']} cellule(s) différente(s) — CSV laissé en place"
        elif cr.get("manquantes"):
            etat = "converti sans perte, mais colonnes attendues absentes — CSV laissé en place"
        else:
            etat = "OK, aucune perte"
        lignes += [f"{cr['fichier']} -> {os.path.basename(cr.get('cible', ''))} : {etat}",
                   f"   {cr['lignes']} lignes, {cr['colonnes']} colonnes ; encodage {cr['encodage']}, séparateur {cr['separateur']} ; "
                   f"{cr['dates']} dates, {cr['nombres']} nombres, {cr['textes']} textes"]
        lignes += [f"   ATTENTION : {a}" for a in cr["avertissements"]]
    ok = sum(1 for cr in comptes if cr["ecarts"] == 0 and not cr.get("manquantes"))
    lignes += ["", f"{ok} fichier(s) sur {len(comptes)} prêts dans : {dossier_xlsx}",
               "Ce rapport ne contient aucune donnée de patient (noms de fichiers et de colonnes seulement) : il peut être "
               "envoyé tel quel pour vérification."]
    texte = "\n".join(lignes)
    print("\n" + texte)
    with open(os.path.join(dossier_csv, "conversion_rapport.txt"), "w", encoding="utf-8") as f:
        f.write(texte + "\n")
    return 0 if ok == len(comptes) else 4


if __name__ == "__main__":
    sys.exit(main(sys.argv))
