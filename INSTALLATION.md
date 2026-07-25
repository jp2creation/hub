# Installation JP2 Hub

Ce document decrit l'installation locale et les commandes utiles pour preparer une mise en production.

## Prerequis

- Docker Desktop ou Podman compatible Docker Compose pour le mode recommande avec Laravel Sail.
- PHP 8.3 ou superieur avec les extensions usuelles Laravel si vous travaillez hors container.
- Composer, Node.js et npm.
- MySQL ou MariaDB.
- Git.
- Un serveur web compatible Laravel pour la production.

Pour l'application mobile :

- Node.js et npm dans `mobile/`.
- Android Studio pour Android.
- Xcode sur macOS pour iOS.

## Installation locale recommandee avec Sail

Depuis la racine du projet :

```bash
cp .env.example .env
composer install
vendor/bin/sail up -d
```

Configurer ensuite `.env` pour Sail :

```env
APP_NAME="JP2 Hub"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=hub
DB_USERNAME=sail
DB_PASSWORD=password

CRM_ADMIN_EMAIL=admin@example.test
CRM_ADMIN_NAME=Administrateur

SANCTUM_MOBILE_TOKEN_EXPIRATION_DAYS=365
```

Generer la cle Laravel, migrer et initialiser les donnees :

```bash
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate
vendor/bin/sail artisan db:seed
```

Installer et compiler les assets :

```bash
vendor/bin/sail npm install
vendor/bin/sail npm run build
vendor/bin/sail artisan crm:publish-static-assets --force --clean
vendor/bin/sail artisan crm:publish-module-assets --force
```

Creer ou mettre a jour le compte admin HUB avec une saisie masquee :

```bash
vendor/bin/sail artisan crm:admin --email=admin@example.test --name="Administrateur"
```

L'application est disponible sur `http://localhost` et Mailpit sur `http://localhost:8025`.

Pendant le developpement, lancer Vite :

```bash
vendor/bin/sail npm run dev
```

## Installation locale sans container

Depuis la racine du projet :

```bash
composer install
npm install
```

Creer le fichier d'environnement :

```bash
cp .env.example .env
```

Sur Windows PowerShell :

```powershell
Copy-Item .env.example .env
```

Configurer ensuite `.env` :

```env
APP_NAME="JP2 Hub"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hub
DB_USERNAME=root
DB_PASSWORD=

CRM_ADMIN_EMAIL=admin@example.test
CRM_ADMIN_NAME=Administrateur

SANCTUM_MOBILE_TOKEN_EXPIRATION_DAYS=365
```

Generer la cle Laravel :

```bash
php artisan key:generate
```

Creer la base de donnees MySQL/MariaDB si elle n'existe pas encore, puis executer les migrations :

```bash
php artisan migrate
```

Initialiser les roles de base :

```bash
php artisan db:seed
```

Creer ou mettre a jour le compte admin HUB avec une saisie masquee :

```bash
php artisan crm:admin --email=admin@example.test --name="Administrateur"
```

En deploiement non interactif, utiliser une variable temporaire du shell, sans l'ajouter a `.env` :

```powershell
$env:CRM_ADMIN_TMP='MotDePasseFort-2026!'
php artisan crm:admin --email=admin@example.test --name="Administrateur" --password-env=CRM_ADMIN_TMP
Remove-Item Env:\CRM_ADMIN_TMP
```

Compiler les assets :

```bash
npm run build
php artisan crm:publish-static-assets --force --clean
php artisan crm:publish-module-assets --force
```

Lancer le serveur local :

```bash
php artisan serve
```

L'application sera disponible sur `http://127.0.0.1:8000`.

## Developpement

Backend Laravel :

```bash
php artisan serve
```

Frontend Vite :

```bash
npm run dev
```

Le script Composer `dev` lance aussi serveur, queue, logs et Vite via `concurrently` :

```bash
composer run dev
```

En Sail, prefixer les commandes :

```bash
vendor/bin/sail composer dev
vendor/bin/sail artisan route:list --path=api
vendor/bin/sail artisan optimize:clear
```

## Tests et qualite

Executer tous les tests :

```bash
vendor/bin/sail artisan test --compact
```

