# Martin Sols HUB Windows

Application Windows pour le HUB Martin Sols. Elle reprend le principe des apps
Android, iOS et macOS : le code metier reste dans le HUB Laravel, et l'app
embarque un cadre natif avec splash, WebView, navigation, connexion rapide,
localisation et verification de mise a jour.

URL chargee par defaut :

```text
https://crm.jp2.fr/?mobile_app=1&source=windows_app
```

## Commandes

```bash
npm install
npm run check
npm run dist
```

Artefacts generes :

```text
mobile/windows/dist/Martin Sols HUB-1.0.1-win-x64-setup.exe
mobile/windows/dist/Martin Sols HUB-1.0.1-win-x64-portable.exe
```

La commande `npm run pack` genere une version non-installee dans
`mobile/windows/dist/win-unpacked` pour tester rapidement l'application.

## Bridge natif

Le preload expose `window.MartinSolsNativeApp`, comme les apps Android/macOS :

- version, build et plateforme Windows ;
- statut de connexion rapide ;
- sauvegarde et suppression de session rapide avec `safeStorage` Windows ;
- code app local facultatif ;
- ouverture des reglages de connexion Windows ;
- localisation via l'API geolocation Chromium ;
- verification du manifeste GitHub.

## Mise a jour

L'app lit le meme manifeste que les apps Android/iOS/macOS :

```text
https://raw.githubusercontent.com/jp2creation/hub_windows/main/martin-sols-update.json
```

Quand un installeur Windows est publie sur GitHub, renseigner `windows.installerUrl`
ou `windows.portableUrl` dans ce manifeste.
