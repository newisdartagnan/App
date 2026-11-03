# Doc&I — consultation médicale à distance

Plateforme où le patient demande une consultation, réserve un créneau, paie
d'avance par Mobile Money, est consulté en vidéo par un médecin, puis reçoit
son ordonnance et ses résultats validés par ce médecin. Le cahier des charges
complet est dans le document « Cahier des charges — Plateforme de
téléconsultation SIH (Kinshasa) ».

Le dossier s'appelle `doc-and-i` et non `Doc&I` : sous PowerShell `&` est un
opérateur, et Docker retire ce caractère des noms de projet.

## Ce qui fonctionne aujourd'hui

| Exigence | Contenu | État |
| --- | --- | --- |
| CPT-01 | Inscription et connexion par numéro de téléphone et code SMS à usage unique | Fait |
| CPT-02 | Profil du patient (nom, sexe, date de naissance, commune, langue, contact d'urgence) | Fait |
| CPT-04 | Consentement à la téléconsultation et au traitement des données, horodaté et révocable | Fait |
| — | Rôles du cahier des charges (patient, médecin, laboratoire, caisse…) | Fait |
| — | En-têtes de sécurité, identifiants UUID, interface installable (PWA, manifeste) | Fait |
| RDV-01 et suite | Demande de consultation, créneaux, paiement | Prochain lot |

## Lancer l'application (Docker, rien à installer d'autre)

Dans PowerShell ou Git Bash, depuis `Hospistal\doc-and-i` :

```bash
docker compose up -d --build
```

Puis ouvrir <http://localhost:8090>. Au premier démarrage, le conteneur
installe les dépendances, crée le `.env`, génère la clé, crée les tables et les
rôles (quelques minutes la première fois).

**Le code SMS en développement** : aucun SMS n'est envoyé (`SMS_DRIVER=log`).
Le code s'écrit dans le journal :

```bash
docker compose exec app tail -n 5 storage/logs/laravel.log
```

| Service | Adresse sur le poste |
| --- | --- |
| Application | <http://localhost:8090> |
| PostgreSQL (pgAdmin, DBeaver) | `localhost:5434`, base `doc_and_i`, utilisateur `doc_and_i`, mot de passe `changeme` |

Les ports diffèrent de ceux de dpi-rdc (8080, 5433) : les deux projets
tournent en même temps.

Arrêter : `docker compose down`. Tout effacer, base comprise :
`docker compose down -v`.

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