Executer les tests HUB les plus courants :

```bash
vendor/bin/sail artisan test --compact tests/Feature/CrmReservationApiTest.php tests/Feature/CrmEquipmentRentalApiTest.php tests/Feature/CrmLeaveApiTest.php tests/Feature/CrmCashControlApiTest.php tests/Feature/CrmCheckRemittanceApiTest.php tests/Feature/CrmDepositRequestApiTest.php tests/Feature/CrmDocumentsApiTest.php tests/Feature/CrmSalesToursApiTest.php
```

Verifier le style PHP :

```bash
vendor/bin/sail composer pint
```

Verifier la compilation frontend :

```bash
vendor/bin/sail npm run build
vendor/bin/sail artisan crm:publish-module-assets --force
```

## Application mobile

Le client mobile se trouve dans `mobile/`.

```bash
cd mobile
npm install
cp .env.example .env
npm run dev
```

Configurer l'URL de l'API si elle n'est pas locale :

```env
VITE_API_BASE_URL=https://hub.example.com
```

Build et synchronisation Capacitor :

```bash
npm run build
npm run cap:sync
```

Ouvrir Android Studio :

```bash
npm run cap:open:android
```

Ouvrir Xcode sur macOS :

```bash
npm run cap:open:ios
```

## Preparation production

La procedure de production doit passer par le deploiement atomique documente dans [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Le document root du domaine doit pointer vers `current/public`, tandis que `.env` et `storage` restent dans `shared/`.

Depuis le poste de deploiement :

```bash
make deploy-check
make deploy
```

Le script construit les assets localement, prepare une nouvelle release sur le serveur, lance Composer et les migrations hors ligne, bascule `current`, verifie `/up`, puis termine Horizon pour que les workers reprennent le nouveau code.

Verifier ensuite :

- `/login`
- `/`
- `/reservations`
- `/locations-materiel`
- `/conges`
- `/rapport-visite`
- `/controle-caisse`
- `/demandes-acompte`
- `/remise-cheques`
- `/documents/promo`
- `/tapis-romus`
- `/pages-crm` (route historique des pages HUB)
- `/admin`
- `/api/conges?action=bootstrap`
- `/api/rapport-visite?action=health`
- `/api/mobile/me` avec un token Sanctum valide

## Variables importantes

- `APP_URL` : URL publique de l'application.
- `DB_*` : connexion MySQL/MariaDB.
- `CRM_ADMIN_PASSWORD_MIN`, `CRM_ADMIN_HASH_ROUNDS` : politique de mot de passe admin.
- `CRM_API_THROTTLE_PER_MINUTE` : limite minute des API HUB Laravel.
- `CRM_LOGIN_THROTTLE_PER_MINUTE` : limite minute du login web et du token mobile.
- `CRM_RESPONSE_COMPRESSION_*` : activation, taille minimale et niveau gzip des reponses API.
- `CRM_BACKUP_DISK`, `CRM_BACKUP_PATH`, `CRM_BACKUP_KEEP` : destination et retention des sauvegardes SQL.
- `CRM_TRUST_LARAVEL_SESSION` : autorise les API HUB a utiliser la session Laravel.
- `CRM_ALLOW_LEGACY_ACTOR_IMPERSONATION` : compatibilite legacy, a garder desactivee sauf besoin controle.
- `SANCTUM_MOBILE_TOKEN_EXPIRATION_DAYS` : duree des tokens mobiles.
- `CORS_ALLOWED_ORIGINS` : origins autorisees pour l'API mobile et les appels `api/*`.

## CI/CD

Le workflow GitHub Actions `.github/workflows/ci.yml` execute a chaque push ou pull request :

- installation Composer et npm
- Pint en mode verification
- build Vite
- tests Laravel sur PHP 8.3.

## Depannage

Vider les caches Laravel :

```bash
vendor/bin/sail artisan optimize:clear
```

Regenerer l'autoload Composer :

```bash
vendor/bin/sail composer dump-autoload
```

Verifier les migrations :

```bash
vendor/bin/sail artisan migrate:status
```

Relancer une compilation propre :

```bash
vendor/bin/sail npm run build
```

Les logs Laravel sont dans `storage/logs/laravel.log`.
