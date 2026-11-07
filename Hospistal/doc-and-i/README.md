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
| PAY-01 | Tarif en USD ou en CDF au taux du jour, saisi par la caisse (bornes, second agent au-delà de 10 % d'écart) | Fait |
| PAY-02 à PAY-04 | Paiement Mobile Money et carte par l'agrégateur ; rendez-vous confirmé seulement par le rappel signé de l'opérateur, une seule fois | Fait (pilote « simulation » ; agrégateur réel à choisir) |
| PAY-05, PAY-08 | Factures et avoirs numérotés, non modifiables, reçu par SMS et en PDF | Fait |
| PAY-06 | Rapprochement quotidien avec le relevé de l'agrégateur, écarts à traiter | Fait |
| PAY-07 | Remboursement validé par un second agent de caisse, rendu sur le même moyen | Fait |
| PAY-09 | Journal de caisse ; reversement des honoraires préparé et payé par deux agents | Fait |
| RG-01 à RG-05 | Paiement d'avance, idempotence, paiement tardif (créneau repris ou remboursé), remboursement à l'annulation | Fait |

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
par le client. Une réservation reste « en attente de paiement » 15 minutes,
puis le créneau est libéré.

**Le paiement en développement** : aucun argent ne circule
(`PAIEMENT_FOURNISSEUR=simulation`, interdit hors local et tests). Après
« Payer », la page d'attente propose « Simuler : paiement réussi / échoué » :
le rappel signé qu'enverrait l'opérateur suit le même chemin qu'un vrai. Le
paiement en CDF demande d'abord un taux du jour saisi par la caisse.

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

Garde-fous réglables dans `config/caisse.php` : bornes du taux du jour (500 à
10 000 CDF pour un dollar), écart au-delà duquel un second agent confirme le
taux (10 %), relances d'un remboursement resté sans réponse de l'opérateur
(12 essais, toutes les 5 minutes, puis « À suivre » pour la caisse).

## Administration

L'espace administrateur (<http://localhost:8097/personnel/admin>) est réservé
au rôle `admin_fonctionnel`. Un compte administrateur ne se crée JAMAIS depuis
l'écran : seulement par la commande, sur le serveur, pour qu'un administrateur
ne puisse pas fabriquer d'autres administrateurs. Par exemple, pour le compte
réel de l'administrateur désigné par le client :

```bash
docker compose exec app php artisan personnel:creer-compte --role=admin_fonctionnel \
  --email=<courriel professionnel> --telephone=<mobile RDC> --nom=Ikula --prenom=Boris
```

La commande affiche un lien d'activation à usage unique (72 heures), à lui
transmettre en main propre ; s'il se perd : `php artisan personnel:lien-activation <courriel>`.
Une fois connecté, l'administrateur gère dans « Personnel » :

- la création des comptes médecin (spécialité, numéro d'Ordre), caisse,
  laboratoire (rattaché à un laboratoire partenaire actif), coordination et
  support ; le lien d'activation s'affiche une seule fois, à transmettre à la
  personne seule, et un nouveau lien invalide l'ancien ;
- la vérification à l'Ordre des médecins (sans elle, pas proposés aux
  patients), leur spécialité et leur présentation, leur planning ;
- la désactivation (motif obligatoire, session coupée, rien n'est supprimé) et
  la réactivation. Un médecin qui a des rendez-vous confirmés à venir n'est
  désactivé qu'après leur annulation, depuis son espace, ou depuis la fiche
  avec remboursement intégral des patients (RG-05).

Règles de sécurité de l'espace :

- **Quatre yeux sur l'Ordre** : l'administrateur qui a créé ou réactivé un
  compte médecin ne vérifie pas lui-même son inscription à l'Ordre ; il faut
  un second administrateur. Prévoir donc deux administrateurs.
- Un médecin réactivé doit être vérifié de nouveau à l'Ordre.
- Les comptes administrateurs et d'audit ne se créent, ne s'activent, ne se
  désactivent et ne se réactivent que par commande, sur le serveur :

  ```bash
  docker compose exec app php artisan personnel:desactiver <courriel> --motif="Départ"
  docker compose exec app php artisan personnel:reactiver <courriel>
  ```
- Une désactivation ferme aussi les sessions déjà ouvertes, même si le compte
  est réactivé ensuite.

Chaque action est inscrite au « Journal », en lecture seule. Les actions faites
par commande (création d'un administrateur) n'y figurent pas : elles n'ont
pas d'auteur connecté.

Démonstration locale : un administrateur fictif, `admin.demo@doc-and-i.local`,
à activer par `php artisan personnel:lien-activation admin.demo@doc-and-i.local`.

## Note de consultation et ordonnance (DOS-02, PRE-01 à PRE-03)

- **Note** : rédigée par le médecin du rendez-vous dans la salle (enregistrée
  automatiquement) ou après, depuis l'agenda (« Consultations terminées »,
  bouton « Note »). Signée, elle ne change plus ; on la complète par un
  avenant daté et signé.
- **Diagnostic CIM-11** : le référentiel est **à fournir par le client**. En
  attendant, le code et le libellé sont saisis librement ; seule la forme du
  code est contrôlée (ex. `BA00`, `1A00.0`, `CA40.Z`).
- **Ordonnance** : « Signer et émettre » lui donne un numéro `OR-AAAA-NNNNNN`
  sans trou, une empreinte et un code de vérification ; elle ne se modifie
  plus (une erreur se corrige par une nouvelle). Le patient reçoit un SMS
  sans contenu médical et la retrouve dans « Mes ordonnances » (PDF avec QR
  code). Le pharmacien scanne le QR code : la page publique
  `/verification-ordonnance/{code}` dit si l'ordonnance est authentique ou
  altérée, et ne montre le patient que par ses initiales.
- **Alertes à la prescription (PRE-02)** : une allergie active déclarée au
  dossier du patient, retrouvée dans le nom du médicament (sans accents ni
  casse), bloque l'émission tant que le médecin ne coche pas « je maintiens
  cette prescription » ; ce maintien est tracé (table `alertes_prescription`).
  Les interactions majeures suivent le même mécanisme, à partir de la table
  `interactions_medicamenteuses` (DCI A, DCI B, message), **livrée vide** :
  son contenu est médical et doit être fourni et validé par le pharmacien du
  client. L'application n'invente aucune donnée médicale (ni familles
  d'allergènes, ni correspondance entre nom commercial et DCI).

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

Démonstration pas à pas de tout ce qui fonctionne : `docs/scenario-demonstration.md`.

Règles de développement : voir `CLAUDE.md`.
