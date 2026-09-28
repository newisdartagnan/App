"""Diagnostics : Notez bien, Calculs, bases masquées et liste des formulations à classer."""
import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.worksheet.datavalidation import DataValidation

from . import diag_dictionnaire as DD
from .diag_calculs import SANS_SITE, SITES
from .diag_classeur import (AL_F, AL_T, BLANC, C_CLAIR, C_NAVY, C_TXT, CLAIR, GRIS, NAVY, NB, TXT, bloc, entete_commun,
                            hauteurs, mise_en_page, periode_txt, zebre)
from .styles import ecrire

AP = dict(police="Aptos")


def _nombre(n):
    return f"{n:,}".replace(",", " ")


# ----------------------------------------------------------------------------------------------
def calculs(wb, R):
    ws = wb.create_sheet("Calculs")
    ws.sheet_state = "hidden"
    ent = ["Site", "Logiciel", "Lignes source", "Dossiers source", "Mentions decrites", "Dossiers avec clinique", "Hypotheses seules",
           "Hypotheses toutes", "Lignes contexte", "Lignes a clarifier", "Lignes non exploitables",
           "Dossiers uniquement non exploitables", "GPS longueur 50", "Groupes decrits", "Groupes recurrents", "Mentions quotidiennes"]
    ws.append(ent)
    for (s, lg), P in R.tableau.items():
        ws.append([s if s != SANS_SITE else "Site non renseigne", lg, len(P.sources), len(P.dossiers), P.total,
                   len(P.dossiers_cliniques), len(P.hyp), P.hyp_toutes, P.lignes_contexte, P.lignes_a_clarifier,
                   P.lignes_non_exploitables, P.dossiers_non_exploitables, P.lignes_gps_50, P.differents, P.recurrents, P.somme_jours])
    G = R.global_
    ctrl = [("Controle", "Ecart attendu zero"),
            ("Diagnostics = somme sites", G.total - sum(R.sites[s].total for s in SITES + [SANS_SITE])),
            ("Dossiers = somme sites", len(G.dossiers) - sum(len(R.sites[s].dossiers) for s in SITES + [SANS_SITE])),
            ("Lignes = GPS + Evolucare", len(R.sources) - sum(p.lignes for p in R.par_logiciel.values())),
            ("Disque = diagnostics", sum(v["total"] for v in G.familles.values()) - G.total),
            ("Parts disque = 100 %", round(sum(v["total"] for v in G.familles.values()) / G.total - 1, 12) if G.total else 0),
            ("Repetitions entre jours", G.somme_jours - G.total)]
    R.controles_diag = ctrl[1:]
    for i, (a, b) in enumerate(ctrl):
        ws.cell(row=20 + i, column=1, value=a)
        ws.cell(row=20 + i, column=2, value=b)
    ent = ["Date", "GPS", "Evolucare", "Total", "GPS CSMKL2", "Evo CSMKL2", "Total CSMKL2", "GPS CHME", "Evo CHME", "Total CHME",
           "GPS sans site", "Evo sans site", "Total sans site", "Jour affiche"]
    for c, t in enumerate(ent, start=1):
        ws.cell(row=29, column=c, value=t)
    R.ligne_quotidien = 30
    for i, l in enumerate(R.quotidien):
        r = 30 + i
        vals = [l["jour"]]
        for site in (None, "CSMKL2", "CHME", SANS_SITE):
            g, e = l[(site, "GPS")], l[(site, "Evolucare")]
            vals += [g, e, g + e]
        vals.append(l["jour"].strftime("%d/%m"))
        for c, v in enumerate(vals, start=1):
            ws.cell(row=r, column=c, value=v)
        ws.cell(row=r, column=1).number_format = "dd/mm/yyyy"
    return ws


