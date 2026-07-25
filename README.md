# JP2 Hub

JP2 Hub est une base Laravel modulaire concue par JP2 Creation pour centraliser des outils operationnels multi-site : portail web, tableau de bord, reservations, locations de materiel, conges, comptabilite, documents, rapports de visite, generation PDF et API mobile.

L'installation Martin Sols presente dans ce depot est l'exemple metier actuellement utilise pour valider le produit et ses modules.

Le produit s'appelle **JP2 Hub**. Les prefixes techniques historiques `crm:*`,
`CRM_*`, `Crm*` et certaines routes internes restent conserves pour compatibilite
avec le code existant, les migrations et les scripts de deploiement.

## Objectif

Ce depot contient l'application Laravel qui remplace les anciens endpoints PHP disperses par une base applicative versionnee, testable et extensible. L'interface principale sert aux equipes internes, tandis que l'administration Filament permet de maintenir les donnees de reference, les droits et les contenus.

## Licence

Ce depot est publie en **source disponible**, pas sous licence open source OSI.
Vous pouvez consulter, cloner, tester et contribuer au code pour un usage
personnel ou d'evaluation. Tout usage professionnel, commercial, client,
production, hebergement, revente, distribution ou exploitation d'une version
modifiee demande l'accord ecrit prealable de Jean-Philippe DEGERT / JP2
Creation.

Voir [LICENSE.md](LICENSE.md).

## Fonctionnalites principales

- Authentification web Laravel, portail HUB protege et PWA installable.
- Tableau de bord multi-site avec cartes, alertes, dernieres reservations, conges en cours et notifications.
- Gestion des reservations vehicules avec planning, conflits et droits par utilisateur/site.
- Gestion des locations de materiel avec cartes visuelles, categories, demi-journee ou journee, planning et disponibilites.
- Module Conges & Absences : demande utilisateur, validation direction, calendrier personnel, planning equipe, soldes, rapports et export PDF.
- Module Rapport de visite pour tournees commerciales, visites clients, comptes rendus et actions de suivi.
- Comptabilite : controle caisse, demandes d'acompte, remises de cheques et lien Addvance.
- Remises de cheques avec photo, detection assistee, controle signature/destinataire, total et export PDF.
- Controle caisse avec comptage especes, encaissements, ecarts, justificatifs et PDF incluant les numeros de facture.
- Documents internes : Promo, Fiches techniques et Procedures avec bibliotheque par site.
- Module Tapis ROMUS integre au HUB avec rendu harmonise et generation PDF.
- Pilotage commercial et Stats : tableaux de bord Filament, KPIs et synchronisation possible avec une API de facturation externe.
- Pages HUB administrables et accessibles via slugs.
- Administration Filament pour utilisateurs, roles, modules, menus, sites, vehicules, materiel et contenus.
- API Laravel sans extension `.php`, avec audit des tentatives legacy bloquees.
- API mobile Laravel Sanctum pour l'application Capacitor du dossier `mobile/`.

## Stack technique

- PHP 8.3+
- Laravel 13
- Filament 5
- Laravel Sanctum
- Spatie Laravel Permission
- MySQL ou MariaDB
- Node.js, npm, Vite et Tailwind CSS
- PHPUnit pour les tests backend
- Capacitor pour le client mobile

## Acces applicatifs

- Portail HUB : `/`
- Connexion : `/login`
- Administration : `/admin`
- Tableau de bord : `/`
- Reservations vehicules : `/reservations`
- Location materiel : `/locations-materiel`
- Conges & Absences : `/conges`
- Rapport de visite : `/rapport-visite`
- Controle caisse : `/controle-caisse`
- Demandes d'acompte : `/demandes-acompte`
- Remise de cheques : `/remise-cheques`
- Documents : `/documents/promo`, `/documents/fiches-techniques`, `/documents/procedures`
- Tapis ROMUS : `/tapis-romus`
- Pages HUB : `/pages-crm` (route historique conservee)
- API reservations : `/api/reservations`
- API locations de materiel : `/api/equipment-rentals`
- API conges : `/api/conges`
- API comptabilite : `/api/controle-caisse`, `/api/demandes-acompte`, `/api/remise-cheques`
- API documents : `/api/documents`
- API rapport de visite : `/api/rapport-visite`
- API pages : `/api/pages`
- API mobile : `/api/mobile/token`, `/api/mobile/me`, `/api/mobile/logout`

