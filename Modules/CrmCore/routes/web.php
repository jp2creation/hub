<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\CrmCore\Http\Controllers\DashboardApiController;
use Modules\CrmCore\Http\Controllers\LegacyCrmPathRedirectController;
use Modules\CrmCore\Http\Controllers\LegacyTemplateController;
use Modules\CrmCore\Http\Controllers\MobileAuthController;
use Modules\CrmCore\Http\Controllers\PublicUploadController;
use Modules\CrmCore\Http\Controllers\PwaAssetController;

Route::get('/manifest.json', [PwaAssetController::class, 'manifest'])->name('crm.pwa.manifest');
Route::get('/sw.js', [PwaAssetController::class, 'serviceWorker'])->name('crm.pwa.service-worker');
Route::get('/offline.html', [PwaAssetController::class, 'offline'])->name('crm.pwa.offline');

Route::get('/uploads/{publicUploadPath}', PublicUploadController::class)
    ->middleware('auth')
    ->where('publicUploadPath', 'assets/uploads/.*')
    ->name('crm.uploads.show');

Route::redirect('/dashboard/crm', '/')
    ->middleware('auth')
    ->name('crm.dashboard');

Route::redirect('/dashboard', '/')
    ->middleware('auth')
    ->name('crm.dashboard.legacy');

Route::view('/pages/account-settings', 'crm')
    ->middleware('auth')
    ->name('crm.account-settings');

Route::get('/dashboard/{legacyCrmPath}', LegacyCrmPathRedirectController::class)
    ->where('legacyCrmPath', '^(?:crm/)?(?:reservations|locations-materiel|equipes|pages-crm|administration|conges|controle-caisse|demandes-acompte|remise-cheques|tapis-romus|documents(?:-[A-Za-z0-9_-]+)?)(?:/.*)?$')
    ->middleware('auth')
    ->name('crm.dashboard.legacy-path');

Route::any('/{legacyTemplatePath}', LegacyTemplateController::class)
    ->where('legacyTemplatePath', '^(?:app|dashboard|forms|tables|charts|pages|features|auth|auth-card)(?:/.*)?$')
    ->name('crm.legacy-template-gone');

$crmApiMiddleware = ['throttle:crm-api', 'crm.compress'];
$crmLoginApiMiddleware = ['throttle:crm-login', 'crm.compress'];

Route::match(['GET', 'OPTIONS'], '/api/dashboard', DashboardApiController::class)
    ->middleware([...$crmApiMiddleware, 'crm.mobile_scope:crm:module:dashboard'])
    ->name('crm.api.dashboard');

Route::get('/api/mobile/dashboard', DashboardApiController::class)
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'crm.mobile_scope:crm:module:dashboard'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.dashboard');

Route::options('/api/mobile/{path}', [MobileAuthController::class, 'options'])
    ->middleware('crm.compress')
    ->where('path', '.*')
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.options');

Route::post('/api/mobile/token', [MobileAuthController::class, 'token'])
    ->middleware($crmLoginApiMiddleware)
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.token');

Route::post('/api/mobile/refresh', [MobileAuthController::class, 'refresh'])
    ->middleware($crmLoginApiMiddleware)
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.refresh');

Route::get('/api/mobile/me', [MobileAuthController::class, 'me'])
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'crm.mobile_scope:crm:mobile'])
    ->name('crm.api.mobile.me');

Route::post('/api/mobile/native-session', [MobileAuthController::class, 'nativeSession'])
    ->middleware(['auth', ...$crmApiMiddleware])
    ->name('crm.api.mobile.native-session');

Route::post('/api/mobile/web-session', [MobileAuthController::class, 'webSession'])
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'crm.mobile_scope:crm:mobile'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.web-session');

Route::post('/api/mobile/logout', [MobileAuthController::class, 'logout'])
    ->middleware(['auth:sanctum', ...$crmApiMiddleware, 'crm.mobile_scope:crm:mobile'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('crm.api.mobile.logout');

Route::get('/mobile/session/{token}', [MobileAuthController::class, 'consumeWebSession'])
    ->middleware(['throttle:crm-api'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('crm.mobile.web-session');