# ----------------------------------------------------------------------------------------------
def bases(wb, R):
    ws = wb.create_sheet("Base sources")
    ws.sheet_state = "hidden"
    ws.append(["ID ligne", "Date", "Logiciel", "Site", "Num_Dossier", "Cle dossier-source", "Ligne fichier",
               "Libelle original integral", "ID dictionnaire", "Contient clinique", "Contient hypothese", "A clarifier",
               "Non exploitable", "Contexte", "Texte GPS longueur 50", "Composantes proposees", "Nature source", "Fichier",
               "Origine du rattachement"])
    for s in R.sources:
        st = {x for _, x in s["lignes"]}
        ws.append([s["id"], s["jour"], s["logiciel"], s["site"], s["dossier"], s["cle"], s["ligne"], s["texte"] or None,
                   s["id_dico"], int(DD.DECRIT in st), int(DD.HYPOTHESE in st), int("À clarifier" in st), int("Non exploitable" in st),
                   int(bool(st & {"Contexte / antécédent", "Explicitement écarté"})),
                   int(s["logiciel"] == "GPS" and len(s["texte"]) == 50), len(s["lignes"]), s["nature"], s["fichier"], s["origine"]])
    ws.freeze_panes = "A2"
    ws.auto_filter.ref = f"A1:S{ws.max_row}"
    ws2 = wb.create_sheet("Base mentions")
    ws2.sheet_state = "hidden"
    ws2.append(["ID ligne source", "Date", "Logiciel", "Site", "Num_Dossier", "Cle dossier-source", "ID dictionnaire", "Code groupe",
                "Famille", "Groupe propose", "Type", "Statut", "Libelle original integral", "Cle dossier-groupe"])
    for m in sorted(R.mentions, key=lambda m: (m["groupe"], m["s"]["cle"])):
        s = m["s"]
        ws2.append([s["id"], s["jour"], s["logiciel"], s["site"], s["dossier"], s["cle"], s["id_dico"], m["code"], m["famille"],
                    m["groupe"], m["nature"], m["statut"], s["texte"] or None, f"{s['cle']}|{m['code']}"])
    ws2.freeze_panes = "A2"
    ws2.auto_filter.ref = f"A1:N{ws2.max_row}"
    ws3 = wb.create_sheet("Dossiers source")
    ws3.sheet_state = "hidden"
    ws3.append(["Cle dossier-source", "Logiciel", "Site", "Num_Dossier", "Au moins un groupe decrit", "Au moins une hypothese",
                "Lignes source", "Premiere date", "Derniere date"])
    par = {}
    for s in R.sources:
        par.setdefault(s["cle"], []).append(s)
    G = R.global_
    hyp = {k for k, _ in G.hyp}
    for k in sorted(par):
        l = par[k]
        s = l[0]
        ws3.append([k, s["logiciel"], s["site"], s["dossier"], int(k in G.dossiers_cliniques), int(k in hyp), len(l),
                    min(x["jour"] for x in l), max(x["jour"] for x in l)])
    ws3.freeze_panes = "A2"


# ----------------------------------------------------------------------------------------------
def a_classer(wb, R):
    """Feuille visible des formulations nouvelles (seulement s'il y en a)."""
    if not R.nouvelles:
        return None
    ws = wb.create_sheet("À classer")
    mise_en_page(ws, "B67313", zoom=85, figer="A7")
    bloc(ws, 1, 1, 2, 8, "NOUVELLES FORMULATIONS / À VALIDER", taille=21, gras=True, couleur=BLANC, fond=NAVY)
    auto = sum(1 for n in R.nouvelles.values() if n["complet"])
    bloc(ws, 3, 1, 3, 8, f"{len(R.nouvelles)} textes absents du dictionnaire : {auto} proposés automatiquement (comptés, à valider), "
                         f"{len(R.nouvelles) - auto} à classer (hors disque tant qu'ils ne sont pas classés).",
         taille=11, couleur=GRIS, fond=CLAIR)
    bloc(ws, 4, 1, 4, 8, "Compléter le fichier sorties/Diagnostics_a_classer_… puis copier les lignes validées dans "
                         "Dictionnaire_Diagnostics.xlsx (feuille Formulations) et relancer.", taille=11, couleur=AL_T, fond=AL_F)
    ent = ["ID proposé", "Texte d'origine", "Lignes", "Logiciels", "Sites", "Traitement", "Proposition / suggestion (groupes)",
           "Statuts proposés"]
    for c, t in enumerate(ent, start=1):
        ecrire(ws, (6, c), t, gras=True, couleur=BLANC, fond=NAVY, h=None)
    r = 7
    for t, n in sorted(R.nouvelles.items(), key=lambda x: (-x[1]["n"], x[0])):
        lignes = n["lignes"] if n["complet"] else n["suggestion"]
        prop = " + ".join(R.dico.groupes.get(c, (c,))[0] for c, _ in lignes)
        stat = " + ".join(s for _, s in lignes)
        vals = [n["id"], t, n["n"], ", ".join(sorted(n["logiciels"])), ", ".join(sorted(n["sites"])),
                "Proposé automatiquement (compté)" if n["complet"] else ("Suggestion à vérifier (non comptée)" if lignes
                                                                         else "À classer (non compté)"), prop or None, stat or None]
        for c, v in enumerate(vals, start=1):
            ecrire(ws, (r, c), v, couleur=TXT, fond=zebre(r) if n["complet"] else AL_F, h=None, wrap=c in (2, 7, 8))
        ws.row_dimensions[r].height = 32
        r += 1
    for c, w in zip("ABCDEFGH", (11, 60, 9, 16, 16, 22, 50, 30)):
        ws.column_dimensions[c].width = w
    ws.auto_filter.ref = f"A6:H{r - 1}"
    return ws


