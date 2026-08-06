<?php

return [
    'android' => [
        'repository_url' => 'https://github.com/jp2creation/hub_android',
        'release_url' => 'https://github.com/jp2creation/hub_android/releases/latest',
        'manifest_url' => 'https://raw.githubusercontent.com/jp2creation/hub_android/main/releases/jp2-hub-android-update.json',
        'store_url' => env('HUB_ANDROID_STORE_URL', ''),
    ],

    'apple' => [
        'repository_url' => 'https://github.com/jp2creation/hub_apple',
        'release_url' => 'https://github.com/jp2creation/hub_apple/releases/latest',
        'manifest_url' => 'https://raw.githubusercontent.com/jp2creation/hub_apple/main/releases/martin-sols-update.json',
        'store_url' => env('HUB_APPLE_STORE_URL', ''),
        'ios_store_url' => env('HUB_IOS_STORE_URL', ''),
        'macos_store_url' => env('HUB_MACOS_STORE_URL', ''),
    ],

    'windows' => [
        'repository_url' => 'https://github.com/jp2creation/hub_windows',
        'release_url' => 'https://github.com/jp2creation/hub_windows/tree/main/releases',
        'manifest_url' => 'https://raw.githubusercontent.com/jp2creation/hub_windows/main/martin-sols-update.json',
        'store_url' => env('HUB_WINDOWS_STORE_URL', ''),
    ],
];
