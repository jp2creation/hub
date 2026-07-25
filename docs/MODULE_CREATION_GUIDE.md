# Guide de creation d'un module JP2 Hub

Ce guide sert de checklist pour ajouter un nouveau module Laravel dans JP2 Hub sans casser la structure existante.

Les modules PHP gardent le prefixe technique historique `Crm*` pour ne pas casser
les namespaces, les migrations et les commandes existantes. Cote produit et UI,
le vocabulaire a utiliser est **JP2 Hub**.

## 1. Choisir les noms

Chaque module utilise trois noms stables :

- Nom PHP : `CrmExample`
- Namespace : `Modules\CrmExample`
- Slug HUB : `example`

Le slug est celui utilise par le menu, les feature flags et le middleware `crm.module`.

## 2. Creer l'arborescence

Commencer par le generateur officiel du package :

```bash
php artisan module:make CrmExample
```

Les chemins utilises par cette commande sont centralises dans `config/modules.php`. Ne pas creer un module a la main sauf reprise d'un module existant : cela evite les variantes d'arborescence et garde les providers, routes, migrations et tests au meme endroit.

Structure recommandee :

```text
Modules/CrmExample/
  app/
    Data/
    Http/
      Controllers/
    Providers/
    Services/
  database/
    factories/
    migrations/
    seeders/
  resources/
    assets/
    views/
  routes/
    web.php
  tests/
    Feature/
    Unit/
  module.json
```

Les migrations metier du module restent toujours dans `Modules/CrmExample/database/migrations`. Le dossier racine `database/migrations` est reserve aux migrations Laravel globales et aux packages.

## 3. Declarer `module.json`

Exemple minimal :

```json
{
    "name": "CrmExample",
    "alias": "crmexample",
    "description": "Module HUB Example",
    "keywords": [],
    "priority": 80,
    "providers": [
        "Modules\\CrmExample\\Providers\\CrmExampleServiceProvider"
    ],
    "files": []
}
```

La priorite controle l'ordre de chargement. Les modules de base comme `CrmCore` doivent rester avant les modules metier.

## 4. Creer le provider

Tous les providers HUB doivent etendre `Modules\CrmCore\Providers\CrmModuleServiceProvider`.

```php
<?php

namespace Modules\CrmExample\Providers;

use Modules\CrmCore\Providers\CrmModuleServiceProvider;

class CrmExampleServiceProvider extends CrmModuleServiceProvider
{
    public function boot(): void
    {
        $this->bootCrmModule(__DIR__.'/../..');
    }
}
```

`bootCrmModule()` charge automatiquement :

- `routes/web.php`
- `database/migrations`

## 5. Ajouter les routes

Les pages HUB servent la vue `crm`. Les API doivent utiliser le chemin sans extension `.php`.

```php
<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Modules\CrmExample\Http\Controllers\ExampleApiController;

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];

Route::view('/example', 'crm')
    ->middleware(['auth', 'crm.module:example,example.view'])
    ->name('hub.example');

Route::match(['GET', 'POST', 'OPTIONS'], '/api/example', ExampleApiController::class)
    ->middleware([...$crmApiMiddleware, 'crm.mobile_scope:crm:module:example'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('hub.api.example');
```

Aucun alias `/api/*.php` ne doit etre cree. Les anciens clients doivent etre migres vers les routes Laravel sans extension.

## 6. Organiser la logique metier

Repartition attendue :

- `Http/Controllers` : validation d'entree, appel service, reponse JSON ou vue.
- `Services` : regles metier, transactions, calculs, conflits.
- `Data` : DTO simples pour normaliser les payloads.
- `Models` : uniquement les relations, casts, scopes et constantes utiles.
- `Events` et `Listeners` : communication entre modules sans dependance directe.

Quand une action a un impact metier important, publier un evenement :

```php
use Modules\CrmCore\Events\CrmDomainEvent;

CrmDomainEvent::dispatch(
    module: 'example',
    name: 'created',
    entity: 'example_item',
    entityId: $item->id,
    siteId: $item->site_id,
    actorId: $user->id,
    actorName: $user->name,
    payload: ['label' => $item->label],
);
```

## 7. Droits, menu et feature flag

Un module doit etre declare dans les donnees HUB :

- `crm_modules` pour l'activation et le libelle.
- `crm_permissions` pour les actions fines.
- `crm_menu_items` pour le menu lateral.
- `crm_feature_flags` pour l'activation a chaud.

Les droits suivent la separation documentee dans [HUB_AUTHORIZATION.md](HUB_AUTHORIZATION.md) :

- Spatie Permission est reserve a la plateforme, Filament et Horizon.
- `CrmAccessService` est l'autorite pour les modules metier, les sites et les permissions metier.
- Les services ou les Policies portent les decisions finales. Les controleurs restent minces.

Commande utile :

```bash
php artisan crm:feature --list
php artisan crm:feature module:example --disable
php artisan crm:feature module:example --enable
```

Les actions sensibles doivent etre protegees par le middleware `crm.module`, une Policy ou un controle explicite dans le service. Une permission Spatie ne doit pas remplacer une permission metier pour une action metier contextuelle par site.

## 8. Assets du module

Placer les sources propres au module dans `resources/assets`, puis publier vers `public/modules` :

```bash
php artisan crm:publish-module-assets --force
```

Ne pas modifier directement `public/modules` pour une correction durable et ne pas commiter ce dossier : il est regenere au build/deploiement.

Les images, logos et assets PWA communs doivent etre places dans `resources/frontend/static/assets`, puis publies vers `public/assets` avec `php artisan crm:publish-static-assets --force --clean`. Ne pas ajouter de nouveau code metier dans le snapshot `legacy-adminex-*` : il existe seulement pour maintenir les anciens ecrans pendant leur migration.

## 9. Tests attendus

Ajouter au minimum :

- test d'acces page avec et sans droit.
- test API `bootstrap` ou equivalent.
- test creation/modification/suppression si le module manipule des donnees.
- test feature flag si la route principale doit disparaitre quand le module est desactive.

Avant commit :

```bash
make quality
make pint
make build
```

## 10. Checklist avant pull request

- Provider declare dans `module.json`.
- `database/migrations` existe dans le module.
- Aucune migration HUB dans `database/migrations`.
- Routes API sans `.php` presentes.
- Aucun alias API legacy `.php`.
- Permissions et menu ajoutes.
- Feature flag `module:<slug>` disponible.
- Services testes.
- `make deploy-check` passe.
