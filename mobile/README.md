# JP2 Hub Mobile

Application Capacitor hybride pour JP2 Hub. L'installation Martin Sols est
l'exemple metier actuellement embarque dans cette application.

Le login, la barre du haut, le menu lateral, les commandes navigateur et les
parametres propres a l'app sont integres dans l'app mobile. Les pages et
modules du HUB restent charges en WebView via une session web temporaire creee
par l'API mobile Laravel.

URL HUB par defaut :

```text
https://crm.jp2.fr
```

Le code metier reste dans le HUB Laravel. Les mises a jour des modules web sont
visibles dans l'app sans reconstruire l'APK, tant que le cadre mobile ne change
pas.

## Fonctionnalites app

- Animation d'entree Martin Sols embarquee dans l'APK.
- Navigateur integre avec retour, avance, actualisation et conservation des
  liens dans l'app.
- Parametres app : URL du serveur HUB, localisation, dernier module et
  commandes navigateur.
- Localisation native Capacitor avec permissions Android fine/coarse.
- Etat reseau et informations version/appareil visibles dans les parametres.

## Commandes

```bash
npm install
npm run build
npx cap sync android
npx cap open android
```

## Android APK debug

```bash
npm run apk:debug
```

APK genere :

```text
mobile/android/app/build/outputs/apk/debug/app-debug.apk
```

## iOS

La plateforme iOS est disponible dans `mobile/ios` et reprend le meme build web
que l'APK Android pour iPhone et iPad. L'ouverture et la compilation iOS se font
sur macOS avec Xcode :

```bash
npm run build
npx cap sync ios
npx cap open ios
```

Reglages iOS integres :

- cible universelle iPhone + iPad ;
- icone et ecran de lancement bases sur les assets mobiles ;
- permissions localisation iOS pour les fonctions terrain ;
- WebView configuree pour les liens, cookies, medias et le clavier.

## Distribution GitHub et stores

Les builds et releases natives ne sont plus pilotes par le depot `hub`.
Chaque plateforme a son depot GitHub dedie, avec un second canal possible par
store officiel :

- Android : `jp2creation/hub_android` ou Google Play ;
- Windows : `jp2creation/hub_windows` ou Microsoft Store ;
- Apple iPhone/iPad/Mac : `jp2creation/hub_apple` ou App Store/Mac App Store.

Le detail des manifests et variables Store est documente dans
`docs/APP_DISTRIBUTION.md`.

## Windows

La plateforme Windows est disponible dans `mobile/windows` et reprend le meme
principe que l'APK Android et l'app macOS : animation d'entree, WebView chargee
sur le HUB, bridge `MartinSolsNativeApp`, connexion rapide locale, localisation
et recherche de mise a jour via le manifeste GitHub.

Compilation sur Windows :

```bash
cd mobile/windows
npm install
npm run check
npm run dist
```

Artefacts Windows generes :

```text
mobile/windows/dist/Martin Sols HUB-1.0.0-win-x64-setup.exe
mobile/windows/dist/Martin Sols HUB-1.0.0-win-x64-portable.exe
mobile/windows/dist/win-unpacked/Martin Sols HUB.exe
```

## Licence

Ces sources locales restent conservees pour compatibilite avec le HUB, mais les
releases officielles doivent etre gerees dans les depots apps dedies. Toute
compilation, distribution, installation client, exploitation professionnelle,
revente ou publication d'un APK, d'un paquet iOS ou d'un paquet macOS/Windows
demande l'accord ecrit prealable de Jean-Philippe DEGERT / JP2 Creation.
