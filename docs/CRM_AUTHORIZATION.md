# Autorisations CRM

Le HUB utilise deux niveaux de droits. Ils ne doivent pas etre fusionnes sans decision explicite, car ils ne protegent pas le meme perimetre.

## Sources d'autorite

### Spatie Permission

Spatie reste l'autorite pour les droits plateforme :

- acces au panel Filament `/admin` ;
- ressources Filament ;
- Horizon ;
- exception admin plateforme sur les pages shell CRM.

La methode unique pour cette decision est `App\Models\User::canUsePlatformAdministration()`.

Les roles plateforme reconnus sont `admin`, `Admin` et `Super Admin`.
Les permissions plateforme reconnues sont `filament.access` et `filament.manage`.

### Droits CRM

`Modules\CrmCore\Services\CrmAccessService` reste l'autorite pour les droits metier :

- acces aux modules CRM ;
- acces aux sites ;
- permissions contextuelles par site, module et action ;
- role CRM `blocked` ;
- role CRM `admin`, qui donne un acces metier complet aux sites et modules actifs.

Les tables CRM restent donc la source de verite pour les decisions metier. Un controleur API ne doit pas decider directement qu'un utilisateur peut creer, modifier ou supprimer une entite metier.

## Regle de routage

Le middleware `crm.module` protege les pages shell CRM.

Un utilisateur avec acces plateforme Spatie peut ouvrir ces pages pour administrer et diagnostiquer le CRM, meme sans profil `CrmUser`. Cette exception est volontaire, documentee et couverte par un test.

Les actions metier restent controlees ensuite par :

- un service metier utilisant `CrmAccessService` ;
- une Policy Laravel pour les modeles principaux ;
- ou les deux lorsque l'action a besoin d'une verification de site et d'une verification de propriete.

## Regles pour les nouveaux modules

- Les controleurs restent minces : lecture de la requete, resolution de l'acteur, appel du service, reponse JSON.
- Les controles de permission metier vont dans un service metier ou dans une Policy.
- Les ressources Filament utilisent `AuthorizesResourceWithPolicy`.
- Les routes shell utilisent `crm.module:<slug>,<permission...>`.
- Les routes API mobile utilisent `auth:sanctum` et `crm.mobile_scope`.
- Les exceptions admin doivent passer par `User::canUsePlatformAdministration()` ou `CrmAccessService`, jamais par une liste locale recopiee.
