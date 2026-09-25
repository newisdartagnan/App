"""Résumé texte court (à coller dans Claude pour faire rédiger un commentaire, sans envoyer les exports)."""
from . import visites as V
from .calculs import indicateurs
from .texte import fr_pct


def ecrire_resume(R, chemin, nom_classeur):
    cs = indicateurs(R, "CSMKL2", V.PRINCIPALE)
    mr = indicateurs(R, "CSMKL2", V.SECONDAIRE)
    amb = indicateurs(R, "CHME", V.AMBULATOIRE)
    urg = indicateurs(R, "CHME", V.URGENCES)
    t = R.unites_total
    pct = lambda x: fr_pct(x) if x is not None else "N/D"
    jours_plus = sum(1 for l in R.jours_act[("CSMKL2", V.PRINCIPALE)] if l["cab"] > R.cabinets_csmkl2)
    lignes = [
        f"MONKOLE — ACTIVITÉS ET CAPACITÉS — du {R.debut:%d/%m/%Y} au {R.fin:%d/%m/%Y} ({R.ndays} jours)",
        f"Classeur : {nom_classeur}",
        "",
        f"Repère : {R.cap_jour} visites par intervenant-jour compté (à partir de {R.seuil} visites dans la journée).",
        "",
        f"CSMKL2 activité principale : {cs['total']} visites (GPS {cs['gps']}, Evolucare {cs['evo']}) ; capacité {cs['cap']} ; "
        f"utilisation {pct(cs['util'])} ; {cs['depass']} journées-intervenants > {R.cap_jour} (pic individuel {cs['pic_medecin']}) ; "
        f"{jours_plus} jour(s) avec plus de {R.cabinets_csmkl2} cabinets ; pic du site {cs['pic_jour']} visites le "
        f"{cs['date_pic']:%d/%m}.",
        f"CSMKL2 MAISON ROSE : {mr['total']} visites, hors capacité principale.",
        f"CHME ambulatoire : {amb['total']} visites ; capacité {amb['cap']} ; utilisation {pct(amb['util'])} ; "
        f"{amb['depass']} journées-intervenants > {R.cap_jour} (pic individuel {amb['pic_medecin']}).",
        f"CHME urgences : {urg['total']} visites dont {urg['sans']} sans médecin renseigné.",
        f"Total hors suivi d'hospitalisation : {cs['total'] + mr['total'] + amb['total'] + urg['total']} visites "
        f"(traces de suivi hospitalier séparées : {sum(1 for v in R.visites if v['activite'] == V.HOSPITALISATION)}).",
        "",
        f"Actes GPS (Date_V) : {sum(1 for a in R.actes if a['logiciel'] == 'GPS')} ; prestations Evolucare (DATEHEURE, série "
        f"séparée) : {sum(1 for a in R.actes if a['logiciel'] != 'GPS')} ; produits Evolucare séparés : {len(R.produits)}.",
        f"Hospitalisation CHME : {t['dos_gps']} dossiers GPS / {t['dos_evo']} Evolucare ; {t['sans_gps'] + t['sans_evo']} sans date "
        f"d'entrée ; occupation GPS {pct(t['occ_gps'])}, Evolucare {pct(t['occ_evo'])} (non additionnables).",
        "",
        "Contrôles quantitatifs (écart attendu 0) : "
        + ("tous à zéro." if all(abs(a - b) < 1e-6 for _, a, b in getattr(R, "controles", [])) else "ÉCART À VÉRIFIER dans Notez bien."),
    ]
    with open(chemin, "w", encoding="utf-8") as f:
        f.write("\n".join(lignes) + "\n")
    return "\n".join(lignes)
