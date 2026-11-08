# Scénario de démonstration MediHome

Ce scénario parcourt tout ce qui fonctionne aujourd'hui : comptes, demande de
consultation, rendez-vous, paiement, caisse et remboursements. Il a été
déroulé en entier dans un navigateur sur une base neuve, sans erreur.

Aucun argent ne circule : le paiement est **simulé** (pilote « simulation »,
interdit hors du poste de développement). Aucun SMS n'est envoyé : chaque SMS
s'écrit dans le journal.

Durée : 20 à 30 minutes. Téléphone en main pour l'application
d'authentification (Google Authenticator, Microsoft Authenticator…).

## Démonstration express : tout voir en 5 minutes

Une seule commande prépare, sur le poste local seulement, des comptes du
personnel déjà activés et des données fictives à toutes les étapes :

```bash
docker compose exec app php artisan demo:preparer
```

- Personnel : <http://localhost:8097/personnel/connexion>, mot de passe
  `MediHome-demo-2026`, puis le code à 6 chiffres donné par
  `docker compose exec app php artisan demo:code` (valable 30 secondes).
  Comptes : `admin.demo`, `admin2.demo`, `medecin.demo`, `pediatre.demo`,
  `caisse1.demo`, `caisse2.demo` (tous `@doc-and-i.local`).
- Patients : <http://localhost:8097/connexion>, numéros `0810000101` (Grâce :
  consultation terminée hier avec note signée et ordonnance, et une
  consultation qui commence dans 5 minutes), `0810000102` (Jean-Paul :
  remboursement à valider par la caisse), `0810000103` (Esther : pédiatrie
  demain). Le code SMS : `docker compose exec app tail -n 5 storage/logs/laravel.log`.

Chaque rôle a son espace : l'administrateur ne voit pas les dossiers
médicaux (secret médical) ; pour voir le dossier, connectez-vous en médecin
ou en patient. Une fenêtre de navigation privée par compte.

La suite de ce document détaille le parcours complet pas à pas.

## 0. Préparer (une fois)

Dans `Hospistal\doc-and-i` :

```bash
git pull origin main
docker compose up -d --build
```

Fenêtre « journal » à garder ouverte (codes SMS et SMS envoyés) :

```bash
docker compose exec app tail -f storage/logs/laravel.log
```

Comptes de démonstration déjà créés (données fictives) :

| Rôle | Compte |
| --- | --- |
| Médecin (médecine générale) | `medecin.demo@doc-and-i.local` |
| Médecin (pédiatrie) | `pediatre.demo@doc-and-i.local` |
| Caisse, agent 1 | `caisse1.demo@doc-and-i.local` |
| Caisse, agent 2 | `caisse2.demo@doc-and-i.local` |

Chaque compte du personnel s'active par un lien à usage unique (72 heures) :

```bash
docker compose exec app php artisan personnel:lien-activation medecin.demo@doc-and-i.local
docker compose exec app php artisan personnel:lien-activation caisse1.demo@doc-and-i.local
docker compose exec app php artisan personnel:lien-activation caisse2.demo@doc-and-i.local
```

