"""Centrage commun à tous les classeurs (activités et capacités, CSMKL2 et CHME, diagnostics) : noms de colonnes et valeurs
chiffrées centrés ; les libellés (noms, jours, spécialités, notes) restent alignés à gauche."""
import datetime as dt
import re
from copy import copy

LARGEUR_MAX = 4          # au-delà (fusion plus large), il s'agit d'un titre ou d'une note : alignement inchangé
TAILLE_MAX = 14          # grands chiffres des indicateurs : déjà mis en forme
CHIFFRE = re.compile(r"^[–—-]?$|^N/D$|^[+-]?[\d\s  .,]+\s?%?$")


def _largeurs(ws):
    """{(ligne, colonne) du coin haut-gauche: nombre de colonnes} des cellules fusionnées ; cellules masquées par une fusion."""
    larg, masquees = {}, set()
    for m in ws.merged_cells.ranges:
        larg[(m.min_row, m.min_col)] = m.max_col - m.min_col + 1
        for r in range(m.min_row, m.max_row + 1):
            for c in range(m.min_col, m.max_col + 1):
                if (r, c) != (m.min_row, m.min_col):
                    masquees.add((r, c))
    return larg, masquees


def _est_chiffre(v):
    if isinstance(v, bool):
        return False
    if isinstance(v, (int, float, dt.date, dt.datetime, dt.time)):
        return True
    return isinstance(v, str) and bool(CHIFFRE.match(v.strip()))


def _est_entete(cel):
    f = cel.fill
    return (isinstance(cel.value, str) and cel.font is not None and cel.font.b
            and f is not None and f.fill_type == "solid")


def _centrer(cel):
    if cel.alignment.horizontal == "center":
        return
    a = copy(cel.alignment)
    a.horizontal = "center"
    cel.alignment = a


def centrer_feuille(ws):
    larg, masquees = _largeurs(ws)
    for ligne in ws.iter_rows():
        entetes, etroits = [], 0
        for cel in ligne:
            if cel.value is None or (cel.row, cel.column) in masquees:
                continue
            large = larg.get((cel.row, cel.column), 1) > LARGEUR_MAX
            if _est_chiffre(cel.value):
                if not large and not (cel.font is not None and cel.font.sz and cel.font.sz > TAILLE_MAX):
                    _centrer(cel)
            elif _est_entete(cel):
                entetes.append(cel)
                etroits += not large
        if etroits >= 3:                 # ligne de noms de colonnes (y compris la première, souvent plus large)
            for cel in entetes:
                _centrer(cel)


def centrer(wb):
    for ws in wb.worksheets:
        if ws.sheet_state == "visible":
            centrer_feuille(ws)