def fichier_a_classer(chemin, R):
    """Fichier au format de la feuille Formulations du dictionnaire, prêt à compléter puis à copier."""
    if not R.nouvelles:
        return False
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Formulations"
    ws.append(["Lignes à vérifier puis à copier dans Dictionnaire_Diagnostics.xlsx, feuille Formulations. "
               "Une ligne par diagnostic regroupé ; phrase composite = plusieurs lignes avec le même ID."])
    ws["A1"].font = Font(italic=True, color="657A88")
    ws.append(DD.COLS_FORM + ["(info) Lignes dans l'export", "(info) Diagnostic regroupé proposé"])
    for c in ws[2]:
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor="143348")
        c.alignment = Alignment(wrap_text=True, vertical="center")
    jj = f"{R.fin:%d-%m}"
    for t, n in sorted(R.nouvelles.items(), key=lambda x: (-x[1]["n"], x[0])):
        lignes = n["lignes"] if n["complet"] else (n["suggestion"] or [(None, None)])
        for code, statut in lignes:
            meth = (f"NOUVELLE FORMULATION / {jj} : proposition automatique à valider" if n["complet"]
                    else f"NOUVELLE FORMULATION / {jj} : suggestion à vérifier et compléter" if code
                    else f"NOUVELLE FORMULATION / {jj} : à classer")
            ws.append([n["id"], t, code, statut, meth, "A valider", None, t, n["n"],
                       R.dico.groupes.get(code, (None,))[0] if code else None])
    for c, w in zip("ABCDEFGHIJ", (9, 45, 12, 22, 40, 12, 20, 45, 12, 40)):
        ws.column_dimensions[c].width = w
    ws.freeze_panes = "A3"
    dv = DataValidation(type="list", formula1='"' + ",".join(DD.STATUTS) + '"', allow_blank=True)
    dv.add(f"D3:D{ws.max_row + 500}")
    ws.add_data_validation(dv)
    wg = wb.create_sheet("Groupes (rappel)")
    wg.append(DD.COLS_GROUPES)
    for c in sorted(R.dico.groupes):
        wg.append((c,) + R.dico.groupes[c])
    for c, w in zip("ABCD", (14, 55, 30, 22)):
        wg.column_dimensions[c].width = w
    wb.save(chemin)
    return True


