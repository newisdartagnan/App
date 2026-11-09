# API des laboratoires partenaires (PRE-06)

Cette API permet au logiciel d'un laboratoire partenaire (Eyano Centre
Biologique ou un autre) d'envoyer les résultats d'un bon d'examen MEDIHOME
directement, sans passer par le courriel ni par le portail web.

Le portail (`/personnel/laboratoire`) et l'API appliquent **les mêmes règles** :
le laboratoire ne traite que les bons **payés** qui lui sont transmis ; les
résultats déposés restent invisibles au patient tant que le médecin
prescripteur ne les a pas validés.

## Pourquoi pas le courriel

MEDIHOME ne lit **aucune boîte de courriel** pour recevoir des résultats. Un
courriel peut être usurpé (adresse d'expéditeur falsifiée), intercepté ou
mal adressé, transite en clair chez plusieurs prestataires et ne dit pas de
façon sûre à quel patient et à quel bon il se rapporte. Pour des données de
santé, seuls le portail (compte nominatif avec second facteur) et cette API
(jeton révocable, HTTPS, bon et code de retrait vérifiés) sont acceptés.

## Obtenir un jeton

1. Un administrateur MEDIHOME ouvre « Jetons des laboratoires »
   (`/personnel/admin/jetons-laboratoire`), choisit le laboratoire et donne
   un nom au jeton (par exemple « Logiciel du laboratoire »).
2. Le jeton s'affiche **une seule fois** (il commence par `mhlab_`). Il est
   transmis au laboratoire par un canal sûr (en main propre, ou par
   téléphone), jamais par courriel.
3. MEDIHOME ne garde que son empreinte (SHA-256) : un jeton perdu ne se
   retrouve pas, on en émet un nouveau et on révoque l'ancien.
4. Un jeton révoqué, ou le jeton d'un laboratoire désactivé, est refusé
   immédiatement.

Le jeton se garde côté laboratoire comme un mot de passe : jamais dans une
adresse web, jamais dans un journal.

## Envoyer des résultats

```
POST https://<adresse de MEDIHOME>/api/laboratoire/v1/resultats
Authorization: Bearer mhlab_…
Content-Type: application/json
```

Corps (JSON) :

| Champ | Obligatoire | Description |
| --- | --- | --- |
| `numero_bon` | oui | Numéro du bon, par exemple `BE-2026-000123`. |
| `code_retrait` | si le patient n'a pas encore été reçu | Code à 8 chiffres présenté par le patient. Le bon passe alors « au laboratoire ». Inutile pour un bon déjà reçu (portail ou envoi précédent). |
| `cle` | recommandé | Clé d'envoi unique (16 à 64 caractères : lettres, chiffres, tirets). Le même envoi rejoué avec la même clé ne crée pas de doublon. |
| `valeurs` | `valeurs` et/ou `pdf_base64` | Liste de valeurs : `analyte` (obligatoire), `valeur` (obligatoire), `unite`, `normes`, `anormal` (vrai/faux). 100 valeurs au plus. |
| `pdf_base64` | `valeurs` et/ou `pdf_base64` | Compte rendu PDF encodé en base64 (10 Mo au plus avant encodage). |
| `critique` | non | `true` pour un résultat critique : le médecin est alerté sans attendre, et le coordinateur si personne ne le traite sous 2 heures. |
| `ligne_id` | non | Identifiant de l'examen du bon concerné, si le dépôt ne porte que sur lui. |

Le PDF est vérifié par son **contenu** (en-tête PDF, fin de fichier) ; un PDF
qui contient du contenu actif (JavaScript, lancement de programme, fichier
joint, formulaire) est refusé : envoyez un PDF simple, non chiffré.

### Exemple : patient reçu et résultats en un envoi

```bash
curl -sS https://medihome.example/api/laboratoire/v1/resultats \
  -H "Authorization: Bearer $JETON_MEDIHOME" \
  -H "Content-Type: application/json" \
  -d '{
    "numero_bon": "BE-2026-000123",
    "code_retrait": "48291736",
    "cle": "eyano-2026-12-07-000981",
    "critique": false,
    "valeurs": [
      {"analyte": "Glycémie à jeun", "valeur": "1,05", "unite": "g/L", "normes": "0,70 - 1,10", "anormal": false}
    ]
  }'
```

### Exemple : compte rendu PDF pour un bon déjà reçu

```bash
PDF=$(base64 -w0 compte-rendu.pdf)
curl -sS https://medihome.example/api/laboratoire/v1/resultats \
  -H "Authorization: Bearer $JETON_MEDIHOME" \
  -H "Content-Type: application/json" \
  -d "{\"numero_bon\": \"BE-2026-000123\", \"cle\": \"eyano-2026-12-07-000982\", \"pdf_base64\": \"$PDF\"}"
```

## Réponses

Toutes les réponses sont en JSON, jamais mises en cache, et ne contiennent
aucune donnée de santé.

| Code | Corps | Sens |
| --- | --- | --- |
| 201 | `{"statut": "depose", "resultat_id": "…", "bon": {"numero": "BE-…", "statut": "resultats_deposes"}}` | Résultats enregistrés ; le médecin est prévenu. |
| 200 | `{"statut": "deja_recu", …}` | Même `cle` déjà reçue : rien de nouveau n'est créé. |
| 401 | `{"erreur": "jeton_invalide"}` | Jeton absent, inconnu, révoqué, ou laboratoire désactivé. |
| 403 | `{"erreur": "code_retrait_incorrect"}` | Code de retrait faux, ou absent pour un bon pas encore reçu. |
| 404 | `{"erreur": "bon_introuvable"}` | Numéro inconnu, bon non payé, ou bon d'un autre laboratoire (même réponse). |
| 409 | `{"erreur": "refuse", "message": "…"}` | Bon qui n'accepte plus de résultats, PDF refusé, quota atteint… |
| 415 | `{"erreur": "format"}` | Le corps n'est pas du JSON. |
| 422 | `{"erreur": "donnees_invalides", "champs": {…}}` | Champ manquant ou mal formé. |
| 429 | `{"erreur": "trop_de_requetes"}` (ou `trop_d_essais`) | Trop de requêtes (60 par minute et par jeton), ou trop de codes de retrait faux pour ce bon (5 par heure). |

Conseils pour le logiciel du laboratoire :

- toujours envoyer une `cle` propre à chaque dépôt, et la réutiliser telle
  quelle en cas de nouvel essai (coupure réseau, délai dépassé) ;
- sur 429, attendre une minute ; sur 5xx, réessayer plus tard avec la même `cle` ;
- ne jamais réessayer en boucle un code de retrait refusé : demander au
  patient de vérifier son code dans son espace (« Mes examens »).

## Ce que le laboratoire ne reçoit pas

L'API ne permet que de **déposer**. Elle ne renvoie ni l'identité complète du
patient, ni son dossier, ni les résultats déjà validés. Le détail d'un bon
(identité utile, examens, renseignements cliniques) se lit dans le portail,
après connexion nominative.
