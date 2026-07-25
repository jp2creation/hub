# CrmStats

Module Filament de pilotage commercial alimente par une API de facturation externe.

## Configuration

Ajouter les variables suivantes cote environnement :

```dotenv
BILLING_API_URL=https://facturation.example.com/api
BILLING_API_KEY=secret
BILLING_API_TIMEOUT=30
BILLING_API_CONNECT_TIMEOUT=5
BILLING_API_RETRY_TIMES=3
BILLING_API_RETRY_SLEEP_MS=250
BILLING_API_PAGE_SIZE=100
CRM_STATS_CACHE_TTL=900
CRM_STATS_LOST_CLIENT_MONTHS=6
```

## Strategie de cache

Le module utilise l option A : une table locale `cached_billing_stats`.
Les pages Filament lisent uniquement cette table, ce qui evite tout appel API lors d un changement de filtre.

## Commandes

```bash
php artisan crm:sync-billing-data --from-date=2026-07-01
php artisan crm:sync-billing-data --full
php artisan crm:stats-cache-clear
php artisan kpis:snapshot --interval=day
```

## Permissions

Le menu Filament est visible pour les administrateurs plateforme ou les utilisateurs ayant la permission Spatie `view_stats`.
La synchronisation manuelle demande `sync_stats`.

## Licence

Ce module fait partie de Martin Sols HUB et suit la licence du depot racine.
Tout usage professionnel, commercial, client, production, hebergement, revente
ou redistribution demande l'accord ecrit prealable de Jean-Philippe DEGERT / JP2
Creation.
