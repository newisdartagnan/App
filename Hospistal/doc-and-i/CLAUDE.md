# Doc&I — règles du projet

Plateforme de téléconsultation pour Kinshasa : données de santé et paiements.
Le cahier des charges fait foi (exigences CPT-, RDV-, TLC-, DOS-, PRE-, PAY-,
NOT-, ADM- ; règles RG-). Chaque commit cite les exigences qu'il traite.

## Langue

Code métier, routes, écrans, messages et tests en français, comme dpi-rdc.
Textes destinés au patient : phrases courtes, sans jargon.

## Tests

- Test d'abord : un test qui échoue, puis le code. Chaque règle métier a son
  test, chaque correction de bug son test de non-régression.
- PostgreSQL toujours, jamais SQLite. Base imposée `doc_and_i_test` ; ne
  jamais affaiblir `phpunit.xml` (valeurs forcées en `<env>` ET `<server>`) ni
  le garde-fou de `tests/TestCase.php`.
- Lancer : `docker compose run --rm tests`, ou sur le poste
  `DB_PORT=5432 php artisan test` avec un PostgreSQL local.
- Toute action médicale ou financière a des tests pour : double envoi,
  transition d'état interdite, accès par un autre rôle, accès au dossier d'un
  autre patient.

## Sécurité (cahier des charges, section 6)

- Chaque contrôleur vérifie côté serveur le rôle ET la relation de soin
  (ce patient-là). Jamais de contrôle par l'interface seule.
- Aucune valeur médicale par défaut : une case non cochée ne vaut pas
  « négatif » (leçon de l'audit de dpi-rdc).
- Les transitions d'état (rendez-vous, paiement, résultat) passent par une
  méthode qui refuse les transitions interdites, dans une transaction avec
  verrou.
- Paiement : jamais validé sur la parole du navigateur ; rappel signé de
  l'agrégateur et clé d'idempotence.
- Aucune donnée de santé dans un SMS, un WhatsApp, un journal ou une URL.
- Identifiants UUID ; aucun secret dans le code ; aucune donnée réelle hors
  production.
- `telephone` n'est pas « fillable » : il n'est posé qu'après vérification du
  code SMS.

## Interface

- Vue 3 + Inertia, mobile d'abord (Android d'entrée de gamme, 3G).
- Aucun script, police ou image venant d'un autre domaine (CSP stricte dans
  `EnTetesSecurite`).
- Boutons désactivés pendant l'envoi (`form.processing`).
- Après modification d'un écran : `npm run build` et committer `public/build`.

## Livraison

Branche de travail, demande de fusion, suite de tests verte et revue avant
fusion dans `main`.
