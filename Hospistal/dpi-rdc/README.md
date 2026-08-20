# DPI-RDC — Dossier Patient Informatisé

Système hospitalier **offline-first** pour réseaux multi-établissements en République Démocratique du Congo.

## Stack

- **Laravel 13** + **Livewire 4** + **Alpine.js** + **Tailwind CSS 4**
- **PostgreSQL 16** (JSONB, pg_trgm, full-text)
- **Redis** + **Horizon** (synchronisation)
- **Docker Compose** (une stack par établissement)
- **PWA** (Service Worker + Background Sync)

## Démarrage rapide (Docker)

```bash
cd dpi-rdc
cp .env.example .env
# Éditer .env : DB_PASSWORD, ESTABLISHMENT_CODE, etc.
chmod +x deploy.sh backup.sh
./deploy.sh
```

Accès : `http://localhost:8080` — compte seed : `admin@dpi-rdc.local` / `dpi-admin-2024`

## Base de données : administrer avec pgAdmin, DBeaver ou psql

Deux façons de faire, au choix.

### 1. Garder la base du conteneur et s'y brancher (le plus simple)

La base du conteneur est publiée sur le poste au port **5433**, pour laisser
le 5432 à un PostgreSQL déjà installé (par exemple PostgreSQL 18.6 sous
Windows). Rien à changer : après `docker compose up -d`, on se connecte avec

| Champ         | Valeur                            |
|---------------|-----------------------------------|
| Hôte          | `localhost`                       |
| Port          | `5433` (variable `DPI_DB_PORT`)   |
| Base          | `dpi_<ESTABLISHMENT_CODE>`        |
| Utilisateur   | `dpi_user`                        |
| Mot de passe  | la valeur de `DB_PASSWORD` du `.env` |

Adminer est également servi sur `http://localhost:8081`.

### 2. Faire tourner l'application sur le PostgreSQL du poste

L'application utilise alors le serveur installé sous Windows, et le conteneur
`db` ne démarre plus. Dans le `.env` :

```
DPI_DB_HOST=host.docker.internal
DPI_DB_PORT_APP=5432
```

puis

```bash
docker compose -f docker-compose.yml -f docker-compose.pg-hote.yml up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

Les prérequis côté PostgreSQL de Windows (rôle, base, `listen_addresses`,
`pg_hba.conf`) sont détaillés en tête de `docker-compose.pg-hote.yml`.

> À noter : le schéma est écrit pour PostgreSQL 14 et suit ; la version 18.6
> le fait tourner sans réserve. Les deux montages ne partagent pas les mêmes
> données — passer de l'un à l'autre demande un `pg_dump` / `pg_restore`.

## Commandes utiles

```bash
# Token offline (48h) pour PWA
docker compose exec app php artisan dpi:offline-token admin@dpi-rdc.local

# Synchronisation manuelle vers le central
docker compose exec app php artisan queue:work --once

# Horizon (monitoring queues)
# http://localhost/horizon
```

## Architecture

| Niveau | Rôle |
|--------|------|
| **Central (Kinshasa)** | MPI national, agrégation épidémiologique, réception sync |
| **Local (par hôpital)** | Stack Docker autonome, 100% offline |
| **Clients** | PWA Chrome/Android, cache + background sync |

## Migrations

Schéma complet : établissements, patients (MPI), séjours, consultations, pharmacie, laboratoire, facturation, sync_queue, audit_logs.

```bash
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --force
```

## Phase 1 — Statut

- [x] Projet Laravel + packages
- [x] Docker Compose (app, db, redis, horizon, scheduler, nginx)
- [x] Migrations PostgreSQL complètes
- [x] RBAC Spatie (9 rôles)
- [x] Service Worker PWA
- [x] Job SyncToCentral + API sync
- [x] Audit trail (observers)
- [x] Token offline JWT
- [x] CI/CD GitHub Actions
- [ ] Modules métier complets (consultation wizard, pharmacie, labo, caisse)
- [ ] Interface résolution conflits sync
- [ ] Export DHIS2

## Développement local

PHP et Composer ne sont pas requis sur l'hôte — tout passe par Docker :

```bash
docker compose run --rm app php artisan <commande>
docker compose run --rm app composer require <package>
```
