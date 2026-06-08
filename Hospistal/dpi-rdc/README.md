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

Accès : `http://localhost` — compte seed : `admin@dpi-rdc.local` / `dpi-admin-2024`

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
