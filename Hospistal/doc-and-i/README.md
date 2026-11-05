# MediHome — La médecine vient à vous

Plateforme où le patient demande une consultation, réserve un créneau, paie
d'avance par Mobile Money, est consulté en vidéo par un médecin, puis reçoit
son ordonnance et ses résultats validés par ce médecin. Le cahier des charges
complet est dans le document « Cahier des charges — Plateforme de
téléconsultation SIH (Kinshasa) ».

L'application s'appelle **MediHome** (logo et devise « La médecine vient à
vous. » dans `public/images`). Le dossier, le projet Docker et le dépôt gardent
le nom technique `doc-and-i`.

## Ce qui fonctionne aujourd'hui

| Exigence | Contenu | État |
| --- | --- | --- |
| CPT-01 | Inscription et connexion par numéro de téléphone et code SMS à usage unique | Fait |
| CPT-02 | Profil du patient (nom, sexe, date de naissance, commune, langue, contact d'urgence) | Fait |
| CPT-04 | Consentement à la téléconsultation et au traitement des données, horodaté et révocable | Fait |
| CPT-05 | Compte médecin créé par un administrateur (commande), lien d'activation, vérification à l'Ordre | Fait |
| CPT-06 | Connexion du personnel : courriel, mot de passe et code TOTP (ou code de récupération) | Fait |
| RDV-04 | Côté médecin : plages hebdomadaires, absences, agenda en lecture | Fait |
| — | Rôles du cahier des charges (patient, médecin, laboratoire, caisse…) | Fait |
| — | En-têtes de sécurité, identifiants UUID, interface installable (PWA, manifeste) | Fait |
| RDV-01, RDV-02 | Demande de consultation (motif, symptômes, pièces jointes) et tri des urgences, chaque question à répondre explicitement | Fait |
| RDV-03 à RDV-05 | Choix par spécialité, médecin ou premier disponible ; créneaux libres ; réservation bloquée 15 min en attente de paiement | Fait |
| RDV-06, RDV-07 | Rappels SMS 24 h et 1 h avant ; annulation et report selon RG-04/05/06 ; annulation par le médecin depuis son agenda | Fait |
| PAY-01 et suite | Paiement Mobile Money et carte, confirmation du rendez-vous | Prochain lot |

## Lancer l'application (Docker, rien à installer d'autre)

Dans PowerShell ou Git Bash, depuis `Hospistal\doc-and-i` :

```bash
docker compose up -d --build
```

Puis ouvrir <http://localhost:8097>. Au premier démarrage, le conteneur
installe les dépendances, crée le `.env`, génère la clé, crée les tables et les
rôles (quelques minutes la première fois).

**Le code SMS en développement** : aucun SMS n'est envoyé (`SMS_DRIVER=log`).
Le code s'écrit dans le journal :

```bash
docker compose exec app tail -n 5 storage/logs/laravel.log
```

| Service | Adresse sur le poste |
| --- | --- |
| Application | <http://localhost:8097> |
| PostgreSQL (pgAdmin, DBeaver) | `localhost:5437`, base `doc_and_i`, utilisateur `doc_and_i`, mot de passe `changeme` |

Les ports diffèrent de ceux de dpi-rdc (8080, 5433) : les deux projets
tournent en même temps.

**Données de démonstration** (en local seulement) : deux médecins fictifs
(médecine générale, pédiatrie) avec des plages du lundi au vendredi et un prix
indicatif de 10 USD, pour tester la réservation. Les vrais prix sont à fixer
par le client. Le paiement arrive au prochain lot : une réservation reste
« en attente de paiement » 15 minutes, puis le créneau est libéré.

Arrêter : `docker compose down`. Tout effacer, base comprise :
`docker compose down -v`.

**HTTPS ou localhost obligatoire** : l'historique du navigateur est chiffré
(un « Retour » après déconnexion ne réaffiche ni dossier ni clé de double
authentification). Ce chiffrement n'existe que sur une page sécurisée :
`http://localhost:8097` fonctionne, une adresse IP du réseau local en HTTP non.

## Comptes du personnel (médecins)

En attendant l'écran d'administration, un administrateur crée le compte d'un
médecin en ligne de commande. Aucun mot de passe n'est créé ni affiché : la
commande affiche un lien d'activation à usage unique, valable 72 heures, à
transmettre au médecin seul.

```bash
docker compose exec app php artisan personnel:creer-medecin \
  --email=dr.exemple@doc-and-i.local --telephone=0810000003 \
  --nom=Exemple --prenom=Marie --specialite=pediatrie --ordre=CNOM-0003
docker compose exec app php artisan personnel:verifier-ordre dr.exemple@doc-and-i.local
docker compose exec app php artisan personnel:lien-activation dr.exemple@doc-and-i.local
```

Par le lien, le médecin choisit son mot de passe (12 caractères au moins),
relie une application d'authentification (QR code ou clé), puis note ses
8 codes de récupération. Il se connecte ensuite sur
<http://localhost:8097/personnel/connexion>. Tant que l'Ordre n'est pas vérifié,
il n'est pas proposé aux patients.

Les médecins de démonstration (`medecin.demo@doc-and-i.local`,
`pediatre.demo@doc-and-i.local`) doivent eux aussi être activés :
`php artisan personnel:lien-activation medecin.demo@doc-and-i.local`.

### Caisse

Un agent de caisse se crée de la même façon, avec son rôle :

```bash
docker compose exec app php artisan personnel:creer-compte --role=caisse \
  --email=caisse@doc-and-i.local --telephone=0810000013 --nom=Exemple --prenom=Rose
```

La démonstration locale crée deux agents (`caisse1.demo@doc-and-i.local`,
`caisse2.demo@doc-and-i.local`), à activer par `personnel:lien-activation` :
deux personnes sont nécessaires, un remboursement n'étant jamais validé par
celui qui l'a demandé, ni un reversement payé par celui qui l'a préparé.
L'espace caisse est sur <http://localhost:8097/personnel/caisse>.

Tâches planifiées (service `scheduler`) : `paiement:executer-remboursements`
chaque minute, `paiement:rapprocher` chaque nuit pour la veille (relançable
pour un jour : `php artisan paiement:rapprocher 2026-12-07`). Les reversements
exigent `DOC_AND_I_COMMISSION_POURCENT` (décision du client, pas de défaut).

## Lancer les tests

```bash
docker compose run --rm tests
docker compose run --rm tests php artisan test --filter=Connexion
```

Les tests tournent sur PostgreSQL, dans la base `doc_and_i_test`, créée
automatiquement au premier démarrage du conteneur `db`. `phpunit.xml` impose
cette base et toutes les valeurs de test ; `tests/TestCase.php` refuse de
démarrer sur une base dont le nom ne finit pas par `_test`. La base de
l'application n'est jamais vidée par les tests.

## Modifier l'interface

Les écrans sont en Vue 3 avec Inertia (`resources/js/Pages`). Les fichiers
compilés sont versionnés dans `public/build` : le poste n'a pas besoin de npm
pour lancer l'application. Après une modification d'un écran :

```bash
npm install
npm run build
```

puis committer `public/build` avec la modification. L'intégration continue
vérifie que `public/build` correspond aux sources.

## Architecture

- Laravel 12, PHP 8.3, PostgreSQL 16, Redis 7 (sessions, cache, files).
- Interface patient : Vue 3 + Inertia, Tailwind, sans script ni police externe
  (politique de sécurité du contenu stricte).
- Services Docker : `app` (PHP-FPM), `worker` (files d'attente), `nginx`, `db`,
  `redis`, `tests` (à la demande).
- Intégration continue : `.github/workflows/doc-and-i.yml` à la racine du dépôt.

Règles de développement : voir `CLAUDE.md`.
