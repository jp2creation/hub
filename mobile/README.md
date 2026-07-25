# Martin Sols Mobile

Application Capacitor hybride pour le HUB Martin Sols.

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

## Licence

Cette application mobile fait partie de Martin Sols HUB et suit la licence du
depot racine. Les sources peuvent etre consultees et testees pour evaluation
personnelle, mais toute compilation, distribution, installation client,
exploitation professionnelle, revente ou publication d'un APK, d'un paquet iOS
ou d'un paquet macOS demande l'accord ecrit prealable de Jean-Philippe DEGERT /
JP2 Creation.