Les anciens chemins `.php`, par exemple `/api/conges.php`, sont bloques. Les integrateurs doivent utiliser les routes modernes sans extension, par exemple `/api/conges`.

## Structure du depot

- `Modules/` : modules Laravel metiers decoupes par domaine metier.
- `app/Models/` : modeles Eloquent partages.
- `database/migrations/` : schema Laravel global et migrations de packages.
- `Modules/*/database/migrations/` : migrations metier versionnees par module.
- `resources/views/` : vues HUB, login, erreurs et shell applicatif.
- `resources/frontend/crm/` : shell HUB Vite/React, navigation, PWA, loaders, compatibilite visuelle et UI native.
- `Modules/*/resources/assets/` : sources frontend propres aux modules.
- `public/build/`, `public/assets/` et `public/modules/` : sorties compilees ou publiees pour la production.
- `tests/Feature/` et `tests/Unit/` : tests des API metier, services et securite.
- `mobile/` : application mobile Capacitor connectee a l'API Sanctum.
- `docs/` : documentation technique, guide utilisateur, plaquette HUB et notes de deploiement.

## Installation

Les instructions completes sont dans [INSTALLATION.md](INSTALLATION.md).
Le mode local recommande utilise Laravel Sail :

```bash
cp .env.example .env
composer install
vendor/bin/sail up -d
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate
vendor/bin/sail npm install
vendor/bin/sail npm run build
vendor/bin/sail artisan crm:publish-static-assets --force --clean
vendor/bin/sail artisan crm:publish-module-assets --force
```

Creer ou mettre a jour le compte admin avec une saisie masquee :

```bash
vendor/bin/sail artisan crm:admin --email=admin@example.test --name="Administrateur"
```

Pour Sail, mettre `DB_HOST=mysql`, `DB_USERNAME=sail` et `DB_PASSWORD=password` dans le `.env` local, puis ouvrir `http://localhost`.

## Verification

```bash
vendor/bin/sail composer quality
vendor/bin/sail npm run build
vendor/bin/sail artisan crm:publish-module-assets --force
```

Des tests cibles existent notamment pour les reservations, les locations de materiel, les conges, le controle caisse, les remises de cheques, les demandes d'acompte, les documents, les rapports de visite, les pages HUB, la PWA et l'authentification mobile.

Le depot fournit aussi un Makefile pour les taches courantes :

```bash
make install
make hooks
make quality
make build
make deploy-check
```

`make hooks` active le hook Git versionne `.githooks/pre-commit`. Ce hook lance Laravel Pint sur les fichiers PHP stages avant commit.

## Deploiement

La procedure de reference est dans [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

Regle importante : ne pas modifier directement `public/build`, `public/assets` ou `public/modules`.
Une correction durable doit etre faite dans `resources/frontend`, `Modules/*/resources/assets`, les vues Laravel ou les classes PHP, puis reconstruite/publiee.

## Documentation

- Architecture, schema ER et flux principaux : [docs/README.md](docs/README.md)
- Standard des modules HUB : [docs/HUB_MODULE_STANDARD.md](docs/HUB_MODULE_STANDARD.md)
- Guide de creation d'un module : [docs/MODULE_CREATION_GUIDE.md](docs/MODULE_CREATION_GUIDE.md)
- Guide d'utilisation utilisateur : [docs/guide-utilisation-hub-martin-sols.pdf](docs/guide-utilisation-hub-martin-sols.pdf)
- Plaquette de presentation HUB : [docs/presentation-hub-publicitaire.pdf](docs/presentation-hub-publicitaire.pdf)
- Historique des changements : [CHANGELOG.md](CHANGELOG.md)

## Securite

- Ne jamais commiter `.env`, tokens, exports de base de donnees ou logs.
- Utiliser des mots de passe forts pour les comptes admin.
- Creer l'admin via `php artisan crm:admin`; ne pas stocker de mot de passe admin dans `.env.example`.
- Desactiver les options legacy d'impersonation sauf besoin explicite.
- L'API mobile utilise Sanctum et des tokens Bearer ; leur duree est pilotee par `SANCTUM_MOBILE_TOKEN_EXPIRATION_DAYS`.
- Les actions critiques HUB sont journalisees dans `crm_logs`.
