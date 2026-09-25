"""Petites fonctions de normalisation de texte, de dates et d'identifiants."""
import datetime as dt
import re
import unicodedata


def sans_accents(s):
    return "".join(c for c in unicodedata.normalize("NFKD", str(s)) if not unicodedata.combining(c))


def cle(s):
    """Clé de comparaison : sans accents, minuscules, espaces simples."""
    if s is None:
        return ""
    return re.sub(r"\s+", " ", sans_accents(s)).strip().lower()


def vide(v):
    return v is None or (isinstance(v, str) and v.strip() in ("", "NULL", "null"))


def texte(v):
    """Texte nettoyé (None si vide / NULL)."""
    if vide(v):
        return None
    return re.sub(r"\s+", " ", str(v)).strip()


def texte_brut(v):
    """Texte conservé tel quel, sauf espaces de fin (None si vide / NULL)."""
    if vide(v):
        return None
    return str(v).rstrip()


def dossier(v):
    """Num_Dossier conservé comme texte (sans .0 parasite)."""
    if vide(v):
        return None
    if isinstance(v, float) and v.is_integer():
        v = int(v)
    return str(v).strip()


def date_heure(v):
    if v is None:
        return None
    if isinstance(v, dt.datetime):
        return v
    if isinstance(v, dt.date):
        return dt.datetime(v.year, v.month, v.day)
    if isinstance(v, str):
        v = v.strip()
        for fmt in ("%d/%m/%Y %H:%M:%S", "%d/%m/%Y %H:%M", "%d/%m/%Y", "%Y-%m-%d %H:%M:%S", "%Y-%m-%d"):
            try:
                return dt.datetime.strptime(v, fmt)
            except ValueError:
                pass
    return None


def jour(v):
    d = date_heure(v)
    return dt.datetime(d.year, d.month, d.day) if d else None


JOURS = ["Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"]
JOURS_COURTS = ["L", "Ma", "Me", "J", "V", "S", "D"]
MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août",
        "septembre", "octobre", "novembre", "décembre"]


def nom_jour(d):
    return JOURS[d.weekday()]


def fr_nombre(n):
    """1 247 (espace fine insécable comme dans le modèle)."""
    return f"{int(round(n)):,}".replace(",", " ")


def fr_nombre_espace(n):
    return f"{int(round(n)):,}".replace(",", " ")


def fr_pct(x, dec=1):
    return f"{x * 100:.{dec}f}".replace(".", ",") + " %"
