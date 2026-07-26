# Migration des endpoints legacy `.php`

Objectif : garder uniquement les routes Laravel sans extension et supprimer toute exposition directe de scripts ou d'alias `/api/*.php`.

## Etat au 21 juillet 2026

- Aucun fichier PHP legacy n'est expose dans `public/`; seul `public/index.php` reste le front controller Laravel.
- Les modules HUB enregistrent uniquement les routes metier `/api/<module>` sans extension.
- Les tentatives legacy qui atteignent Laravel sont journalisees dans `crm_logs` avec l'action `legacy php api blocked`.
- `LegacyPhpApiController`, `AuditLegacyPhpApi`, le rate limiter `crm-legacy-api` et les variables `CRM_LEGACY_PHP_API_*` ont ete supprimes.
- La documentation OpenAPI ne liste plus les chemins `.php`.

## Regle de developpement

Une nouvelle fonctionnalite ne doit jamais ajouter de route API metier avec extension `.php`. Aucune route Laravel `.php` ne doit etre enregistree dans `route:list`.

Si une integration externe demande un ancien chemin, il faut migrer le client vers la route Laravel moderne correspondante :

```text
/api/reservations.php?action=health  ->  /api/reservations?action=health
/api/conges.php?action=bootstrap     ->  /api/conges?action=bootstrap
```

## Commandes de controle

```bash
find public -maxdepth 5 -type f -name '*.php' -print
rg -n "/api/[A-Za-z0-9_-]+\\.php|LegacyPhpApiController|crm-legacy-api|legacy_php_api" app bootstrap config Modules resources tests docs
php artisan route:list | rg "/api/.*\\.php|crm-legacy-api|LegacyPhpApiController"
php artisan hub:audit-legacy-php-api --days=30 --deactivation-date=2026-08-31
php artisan test --filter=CrmSecurityTest
```

La seule sortie attendue pour `find public ...` est :

```text
public/index.php
```

`route:list` ne doit retourner aucune route API en `.php`.

## Verification production

Avant de supprimer toute tolerance restante autour des anciens clients, lancer :

```bash
php artisan hub:audit-legacy-php-api --days=30 --deactivation-date=2026-08-31
```

- Si la commande affiche `Aucune tentative legacy`, aucun appel `/api/*.php` n'a atteint Laravel sur la periode analysee.
- Si des lignes apparaissent, utiliser les colonnes IP et User-Agent pour identifier les integrateurs, puis leur communiquer les routes modernes sans extension et la date de desactivation definitive.
- En CI ou dans un controle de deploiement, ajouter `--fail-on-hits` pour faire echouer le controle tant que des appels legacy sont observes.

Les regles Apache et `public/index.php` peuvent bloquer certains chemins avant Laravel. Ces requetes n'apparaitront pas dans `crm_logs`; pour un audit exhaustif, croiser la commande avec les access logs de Planethoster sur le motif `/api/*.php`.
