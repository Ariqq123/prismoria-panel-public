<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Admin\Extensions\serverbackgrounds\serverbackgroundsExtensionController;

/*
 * Blueprint registers this router from `conf.yml` (requests.routers.web).
 * These routes are mounted under `/extensions/serverbackgrounds`.
 */
Route::get('/server-backgrounds', [serverbackgroundsExtensionController::class, 'index'])
    ->name('blueprint.extensions.serverbackgrounds');

Route::get('/configured-egg-backgrounds', [serverbackgroundsExtensionController::class, 'fetchConfiguredEggBackgrounds'])
    ->name('blueprint.extensions.serverbackgrounds.api.configured_egg_backgrounds');

Route::get('/configured-server-backgrounds', [serverbackgroundsExtensionController::class, 'fetchConfiguredServerBackgrounds'])
    ->name('blueprint.extensions.serverbackgrounds.api.configured_server_backgrounds');

Route::get('/configured-server-backgrounds-effective', [serverbackgroundsExtensionController::class, 'fetchEffectiveServerBackgrounds'])
    ->name('blueprint.extensions.serverbackgrounds.api.configured_server_backgrounds_effective');

Route::get('/api/settings', [serverbackgroundsExtensionController::class, 'getSettings'])
    ->name('blueprint.extensions.serverbackgrounds.api.settings');

Route::get('/api/user-server-background', [serverbackgroundsExtensionController::class, 'getUserServerBackground'])
    ->name('blueprint.extensions.serverbackgrounds.api.user_server_background');

Route::post('/api/user-server-background', [serverbackgroundsExtensionController::class, 'upsertUserServerBackground'])
    ->name('blueprint.extensions.serverbackgrounds.api.user_server_background_upsert');

Route::post('/api/user-server-background/upload', [serverbackgroundsExtensionController::class, 'uploadUserServerBackground'])
    ->name('blueprint.extensions.serverbackgrounds.api.user_server_background_upload');

Route::post('/admin/extensions/serverbackgrounds/settings', [serverbackgroundsExtensionController::class, 'updateSettings'])
    ->name('blueprint.extensions.serverbackgrounds.updateSettings');

Route::post('/admin/extensions/serverbackgrounds/bulk-save', [serverbackgroundsExtensionController::class, 'bulkSaveBackgrounds'])
    ->name('blueprint.extensions.serverbackgrounds.bulkSaveSettings');

Route::post('/admin/extensions/serverbackgrounds/update-delete', [serverbackgroundsExtensionController::class, 'updateAndDeleteBackgroundSettings'])
    ->name('blueprint.extensions.serverbackgrounds.updateAndDeleteSettings');