Ouvrir chaque lien (remplacer l'adresse par `http://localhost:8097` si besoin) :
choisir un mot de passe (12 caractères au moins), scanner le QR code avec
l'application d'authentification, saisir le code à 6 chiffres, noter les
codes de récupération. **Une fenêtre de navigation privée par personne**
(patient, médecin, caisse 1, caisse 2) : chacun a sa propre session.

## 1. Le patient s'inscrit (CPT-01, CPT-02, CPT-04)

1. <http://localhost:8097> → « Commencer ».
2. Numéro `0812345679` → « Recevoir un code ». Le code est dans le journal
   (« MEDIHOME : votre code de connexion est … »).
3. Profil : nom, prénom, sexe, date de naissance → « Enregistrer ».
4. Consentement : cocher les deux cases → « J'accepte ».

**À montrer** : pas de mot de passe pour le patient ; le consentement est
horodaté et révocable.

## 2. Il demande une consultation et réserve (RDV-01 à RDV-05)

1. « Demander une consultation » : motif (« Toux et fièvre depuis trois
   jours »), puis répondre à **chaque** question de tri (« non »). Le bouton
   reste grisé tant qu'une question est sans réponse.
2. Choisir « Médecine générale », un type de consultation, « Voir les
   créneaux libres ».
3. Choisir un créneau **d'un autre jour** (plus de 24 h : remboursement total
   à l'annulation) → « Réserver ce créneau ».

**À montrer** : le créneau est bloqué 15 minutes en attente de paiement.
Répondre « oui » à une question d'alerte (douleur thoracique…) affiche à la
place la conduite d'urgence, sans rendez-vous.

## 3. Il paie (PAY-02 à PAY-05, RG-01, RG-02)

1. « Payer 10,00 USD » → M-Pesa, numéro prérempli → « Payer ».
2. Page d'attente : « Simuler : paiement réussi » (cadre « Simulation »).
3. Le rendez-vous passe à « confirmé ». SMS dans le journal :
   « MEDIHOME : paiement de 10,00 USD reçu (reçu FA-2026-000001)… ».
4. « Mes paiements » → télécharger le reçu en PDF.

**À montrer** : seul le message signé de l'opérateur confirme ; le navigateur
ne confirme rien. Un double clic ne débite qu'une fois. Le SMS ne contient
aucune donnée de santé.

## 4. Le médecin voit son agenda (RDV-04, CPT-05, CPT-06)

Fenêtre du médecin : <http://localhost:8097/personnel/connexion>, courriel,
mot de passe, code de l'application. « Agenda » : le rendez-vous confirmé,
avec le nom du patient et le motif. Montrer aussi « Disponibilités » et
« Absences ».

## 5. La caisse saisit le taux du jour (PAY-01)

Fenêtre caisse 1 : <http://localhost:8097/personnel/caisse> → « Taux du
jour » → `2850` → « Enregistrer le taux du jour ».

**À montrer** : bornes de 500 à 10 000 ; au-delà de 10 % d'écart avec la
veille, un second agent doit confirmer.

## 6. Second rendez-vous payé en francs congolais

Fenêtre patient : nouvelle demande (« Maux de tête »), réserver, « Payer » :
choisir **CDF** → 28 500 CDF au taux du jour → simuler le paiement réussi.

## 7. Le patient annule, la caisse rembourse (RG-04, PAY-07, PAY-08)

1. Patient : premier rendez-vous → « Annuler ce rendez-vous ». Le message
   annonce le remboursement total → « Oui, annuler ».
2. Caisse 1 : « Remboursements » → la demande (« Système, règle
   d'annulation ») → « Valider ». Elle part chez l'opérateur.
3. « Simuler : remboursement réussi ». SMS : « MEDIHOME : remboursement de
   10,00 USD envoyé sur votre compte M-Pesa. »
4. Patient : « Mes paiements » → l'avoir AV-… apparaît.

## 8. Geste commercial à quatre yeux (PAY-07)

1. Caisse 1 : « Remboursements » → chercher la facture en CDF (FA-…-000002)
   → montant `5000`, motif « Attente prolongée » → « Demander ».
   **Caisse 1 n'a pas de bouton « Valider » sur sa propre demande.**
2. Caisse 2 : « Remboursements » → « Valider » → « Simuler : remboursement
   réussi ».

## 9. Journal de caisse (PAY-09)

Caisse : « Journal » → totaux du jour **par devise** (USD et CDF jamais
additionnés), par moyen de paiement et par médecin. « Factures » : recherche
par numéro, PDF.

## Ce qui ne se montre pas encore

- **Reversement des honoraires** : il porte sur des jours clos (au plus tard
  la veille) et des consultations dont l'heure est passée. Il se montre le
  lendemain d'une consultation payée.
- **Rapprochement** : tâche de nuit (`php artisan paiement:rapprocher`) ;
  avec la simulation, il ne trouve normalement aucun écart.
- **Pas encore construits** : la consultation vidéo, le dossier médical,
  l'ordonnance et les résultats, le laboratoire, l'espace administrateur.