# ----------------------------------------------------------------------------------------------
def notez_bien(wb, R):
    ws = wb.create_sheet("Notez bien")
    mise_en_page(ws, "B67313", zoom=85, figer="A4")
    G = R.global_
    per = periode_txt(R)
    entete_commun(ws, R, "NOTEZ BIEN / REGROUPEMENTS ET CONTRÔLES",
                  f"{per} | Référentiel analytique proposé, non codification médicale officielle", [])
    bloc(ws, 4, 1, 5, 16, "REMARQUE : les diagnostics sont en grande partie saisis manuellement. Les regroupements facilitent la "
                          "lecture, sans modifier ni confirmer le diagnostic du médecin. Les textes originaux et les propositions "
                          "restent accessibles.", taille=11, gras=True, couleur=AL_T, fond=AL_F)
    n_dico = len(R.dico.entrees)
    presentes = len({s["id_dico"] for s in R.sources if s["id_dico"] in R.dico.entrees})
    auto = sum(1 for n in R.nouvelles.values() if n["complet"])
    sans_site = sum(1 for s in R.sources if s["site"] == SANS_SITE)
    f_gps, f_evo = R.fichiers["GPS"], R.fichiers["Evolucare"]
    textes = [
        ("1 / Ce qui a change", "Le disque présente les 16 familles nommées. La longue traîne n’est plus masquée dans Autres libellés "
                                "cliniques. Tous les groupes sont conservés dans les détails, même lorsqu’ils ne concernent qu’un seul "
                                "dossier-source."),
        ("2 / Normalisation prudente", "Casse, accents, espaces, fautes évidentes et abréviations usuelles lisibles sont rapprochés. Une "
                                       "phrase composite est décomposée uniquement en problèmes explicitement lisibles. Aucun symptôme "
                                       "n’est transformé en maladie, aucune fin de texte tronquée n’est reconstruite."),
        ("3 / Dictionnaire auditable", f"{n_dico} formulations normalisées dans le dictionnaire conservé. {presentes} sont présentes dans "
                                       f"ces exports; {len(R.nouvelles)} formulations nouvelles : {auto} proposées automatiquement à partir "
                                       f"de morceaux déjà connus, {len(R.nouvelles) - auto} à classer. Les cellules de validation "
                                       "précédentes sont conservées."),
        ("4 / Validation médicale indispensable", "Ces rapprochements sont des propositions analytiques, pas des diagnostics confirmés ni "
                                                  "un codage CIM/SNOMED. Le Médecin directeur doit valider les regroupements, les sigles "
                                                  "locaux, les limites de synonymie et les textes composites. Aucun code médical officiel "
                                                  "n’a été attribue."),
        ("5 / Maladies, symptômes, situations", "La répartition principale distingue dans les détails les diagnostics cités, "
                                                "symptômes/syndromes et situations cliniques. Grossesse, travail, naissance et nouveau-ne "
                                                "sans problème décrit ne sont pas des maladies. Les actes, suivis sans diagnostic, "
                                                "résultats normaux et demandes administratives restent hors disque."),
        ("6 / Familles non officielles", "La famille d’un groupe est exclusive et analytique. Les infections localisées restent dans la "
                                         "famille de l’organe (urinaire, ORL, peau, etc.). Infectieux / parasitaire n’est donc pas le "
                                         "total de toutes les infections. Les familles ne sont ni les services prescripteurs ni des "
                                         "chapitres CIM certifiés."),
        ("7 / Statut du diagnostic", "Sans marqueur de doute signifie seulement que le texte ne porte pas une suspicion explicite "
                                     "lisible. Cela ne prouve pas la confirmation. Exclure, ?, probable, suspicion et diagnostics "
                                     "différentiels sont classes à part; les antécédents et diagnostics explicitement écartés aussi."),
        ("8 / Hypothèses seules", f"Un diagnostic figure en hypothèses seules s'il est seulement suspecte dans ce dossier et logiciel. "
                                  f"{G.hyp_toutes} associations comportent une hypothèse; {len(G.hyp)} restent hypothèses seules. Les "
                                  f"{G.hyp_toutes - len(G.hyp)} autres suivent uniquement une regle de non-double-comptage, sans "
                                  "confirmation clinique supposee."),
        ("9 / Unités de comptage", "Un dossier est identifie par logiciel + site + Num_Dossier. Un diagnostic répété dans le même "
                                   "dossier ne compte qu'une fois sur la période. Un dossier avec hypertension et diabète compte pour "
                                   "deux diagnostics, mais reste un dossier."),
        ("10 / Limite entre logiciels", f"{len(G.dossiers)} dossiers des logiciels ne signifient pas {len(G.dossiers)} patients uniques. "
                                        "Aucun rapprochement patient GPS-Evolucare fiable n'est fourni. Les chiffres sont documentaires; "
                                        "les logiciels restent séparés."),
        ("11 / Récurrence et proportions", f"Récurrent = présent dans au moins deux dossiers de logiciel. Ce n'est pas une rechute. Part "
                                           f"du disque = diagnostics comptabilisés de la famille / {G.total}. Les chiffres ne sont ni "
                                           "incidence, ni prévalence, ni couverture de toutes les visites."),
        ("12 / Période et mise à jour", f"Les deux fichiers couvrent la période du {per}, selon Date. Ils remplacent intégralement les "
                                        "exports précédents, sans cumul. Des corrections existent aussi sur les jours antérieurs : une "
                                        "variation entre classeurs ne doit pas etre interprétée comme une hausse épidémiologique."),
        ("13 / Jours versus période", f"Le quotidien compte chaque diagnostic au plus une fois par dossier et par jour. Somme "
                                      f"quotidienne : {G.somme_jours}; total distinct sur la période : {G.total}; écart : "
                                      f"{G.somme_jours - G.total} répétitions entre jours."),
        ("14 / Saisies a revoir", f"{G.lignes_a_clarifier} lignes avec un texte ou sigle à clarifier; les parties lisibles peuvent "
                                  f"rester comptees. {G.lignes_non_exploitables} lignes sans diagnostic exploitable; "
                                  f"{G.dossiers_non_exploitables} dossiers ne contiennent que ces saisies. Les indicateurs peuvent se "
                                  "recouper."),
        ("15 / Troncature et erreur d’export", f"{G.lignes_gps_50} textes GPS atteignent exactement 50 caractères : indice de troncature "
                                               "possible, non preuve. Aucun suffixe n'est complete par supposition. Toute valeur source "
                                               "non exploitable est conservee comme texte, hors des calculs cliniques."),
        ("16 / Site manquant", "Tous les sites sont renseignés dans ces exports; aucun site n'a été deduit du nom, de l'adresse, du "
                               "médecin ou d'une hypothèse." if not sans_site else
                               f"{sans_site} lignes n'ont pas de site : elles restent présentées à part, sans site deduit."),
        ("17 / Qualificatifs conservés", "Les regroupements précédents sont reconduits pour les textes connus. Les précisions restent dans "
                                         "les textes. Pas de transformation d'un symptôme en maladie ni de code CIM attribue."),
        ("18 / Utilisation et validation", "Les correspondances se modifient dans Dictionnaire_Diagnostics.xlsx (à côté du programme), "
                                           "puis le programme est relancé : la base est alors reclassée entièrement. Les clés de "
                                           "dédoublonnage sont tracées dans les bases masquées."),
        ("19 / Confidentialité", "Le fichier de synthèse ne reprend ni noms de patients, ni adresses, ni dates de naissance. Les bases "
                                 "techniques, masquées pour la lisibilite mais non protégées comme un système de sécurité, contiennent "
                                 "les Num_Dossier et textes originaux. Diffusion réservée aux personnes habilitées."),
        ("20 / Source de la structure", f"Structure : classeur Diagnostics avec Composition familles (version retenue). Sources : "
                                        f"{f_gps[0]} ({f_gps[1]} lignes) et {f_evo[0]} ({f_evo[1]} lignes). Capacités : fichier séparé."),
    ]
    r = 7
    for titre, t in textes:
        bloc(ws, r, 1, r, 16, titre, taille=11, gras=True, couleur=BLANC, fond=NAVY)
        bloc(ws, r + 1, 1, r + 2, 16, t, taille=11, couleur=TXT, fond=CLAIR)
        hauteurs(ws, {r: 25, r + 1: 24, r + 2: 24, r + 3: 21})
        r += 4
    bloc(ws, r, 1, r, 16, "CONTRÔLES QUANTITATIFS / SOURCE ET PÉRIMÈTRE", taille=11, gras=True, couleur=BLANC, fond=NAVY)
    r += 2
    for c1, c2, t in ((1, 4, "Site / source"), (5, 6, "Lignes"), (7, 9, "Dossiers-source"), (10, 12, "Diagnostics décrites"),
                      (13, 16, "Hypothèses seules")):
        bloc(ws, r, c1, r, c2, t, taille=11, gras=True, couleur=TXT, fond=CLAIR)
    lignes = [("GPS / tous sites", R.par_logiciel["GPS"]), ("Evolucare / tous sites", R.par_logiciel["Evolucare"])]
    tab = [(n, p.lignes, p.dossiers, p.decrits, p.hyp) for n, p in lignes]
    tab.append(("TOTAL / deux sources", len(R.sources), len(G.dossiers), G.total, len(G.hyp)))
    for s in SITES + [SANS_SITE]:
        P = R.sites[s]
        tab.append((s if s != SANS_SITE else "SITE NON RENSEIGNÉ", len(P.sources), len(P.dossiers), P.total, len(P.hyp)))
    for i, (n, a, b, c, d) in enumerate(tab):
        rr = r + 1 + i
        bloc(ws, rr, 1, rr, 4, n, taille=11, couleur=TXT, fond=zebre(rr))
        for (c1, c2), v in zip(((5, 6), (7, 9), (10, 12), (13, 16)), (a, b, c, d)):
            bloc(ws, rr, c1, rr, c2, v, couleur=TXT, fond=BLANC, fmt=NB)
    r = r + 1 + len(tab) + 2
    bloc(ws, r, 1, r, 16, "EXEMPLES DE RAPPROCHEMENT / LIMITES A RESPECTER", taille=11, gras=True, couleur=BLANC, fond=NAVY)
    exemples = [("HTA / hypertension artérielle / HTA + DT2", "HTA est rassemblée; DT2 est compté séparément lorsqu’il est cité."),
                ("Diabète type 2 / DT2 / DS2", "Type 2 rapproché; type 1 et type non précisé restent distincts."),
                ("Paludisme / accès palustre / paludisme a exclure", "Les libellés cités sont rapprochés; a exclure reste une hypothèse."),
                ("Douleur abdominale : appendicite ?", "Douleur conservée en symptôme; appendicite séparée en hypothèse."),
                ("SOPK / ovaires micropolykystiques", "Syndrome et simple morphologie restent deux groupes, sans inference."),
                ("BC surinfecte / BAN / HP en fin de traitement", "Pas d’expansion forcee; sigles et contexte médical à clarifier."),
                ("Texte GPS de 50 caractères", "Partie lisible regroupée; fragment final non reconstruit."),
                ("Texte Pas de diagnostic / #NAME?", "Conservé dans la trace, hors répartition clinique.")]
    for i, (a, b) in enumerate(exemples):
        rr = r + 1 + i
        bloc(ws, rr, 1, rr, 6, a, taille=11, couleur=TXT, fond=zebre(rr))
        bloc(ws, rr, 7, rr, 16, b, taille=11, couleur=GRIS, fond=zebre(rr))
    r = r + 1 + len(exemples) + 2
    bloc(ws, r, 1, r, 16, "MISE A JOUR / CONTRÔLES ET DÉFINITIONS SIMPLES", taille=12, gras=True, couleur=BLANC, fond=C_NAVY, **AP)
    r += 2
    bloc(ws, r, 1, r, 16, "Les nouveaux fichiers remplacent les anciens. Les textes connus gardent les mêmes correspondances; aucune "
                          "recodification CIM-11 n'est introduite.", couleur=C_TXT, fond=C_CLAIR, **AP)
    r += 2
    dernier = sum(1 for s in R.sources if s["jour"] == R.fin)
    lig_nv = sum(n["n"] for n in R.nouvelles.values())
    kv = [("Periode commune", f"{per}"), ("Formulations absentes du dictionnaire", str(len(R.nouvelles))),
          ("Lignes portant une nouvelle formulation", str(lig_nv)), ("Formulations proposées automatiquement", str(auto)),
          ("Formulations à classer (hors disque)", str(len(R.nouvelles) - auto)),
          (f"Lignes du {R.fin:%d} {per.split(' ', 1)[1] if ' ' in per else ''}".strip(), str(dernier)),
          ("Sites non renseignés dans cet export", str(sans_site))]
    for i, (a, b) in enumerate(kv):
        rr = r + i
        bloc(ws, rr, 1, rr, 10, a, couleur=C_TXT, fond=C_CLAIR, **AP)
        bloc(ws, rr, 11, rr, 16, b, gras=True, couleur=C_TXT, fond=BLANC, **AP)
    r += len(kv) + 2
    bloc(ws, r, 1, r, 16, "LEXIQUE / LES MOTS DU DASHBOARD", gras=True, couleur=BLANC, fond=C_NAVY, **AP)
    lex = [("Dossier des logiciels", "Un numéro de dossier dans un site et un logiciel. Ce n'est pas nécessairement une personne differente."),
           ("Diagnostic regroupé", "Plusieurs ecritures reconnues comme équivalentes, par exemple HTA et hypertension artérielle."),
           ("Diagnostic comptabilisé", "Un diagnostic retrouve dans un dossier. Repete trois fois dans le même dossier et logiciel, il ne "
                                       "compte qu'une fois sur la période."),
           ("Diagnostics différents", "Nombre de groupes distincts representes, pas le nombre de patients ou le volume total."),
           ("Dossier-groupe / mention (anciens termes)", "Ancien nom du diagnostic comptabilisé : une association dossier + diagnostic. "
                                                         "Exemple : un dossier avec HTA et diabète donne deux diagnostics comptabilisés."),
           ("Part dans le disque global", "Diagnostics comptabilisés d'une famille divises par le total general. Ce n'est pas une "
                                          "proportion de patients."),
           ("Part dans une famille", "Diagnostics comptabilisés du groupe divises par le total de cette famille. Leur somme vaut 100 % "
                                     "pour toute famille non vide."),
           ("Hypothese seule", "Diagnostic uniquement évoqué avec doute pour ce dossier. Exclu du disque principal, mais conservé dans "
                               "les details."),
           ("Sans marqueur de doute", "Aucun doute explicite dans le texte. Cela ne prouve pas la confirmation médicale."),
           ("Récurrent", "Diagnostic retrouve dans au moins deux dossiers de logiciel. Ne signifie ni rechute, ni nouvelle maladie "
                         "apparue."),
           ("Famille Infectieux / parasitaire", "Ce n'est pas le total de toutes les infections : les infections d'organe restent dans "
                                                "les familles urinaire, ORL, peau, etc. selon le modèle."),
           ("Formulation à classer", "Texte absent du dictionnaire et non décomposable en morceaux connus : conservé hors disque jusqu'à "
                                     "son classement dans Dictionnaire_Diagnostics.xlsx."),
           ("Âge et sexe", "Sexe et date de naissance de l’export (première valeur renseignée du dossier). Âge au premier jour du "
                           "dossier dans la période ; date absente ou incohérente = âge inconnu. Dossiers par logiciel, pas patients.")]
    for i, (a, b) in enumerate(lex):
        rr = r + 2 + i
        bloc(ws, rr, 1, rr, 5, a, gras=True, couleur=C_TXT, fond=C_CLAIR, **AP)
        bloc(ws, rr, 6, rr, 16, b, couleur=C_TXT, fond=BLANC, **AP)
        ws.row_dimensions[rr].height = 30
    r = r + 2 + len(lex) + 2
    bloc(ws, r, 1, r, 16, "CONTRÔLES DE COHÉRENCE / ÉCART ATTENDU : ZERO", gras=True, couleur=BLANC, fond=C_NAVY, **AP)
    fam_tot = sum(v["total"] for v in G.familles.values())
    ctrl = [("Sources = deux logiciels", len(R.sources) - sum(p.lignes for p in R.par_logiciel.values())),
            ("Dossiers = deux sites", len(G.dossiers) - sum(len(R.sites[s].dossiers) for s in SITES + [SANS_SITE])),
            ("Diagnostics = deux sites", G.total - sum(R.sites[s].total for s in SITES + [SANS_SITE])),
            ("Composition = Dashboard", fam_tot - G.total),
            ("Diagnostics différents = composition", G.differents - sum(v["differents"] for v in G.familles.values())),
            ("Somme des parts du disque = 100 %", round(fam_tot / G.total - 1, 12) if G.total else 0),
            ("Jours - période = répétitions attendues", 0)]
    R.controles_notez = ctrl
    for i, (a, b) in enumerate(ctrl):
        rr = r + 2 + i
        bloc(ws, rr, 1, rr, 12, a, couleur=C_TXT, fond=C_CLAIR, **AP)
        bloc(ws, rr, 13, rr, 16, b, gras=True, couleur=C_TXT, fond=BLANC, **AP)
    r = r + 2 + len(ctrl) + 2
    bloc(ws, r, 1, r, 16, "CONFIDENTIALITE / Les vues de synthèse ne contiennent pas de noms de patients. Les bases masquées "
                          "comportent les numéros de dossier et textes. Masquer une feuille n'est pas un contrôle d accès : diffusion "
                          "aux personnes habilitées uniquement.", couleur="C87B00", fond="FFF2CB", **AP)
    hauteurs(ws, {1: 24, 2: 22, 3: 27, 4: 21, 5: 21})
    return ws
