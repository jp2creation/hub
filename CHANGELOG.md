# Changelog

Toutes les modifications notables du HUB Martin Sols sont documentees ici.

Le format suit une logique proche de "Keep a Changelog" et les releases doivent
etre taguees dans Git avec la convention `vYYYY.MM.DD.N`.

## [Unreleased]

### Added

- Documentation technique dans `docs/README.md` avec architecture, schema ER et flux principaux.
- Convention de release et rappel d'utilisation des tags Git.
- Tests de conflit conges pour les demi-journees.
- Cache applicatif centralise pour les listes de reference CRM.
- Index de consultation sur les pivots CRM et colonnes de jointure restantes.
- Tests unitaires du calcul de chevauchement des conges.
- Tests d'echec API pour validation `422`, ressource introuvable `404`, limitation de tentatives et CSRF.
- Commande Artisan `crm:admin` pour creer ou mettre a jour l'administrateur sans mot de passe dans `.env.example`.
- Configuration CORS explicite pour les routes `api/*`, dont `api/mobile/*` et les origins Capacitor/Ionic.
- Tests unitaires de la politique de mot de passe admin.
- Detection automatique de la version des bundles importes par les chunks CRM pour eviter le double chargement de React.
- Stack Docker Sail PHP 8.3 avec MySQL, Redis et Mailpit.
- Workflow GitHub Actions pour Pint, build Vite et tests Laravel en PHP 8.3.
- `CrmActivityLogger` centralise pour journaliser les actions critiques dans `crm_logs`.
- Nettoyage automatique des photos uploadées lors du masquage, remplacement ou suppression des vehicules et materiels.
- Throttles Laravel explicites pour les routes API CRM, token mobile et login web.
- Canal de log `horizon` en rotation quotidienne.
- Nettoyage automatique des anciennes entrees de menu issues du template UI.
- Middleware de compression gzip pour les reponses API JSON.
- Commande `backup:run` et planification quotidienne des sauvegardes SQL compressees.
- Page maintenance `503` personnalisee.
- Route 404 explicite pour les anciennes pages du template UI.
- Module Documents avec categories Promo, Fiches techniques et Procedures.
- Module Demande d'acompte avec creation, suivi et validation comptable.
- Module Rapport de visite pour les tournees commerciales, visites clients, comptes rendus et actions.
- Module Remise de cheques avec photo, controles signature/destinataire, detection assistee et export PDF.
- Evolution du module Controle caisse avec factures, comptage especes, ecarts, justificatifs et PDF.
- PWA installable avec `manifest.json`, Service Worker et scripts de boot public.
- Guide d'utilisation PDF complet et plaquette de presentation CRM dans `docs/`.

### Changed

- `.editorconfig` complete pour mieux cadrer les fichiers PHP, JS, CSS, JSON et Markdown.
- Les anciennes entrees physiques `public/api/*.php` sont supprimees afin que les URL legacy passent par les routes Laravel et leur middleware.
- Les conflits de conges tiennent compte des demi-journees et ignorent les demandes refusees.
- La documentation d'installation rappelle que `CRM_ADMIN_PASSWORD` doit etre defini uniquement dans le vrai `.env`.
- Les bootstraps API reservations/location materiel prechargent les relations et reutilisent les donnees statiques cachees.
- `CrmAsset` utilise le manifest Vite quand un asset y est declare, puis le cache-busting `filemtime` pour les bundles CRM historiques.
- Les conflits de conges utilisent un service metier testable au lieu d'une logique uniquement portee par la requete SQL.
- Les erreurs de validation des `FormRequest` API CRM retournent maintenant le statut HTTP `422`.
- Le seeder ne bloque plus l'installation si aucun mot de passe admin n'est fourni ; l'admin se gere via `php artisan crm:admin`.
- Les assets CRM generes par Laravel utilisent la meme query string que les imports Vite existants, ce qui corrige les pages blanches au rafraichissement.
- Les creations et suppressions de conges sont maintenant tracees dans le journal CRM.
- Le projet exige maintenant PHP 8.3 minimum, aligne avec l'hebergement Planethoster.
- Les reservations vehicules et locations materiel refusent maintenant les dates passees.
- Le menu CRM se limite aux modules reels : applications metier, administration/interne et pages internes seulement si elles existent.
- La documentation de deploiement rappelle la generation du cache vues et le controle du scheduler.
- Le rewrite Apache ne force plus les anciens chemins `/auth`, `/dashboard`, `/forms`, `/tables`, `/charts`, `/pages` et `/features` vers le HUB.
- Le menu place maintenant `Conges` dans `Applications HUB`, juste sous `Equipe`.
- Le README et la documentation technique listent les modules recents, les routes, les tables et la procedure de publication des assets modules.

### Removed

- Vues Blade obsoletes et sauvegardes locales non routees.

## [2026.07.11.3] - 2026-07-11

### Added

- Cache-busting automatique des assets CRM via `App\Support\CrmAsset`.
- Migration d'indexes de performance pour reservations, locations, materiel, vehicules et conges.
- Cron serveur Laravel `schedule:run` toutes les minutes.
- Tache planifiee `sanctum:prune-expired --hours=24`.
- Cache applicatif des categories materiel actives avec invalidation automatique.

### Changed

- Logs Laravel configures en canal `daily` avec conservation sur 30 jours.
- Variables `.env.example` completees pour logs, timezone, securite legacy et assets.

## [2026.07.11.2] - 2026-07-11

### Added

- `FormRequest` dediees pour login, token mobile et actions API CRM.
- `ReservationConflictQuery` pour centraliser les controles de chevauchement.
- Chargements eager loading dans les ressources Filament principales.
- Configuration `CRM_DISPLAY_TIMEZONE`.

### Changed

- Les controles de conflits vehicules, materiel et conges utilisent une classe de query dediee.
- Les validations recurrentes des API CRM sont centralisees.

## [2026.07.11.1] - 2026-07-11

### Added

- Middleware d'audit pour endpoints legacy `.php`.
- Politiques Filament de base pour limiter les acces directs.
- Revocation des tokens Sanctum lors des changements de mot de passe.

### Changed

- Durcissement des mots de passe admin seedes.
- Throttling des endpoints legacy CRM.

## [2026.07.10.1] - 2026-07-10

### Added

- Tableau de bord CRM apres connexion.
- Vue cartes pour location materiel et reservations vehicules.
- Module conges integre au shell CRM.

### Fixed

- Corrections de pages blanches et problemes de routes 404 internes.
- Harmonisation visuelle mobile pour les modules reservations, locations et conges.
