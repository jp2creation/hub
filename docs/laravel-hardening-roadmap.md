# Laravel hardening roadmap

## Etat actuel

Le projet est un Laravel 12 avec Filament 5. L'administration Filament utilise le modele `users`, Spatie Permission et le guard `web`.

Le HUB metier public passe par le routeur Laravel. Les anciens endpoints proceduraux `public/api/*.php` et les alias `/api/*.php` ont ete retires.

## Corrections immediates deja appliquees

- Les erreurs internes des endpoints metier sont logguees cote serveur et ne sont plus retournees en clair au navigateur quand `APP_DEBUG=false`.
- L'identite HUB par `?user_id=` dans l'URL a ete supprimee.
- Les fichiers internes `public/api/_*.php` et les fichiers AppleDouble `._*` sont bloques par `.htaccess`.
- `.gitignore` ignore maintenant les fichiers `._*`.
- Une migration ajoute `crm_users.user_id` avec un index unique pour relier progressivement les utilisateurs metier aux comptes Laravel.
- Les endpoints HUB privilegient la session Laravel : cookie Laravel -> table `sessions` -> `users.id` -> `crm_users.user_id`.
- L'ancien header `X-CRM-User-Id` est desactive par defaut via `CRM_ALLOW_LEGACY_ACTOR_HEADER=false`; le champ body `actorUserId` reste toleré temporairement via `CRM_ALLOW_LEGACY_ACTOR_BODY=true`.
- Une page de connexion HUB Laravel existe sur `/login`, avec deconnexion POST `/logout`, pour creer une session `web` hors Filament.
- La resource Filament `Utilisateurs HUB` affiche le compte Laravel rattache et propose une action de creation/rattachement de compte pour les profils non rattaches.

## Migration prioritaire

1. Rattacher les utilisateurs HUB aux comptes Laravel.

   La colonne `crm_users.user_id nullable unique` est en place. Les comptes peuvent etre crees et rattaches depuis Filament, fiche `Utilisateurs HUB`, action `Compte Laravel`.

   Note hebergement actuel : une migration separee convertit `users` en InnoDB puis ajoute la foreign key `crm_users.user_id -> users.id`.

2. Proteger les routes HUB avec Laravel.

   Les routes applicatives doivent utiliser `auth`, `verified` si necessaire, puis des policies ou gates metier.

3. Garder les endpoints HUB dans le routeur Laravel.

   Cible permanente :

   - routes `/api/<module>` sans extension `.php`
   - Form Requests pour validation
   - Resources JSON pour les payloads front
   - Services metier pour conflits de planning, droits par site et droits par module

4. Supprimer l'impersonation legacy.

   Quand tous les utilisateurs metier ont un compte Laravel rattache, definir :

   ```dotenv
   CRM_ALLOW_LEGACY_ACTOR_IMPERSONATION=false
   ```

   Puis supprimer `actorUserId` et `X-CRM-User-Id` du front. La session Laravel est deja prioritaire si `CRM_TRUST_LARAVEL_SESSION=true`.

   Transition conseillee tant que certains profils n'ont pas de compte Laravel :

   ```dotenv
   CRM_ALLOW_LEGACY_ACTOR_IMPERSONATION=true
   CRM_ALLOW_LEGACY_ACTOR_HEADER=false
   CRM_ALLOW_LEGACY_ACTOR_BODY=true
   ```

   Une fois les comptes rattaches et le front migre, passer `CRM_ALLOW_LEGACY_ACTOR_IMPERSONATION=false`.

5. Remplacer toute alteration SQL a la requete par des migrations.

   Le schema doit etre gere uniquement par `php artisan migrate`.

6. Ajouter les contraintes relationnelles.

   Les migrations HUB utilisent beaucoup de `foreignId()` sans `constrained()`. Ajouter les foreign keys avec une strategie claire :

   - `restrictOnDelete()` pour les donnees historiques a conserver
   - `cascadeOnDelete()` seulement pour les pivots
   - index supplementaires sur les plages de reservation si necessaire

7. Ajouter des tests.

   Tests minimaux :

   - un employe ne peut modifier que ses reservations
   - un responsable peut modifier les reservations de son site
   - un utilisateur ne peut pas acceder a un site non rattache
   - deux reservations ne peuvent pas se chevaucher sur le meme vehicule ou materiel
   - un utilisateur bloque ne peut appeler aucun module

## Nettoyage de deploiement

Ne pas versionner ou deployer :

- `vendor/`
- `node_modules/`
- `storage/logs/*`
- `storage/framework/views/*`
- fichiers `._*`
- anciennes builds non referencees dans `public/assets`

Garder les archives de recuperation hors du dossier applicatif deploye.
