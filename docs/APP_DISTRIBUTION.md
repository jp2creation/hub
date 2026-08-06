# Distribution des applications HUB

Le depot `hub` reste la source du HUB web Laravel. Il ne pilote plus les builds
ou les releases des applications natives.

## Sources GitHub

| Plateforme | Depot | Manifest de mise a jour | Telechargement GitHub |
| --- | --- | --- | --- |
| Android APK / Play bundle | `jp2creation/hub_android` | `releases/jp2-hub-android-update.json` | Releases `hub_android` |
| Windows EXE | `jp2creation/hub_windows` | `martin-sols-update.json` | Dossier `releases` de `hub_windows` |
| Apple iPhone/iPad/Mac | `jp2creation/hub_apple` | `releases/martin-sols-update.json` | Releases `hub_apple` |

## Stores officiels

Les stores restent un second canal de distribution :

- Android : Google Play via `HUB_ANDROID_STORE_URL`.
- iPhone/iPad : App Store ou TestFlight via `HUB_IOS_STORE_URL`.
- Mac : Mac App Store via `HUB_MACOS_STORE_URL`.
- Windows : Microsoft Store via `HUB_WINDOWS_STORE_URL`.

Tant que ces variables sont vides, la page de login garde les badges Store
visibles mais indique que la publication officielle est en attente. Les liens
GitHub restent disponibles en dessous pour les telechargements directs.

## Role du depot HUB

Le HUB conserve seulement :

- la configuration des liens publics dans `config/hub_apps.php` ;
- l'affichage des badges Store et liens GitHub sur la page de login ;
- une copie de manifest local dans `mobile/releases/martin-sols-update.json`
  pour alimenter les liens publics du login.

Les workflows de release natifs doivent etre maintenus dans les depots apps
respectifs, pas dans `.github/workflows` du depot `hub`.
