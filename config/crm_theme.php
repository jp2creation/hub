<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HUB Theme
    |--------------------------------------------------------------------------
    |
    | Change these values to update the shared CRM visual identity. The helper
    | exposes the same colors to Blade views, CRM modules, PWA metadata, and
    | Filament.
    |
    */

    'colors' => [
        'primary' => env('CRM_THEME_PRIMARY', '#95002e'),
        'primary_dark' => env('CRM_THEME_PRIMARY_DARK'),
        'accent' => env('CRM_THEME_ACCENT', '#f5b212'),
    ],
];
