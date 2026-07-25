# Deploiement HUB

Ce projet doit etre deploye depuis le depot Git local, pas par modification directe de fichiers minifies sur le serveur.

## Verification locale

```bash
composer install --no-interaction
npm install
composer quality
npm run build
php artisan crm:publish-static-assets --force --clean
php artisan crm:publish-module-assets --force
composer audit
npm audit --audit-level=moderate
```

## Mise en production atomique

Le serveur doit utiliser une racine de deploiement stable avec trois dossiers :

```text
hub/
|-- current -> releases/20260720093000-abc123
|-- releases/
|   |-- 20260720093000-abc123/
|   `-- 20260719080000-def456/
`-- shared/
    |-- .env
    `-- storage/
```

Le document root du domaine doit pointer vers `hub/current/public`. Les fichiers persistants restent dans `shared/.env` et `shared/storage`, puis chaque release les reference avec des symlinks.

Le flux de production est :

1. Construire l'archive depuis le depot local.
2. Creer `releases/<timestamp>-<sha>` sur le serveur.
3. Extraire l'archive dans cette nouvelle release, sans toucher a `current`.
4. Relier `.env` et `storage` depuis `shared`.
5. Installer Composer dans la release inactive.
6. Lancer `php artisan optimize:clear`, `migrate --force`, `storage:link --force`, `crm:publish-static-assets --force --clean`, `crm:publish-module-assets --force`, `optimize` et `view:cache`.
7. Basculer atomiquement `current` vers la nouvelle release.
8. Verifier automatiquement `/up`.
9. En cas d'echec HTTP, revenir automatiquement au symlink precedent.
10. Lancer `php artisan horizon:terminate` et `queue:restart`.
11. Supprimer les anciennes releases au-dela de la retention.

Les migrations doivent rester compatibles avec l'ancienne version deja servie, car elles sont executees avant le basculement du symlink. Ne pas supprimer ou renommer brutalement une colonne utilisee par la release precedente sans deploiement en deux temps.

Les migrations HUB sont chargees depuis `Modules/*/database/migrations`. Avant d'envoyer une release, verifier qu'aucune migration HUB ne reste dans `database/migrations` :

```bash
php artisan test --filter=CrmModuleManifestTest
```

Verifier le scheduler et declencher un backup de controle si besoin :

```bash
php artisan schedule:list
php artisan backup:run --verify
```

## Monitoring et alertes

Le monitoring applicatif se fait sur deux niveaux :

- `laravel/telescope` est installe pour le developpement local uniquement. Activer au besoin `TELESCOPE_ENABLED=true` en local, puis ouvrir `/telescope`. Ne pas l'activer en production.
- `sentry/sentry-laravel` est integre au gestionnaire d'exceptions Laravel. En production, renseigner au minimum :

```dotenv
SENTRY_LARAVEL_DSN=https://...
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=<sha-ou-version>
SENTRY_SEND_DEFAULT_PII=false
TELESCOPE_ENABLED=false
CRM_FAILED_LOGIN_ALERT_THRESHOLD=5
CRM_FAILED_LOGIN_ALERT_WINDOW_MINUTES=15
CRM_FAILED_LOGIN_ALERT_COOLDOWN_MINUTES=30
CRM_MONITORING_DASHBOARD_ALERT_MINUTES=60
```

Les alertes internes sont tracees dans `notification_logs` avec `channel=monitoring`.
Elles couvrent actuellement :

- `backup.database.failed` lorsqu'une sauvegarde echoue ;
- `queue.size.threshold` lorsque la queue depasse le seuil configure ;
- `auth.login.failed` lorsqu'une adresse/IP depasse le seuil de connexions echouees.

Le dashboard affiche les alertes monitoring recentes selon `CRM_MONITORING_DASHBOARD_ALERT_MINUTES`.
Les donnees sensibles des tentatives de connexion ne sont pas stockees en clair : le mot de passe est ignore et l'e-mail est conserve sous forme de hash avec un indice masque.

En production MySQL/MariaDB, la commande `backup:run` attend `mariadb-dump` ou `mysqldump`.
Configurer `CRM_BACKUP_DUMP_BINARY` si le binaire n'est pas dans le `PATH`.
Pour sortir les sauvegardes du serveur, utiliser un disque Laravel externe :

```dotenv
CRM_BACKUP_DISK=s3
CRM_BACKUP_PATH=crm/database
CRM_BACKUP_KEEP=14
CRM_BACKUP_KEEP_WEEKLY=8
CRM_BACKUP_KEEP_MONTHLY=12
CRM_BACKUP_ENCRYPT=true
CRM_BACKUP_ENCRYPTION_KEY="cle-longue-hors-git"
```

Planifier un test mensuel de restauration sur une base temporaire : recuperer la derniere archive, la dechiffrer si besoin, la decompresser, l'importer dans une base isolee, puis valider les migrations et les parcours critiques. Une sauvegarde non restauree n'est pas encore une vraie sauvegarde.

## Feature flags production

Les modules peuvent etre actives ou desactives sans redeploiement via la table `crm_feature_flags`.

```bash
php artisan crm:feature --list
php artisan crm:feature module:locations-materiel --disable
php artisan crm:feature module:locations-materiel --enable
php artisan optimize:clear
```

Les flags de module utilisent la forme `module:<slug>`. Une desactivation retire le module des references actives, du menu et des routes protegees par `crm.module`.

## Migration des endpoints legacy

Le retrait des routes API `.php` est documente dans [LEGACY_API_MIGRATION.md](LEGACY_API_MIGRATION.md). En production, ces endpoints ne sont plus routes par Laravel : les clients doivent appeler les routes REST sans extension.

Avant la date de desactivation definitive annoncee aux integrateurs, verifier les tentatives restantes qui atteignent Laravel :

```bash
php artisan crm:audit-legacy-php-api --days=30 --deactivation-date=2026-08-31
```

Si la commande retourne des hits, contacter les integrateurs concernes via les IP/User-Agent affiches et migrer leurs appels vers `/api/<module>` sans extension. Ajouter `--fail-on-hits` dans un controle manuel ou CI pour bloquer un retrait definitif tant que des appels legacy existent.

## Regle importante

Les fichiers dans `public/build`, `public/assets` et `public/modules` sont des sorties de build ou de publication. Toute correction durable doit etre faite dans les sources applicatives, puis reconstruite.

- `public/build` vient de Vite : `npm run build`.
- `public/assets` vient de `resources/frontend/static/assets` : `php artisan crm:publish-static-assets --force --clean`.
- `public/modules` vient de `Modules/*/resources/assets` : `php artisan crm:publish-module-assets --force`.

Adminex n'est plus une dependance runtime a remettre dans `public/assets`. Les elements visuels conserves sont reconstruits proprement dans les sources natives du HUB, notamment `resources/frontend/crm/styles/template-compat/*`, `resources/frontend/crm/styles/native-ui.css` et les assets de modules.

Apres une modification de module, lancer `php artisan crm:publish-module-assets --force` avant de vider les caches.

## Script de deploiement aide

Le depot fournit `make deploy`, qui appelle `scripts/deploy-planethoster.sh`. Le script construit une archive locale, l'envoie en SSH, prepare une nouvelle release, met a jour `CRM_ASSET_VERSION`, lance les migrations et les caches hors ligne, bascule `current`, verifie `/up`, puis termine Horizon pour forcer les workers a reprendre le nouveau code.

Les secrets ne doivent jamais etre stockes dans le depot. Configurer les variables dans le terminal ou dans un gestionnaire local :

```bash
export CRM_DEPLOY_HOST=ssh.example.test
export CRM_DEPLOY_PORT=5022
export CRM_DEPLOY_USER=mon_utilisateur
export CRM_DEPLOY_ROOT=/home/mon_utilisateur/hub
export CRM_DEPLOY_COMPOSER=/home/mon_utilisateur/bin/composer
export CRM_DEPLOY_HEALTH_URL=https://hub.example.test/up

make deploy-check
make deploy
```

Options utiles :

- `CRM_DEPLOY_BUILD=0` pour sauter `npm run build` si les assets sont deja prets.
- `CRM_DEPLOY_ALLOW_DIRTY=1` pour deployer une copie locale non commitee, uniquement en urgence.
- `CRM_DEPLOY_TMP_DIR=/home/mon_utilisateur` pour choisir le dossier temporaire distant.
- `CRM_DEPLOY_ROOT=/home/mon_utilisateur/hub` est le chemin recommande. `CRM_DEPLOY_PATH` reste accepte par compatibilite avec les anciens scripts locaux.
- `CRM_DEPLOY_HEALTH_URL=https://hub.example.test/up` pour fixer l'URL de verification. Sans cette variable, le script utilise `APP_URL` dans `shared/.env` et ajoute `/up`.
- `CRM_DEPLOY_KEEP_RELEASES=3` pour garder les trois dernieres releases.
- `CRM_DEPLOY_SKIP_HEALTHCHECK=1` uniquement en urgence si le serveur ne peut pas joindre son propre domaine.

Pour une premiere migration depuis l'ancien deploiement non atomique, creer `shared/.env` et `shared/storage` avant le premier `make deploy`, puis configurer le document root du domaine vers `current/public`. Le script peut copier `.env` et `storage` depuis l'ancien dossier si ces elements existent dans `CRM_DEPLOY_PATH`, mais cette transition doit etre faite dans une fenetre controlee.
