# Standard des modules CRM

Ce projet utilise des modules Laravel sous `Modules/Crm*`. Un nouveau module doit rester lisible, testable et deconnecte des autres modules.

Le guide pas a pas de creation d'un module est disponible dans [MODULE_CREATION_GUIDE.md](MODULE_CREATION_GUIDE.md).

## Structure minimale

- `module.json` avec `name`, `alias`, `priority` et `providers`.
- `app/Providers/<Module>ServiceProvider.php` pour charger les routes et les listeners du module.
- `routes/web.php` pour les pages HUB et les routes `/api/<module>` sans extension `.php`.
- `database/migrations` pour les migrations versionnees du module.
- `app/Services` pour la logique metier. Les controleurs doivent rester minces.
- `app/Data` pour les DTO qui normalisent les payloads entrants.
- `tests/Feature` pour les parcours API et UI critiques.

Les classes PHP propres au module doivent rester sous `app/`. Les anciens dossiers racine `Providers`, `Http` ou `Services` ne sont plus autorises, afin que tous les modules suivent l'arborescence generee par `nwidart/laravel-modules`.

## Creation

Creer d'abord le squelette avec Artisan :

```bash
php artisan module:make CrmExample
```

La configuration de generation est centralisee dans `config/modules.php` : chemins `app/`, `routes/`, `database/`, `resources/`, auteur Composer et fichier `modules_statuses.json`. Apres generation, adapter le provider pour etendre `Modules\CrmCore\Providers\CrmModuleServiceProvider`, puis declarer la priorite dans `module.json`.

## Conventions API

- Les routes de production utilisent `/api/<module>`.
- Aucun alias API avec extension `.php` ne doit etre ajoute. Les anciens chemins `/api/*.php` doivent retourner `404`.
- Les controleurs API recoivent `Modules\CrmCore\Http\Requests\CrmApiRequest`.
- Les actions complexes convertissent le payload avec un DTO avant d'appeler le service.

## Autorisations

La separation des droits est decrite dans [CRM_AUTHORIZATION.md](CRM_AUTHORIZATION.md).

- Spatie Permission couvre la plateforme : Filament, Horizon et l'exception admin des pages shell CRM.
- `CrmAccessService` couvre les droits metier : modules, sites et permissions contextuelles.
- Les controleurs ne doivent pas porter de decision metier finale. Ils appellent un service ou une Policy.
- Les exceptions admin doivent utiliser `User::canUsePlatformAdministration()` ou `CrmAccessService`, pas une liste de roles locale.

## Evenements

Les modules publient les actions importantes via `Modules\CrmCore\Events\CrmDomainEvent`.

Exemple :

```php
CrmDomainEvent::dispatch(
    module: 'reservations',
    name: 'deleted',
    entity: 'reservation',
    entityId: $reservation->id,
    siteId: $reservation->site_id,
    actorId: $actor->id,
    actorName: $actor->name,
    payload: ['title' => $reservation->title],
);
```

Les modules qui veulent reagir a un evenement enregistrent leur listener dans leur provider. Cela evite les appels directs entre modules.

## Migrations

- Les migrations CRM restent dans `Modules/<Module>/database/migrations`.
- Les migrations Laravel globales et les migrations de packages restent dans `database/migrations`.
- Le provider de chaque module etend `Modules\CrmCore\Providers\CrmModuleServiceProvider`, qui charge automatiquement `routes/web.php` et `database/migrations`.
- Lors d'un deploiement, `php artisan migrate --force` suffit : Laravel garde les migrations deja executees par leur nom de fichier.

## Feature flags

Chaque module CRM a un flag `module:<slug>` en base dans `crm_feature_flags`.

Commandes utiles :

```bash
php artisan crm:feature --list
php artisan crm:feature module:reservations --disable
php artisan crm:feature module:reservations --enable
```

Un module desactive par feature flag disparait du menu et ses routes protegees par `crm.module` retournent une erreur au lieu de continuer a servir la fonctionnalite.
