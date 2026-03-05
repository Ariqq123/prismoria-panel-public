<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Client;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Activity\AccountSubject;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client
|
*/
Route::get('/', [Client\ClientController::class, 'index'])->name('api:client.index');
Route::get('/permissions', [Client\ClientController::class, 'permissions']);

Route::prefix('/account')->middleware(AccountSubject::class)->group(function () {
    Route::prefix('/')->withoutMiddleware(RequireTwoFactorAuthentication::class)->group(function () {
        Route::get('/', [Client\AccountController::class, 'index'])->name('api:client.account');
        Route::get('/two-factor', [Client\TwoFactorController::class, 'index']);
        Route::post('/two-factor', [Client\TwoFactorController::class, 'store']);
        Route::post('/two-factor/disable', [Client\TwoFactorController::class, 'delete']);
    });

    Route::put('/email', [Client\AccountController::class, 'updateEmail'])->name('api:client.account.update-email');
    Route::put('/password', [Client\AccountController::class, 'updatePassword'])->name('api:client.account.update-password');

    Route::get('/activity', Client\ActivityLogController::class)->name('api:client.account.activity');

    Route::get('/api-keys', [Client\ApiKeyController::class, 'index']);
    Route::post('/api-keys', [Client\ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{identifier}', [Client\ApiKeyController::class, 'delete']);

    Route::prefix('/ssh-keys')->group(function () {
        Route::get('/', [Client\SSHKeyController::class, 'index']);
        Route::post('/', [Client\SSHKeyController::class, 'store']);
        Route::post('/remove', [Client\SSHKeyController::class, 'delete']);
    });

    Route::prefix('/external-panels')->middleware('throttle:external-panel')->group(function () {
        Route::get('/', [Client\ExternalPanelConnectionController::class, 'index']);
        Route::get('/export', [Client\ExternalPanelConnectionController::class, 'export']);
        Route::post('/import', [Client\ExternalPanelConnectionController::class, 'import']);
        Route::post('/', [Client\ExternalPanelConnectionController::class, 'store']);
        Route::patch('/{connection}', [Client\ExternalPanelConnectionController::class, 'update']);
        Route::post('/{connection}/verify', [Client\ExternalPanelConnectionController::class, 'verify']);
        Route::delete('/{connection}', [Client\ExternalPanelConnectionController::class, 'delete']);
    });

    Route::post('/assistant/chat', [Client\AiAssistantController::class, 'chat'])
        ->middleware('throttle:20,1');
});

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client/servers/{server}
|
*/
Route::group([
    'prefix' => '/servers/external:{externalServer}',
    'middleware' => ['throttle:external-panel'],
], function () {
    Route::get('/', [Client\External\Servers\ServerController::class, 'index'])->name('api:client:external-server.view');
    Route::get('/websocket', Client\External\Servers\WebsocketController::class)->name('api:client:external-server.ws');
    Route::get('/resources', Client\External\Servers\ResourceUtilizationController::class)->name('api:client:external-server.resources');
    Route::get('/activity', Client\External\Servers\ActivityLogController::class)->name('api:client:external-server.activity');

    Route::post('/command', [Client\External\Servers\CommandController::class, 'index']);
    Route::post('/power', [Client\External\Servers\PowerController::class, 'index']);
    Route::get('/region', Client\External\Servers\RegionController::class);

    Route::group(['prefix' => '/mcplugins'], function () {
        Route::get('/', [Client\External\Servers\MCPluginsController::class, 'index']);
        Route::get('/version', [Client\External\Servers\MCPluginsController::class, 'versions']);
        Route::post('/install', [Client\External\Servers\MCPluginsController::class, 'install']);
        Route::get('/settings', [Client\External\Servers\MCPluginsController::class, 'settings']);
    });

    Route::group(['prefix' => '/playermanager'], function () {
        Route::get('/', [Client\External\Servers\PlayerManagerController::class, 'index']);
        Route::post('/command', [Client\External\Servers\PlayerManagerController::class, 'runCommand']);
    });

    Route::group(['prefix' => '/votifier'], function () {
        Route::post('/classic', [Client\External\Servers\VotifierController::class, 'sendClassic']);
        Route::post('/nu', [Client\External\Servers\VotifierController::class, 'sendNu']);
        Route::post('/nu/v2', [Client\External\Servers\VotifierController::class, 'sendNuV2']);
    });

    Route::group(['prefix' => '/subdomain'], function () {
        Route::get('/', [Client\External\Servers\SubdomainController::class, 'index']);
        Route::get('/min3/checking', [Client\External\Servers\SubdomainController::class, 'min3Checking']);
        Route::post('/create', [Client\External\Servers\SubdomainController::class, 'create']);
        Route::delete('/delete/{id}', [Client\External\Servers\SubdomainController::class, 'delete']);
    });

    Route::group(['prefix' => '/databases'], function () {
        Route::get('/', [Client\External\Servers\DatabaseController::class, 'index']);
        Route::post('/', [Client\External\Servers\DatabaseController::class, 'store']);
        Route::post('/{database}/rotate-password', [Client\External\Servers\DatabaseController::class, 'rotatePassword']);
        Route::delete('/{database}', [Client\External\Servers\DatabaseController::class, 'delete']);
    });

    Route::group(['prefix' => '/files'], function () {
        Route::get('/list', [Client\External\Servers\FileController::class, 'directory']);
        Route::get('/contents', [Client\External\Servers\FileController::class, 'contents']);
        Route::get('/download', [Client\External\Servers\FileController::class, 'download']);
        Route::put('/rename', [Client\External\Servers\FileController::class, 'rename']);
        Route::post('/copy', [Client\External\Servers\FileController::class, 'copy']);
        Route::post('/write', [Client\External\Servers\FileController::class, 'write']);
        Route::post('/compress', [Client\External\Servers\FileController::class, 'compress']);
        Route::post('/decompress', [Client\External\Servers\FileController::class, 'decompress']);
        Route::post('/delete', [Client\External\Servers\FileController::class, 'delete']);
        Route::post('/create-folder', [Client\External\Servers\FileController::class, 'create']);
        Route::post('/chmod', [Client\External\Servers\FileController::class, 'chmod']);
        Route::post('/pull', [Client\External\Servers\FileController::class, 'pull'])->middleware(['throttle:10,5']);
        Route::get('/upload', Client\External\Servers\FileUploadController::class);
        Route::post('/upload/proxy', Client\External\Servers\FileUploadProxyController::class)->name('api:client:external-server.files.upload-proxy');
    });

    Route::group(['prefix' => '/schedules'], function () {
        Route::get('/', [Client\External\Servers\ScheduleController::class, 'index']);
        Route::post('/', [Client\External\Servers\ScheduleController::class, 'store']);
        Route::get('/{schedule}', [Client\External\Servers\ScheduleController::class, 'view']);
        Route::post('/{schedule}', [Client\External\Servers\ScheduleController::class, 'update']);
        Route::post('/{schedule}/execute', [Client\External\Servers\ScheduleController::class, 'execute']);
        Route::delete('/{schedule}', [Client\External\Servers\ScheduleController::class, 'delete']);

        Route::post('/{schedule}/tasks', [Client\External\Servers\ScheduleTaskController::class, 'store']);
        Route::post('/{schedule}/tasks/{task}', [Client\External\Servers\ScheduleTaskController::class, 'update']);
        Route::delete('/{schedule}/tasks/{task}', [Client\External\Servers\ScheduleTaskController::class, 'delete']);
    });

    Route::group(['prefix' => '/network'], function () {
        Route::get('/allocations', [Client\External\Servers\NetworkAllocationController::class, 'index']);
        Route::post('/allocations', [Client\External\Servers\NetworkAllocationController::class, 'store']);
        Route::post('/allocations/{allocation}', [Client\External\Servers\NetworkAllocationController::class, 'update']);
        Route::post('/allocations/{allocation}/primary', [Client\External\Servers\NetworkAllocationController::class, 'setPrimary']);
        Route::delete('/allocations/{allocation}', [Client\External\Servers\NetworkAllocationController::class, 'delete']);
    });

    Route::group(['prefix' => '/users'], function () {
        Route::get('/', [Client\External\Servers\SubuserController::class, 'index']);
        Route::post('/', [Client\External\Servers\SubuserController::class, 'store']);
        Route::get('/{externalUser}', [Client\External\Servers\SubuserController::class, 'view']);
        Route::post('/{externalUser}', [Client\External\Servers\SubuserController::class, 'update']);
        Route::delete('/{externalUser}', [Client\External\Servers\SubuserController::class, 'delete']);
    });

    Route::group(['prefix' => '/backups'], function () {
        Route::get('/', [Client\External\Servers\BackupController::class, 'index']);
        Route::post('/', [Client\External\Servers\BackupController::class, 'store']);
        Route::get('/{backup}', [Client\External\Servers\BackupController::class, 'view']);
        Route::get('/{backup}/download', [Client\External\Servers\BackupController::class, 'download']);
        Route::post('/{backup}/lock', [Client\External\Servers\BackupController::class, 'toggleLock']);
        Route::post('/{backup}/restore', [Client\External\Servers\BackupController::class, 'restore']);
        Route::delete('/{backup}', [Client\External\Servers\BackupController::class, 'delete']);
    });

    Route::group(['prefix' => '/startup'], function () {
        Route::get('/', [Client\External\Servers\StartupController::class, 'index']);
        Route::put('/variable', [Client\External\Servers\StartupController::class, 'update']);
    });

    Route::group(['prefix' => '/settings'], function () {
        Route::post('/rename', [Client\External\Servers\SettingsController::class, 'rename']);
        Route::post('/reinstall', [Client\External\Servers\SettingsController::class, 'reinstall']);
        Route::put('/docker-image', [Client\External\Servers\SettingsController::class, 'dockerImage']);
    });
});

Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::get('/', [Client\Servers\ServerController::class, 'index'])->name('api:client:server.view');
    Route::get('/websocket', Client\Servers\WebsocketController::class)->name('api:client:server.ws');
    Route::get('/resources', Client\Servers\ResourceUtilizationController::class)->name('api:client:server.resources');
    Route::get('/activity', Client\Servers\ActivityLogController::class)->name('api:client:server.activity');

    Route::post('/command', [Client\Servers\CommandController::class, 'index']);
    Route::post('/power', [Client\Servers\PowerController::class, 'index']);
    Route::get('/region', Client\Servers\RegionController::class);

    Route::group(['prefix' => '/mcplugins'], function () {
        Route::get('/', [Client\Servers\MCPlugins\PluginsManagerController::class, 'index']);
        Route::get('/version', [Client\Servers\MCPlugins\PluginVersionsController::class, 'index']);
        Route::post('/install', [Client\Servers\MCPlugins\InstallPluginsController::class, 'index']);
        Route::get('/settings', [Client\Servers\MCPlugins\MCPluginsSettingsController::class, 'index']);
    });

    Route::group(['prefix' => '/playermanager'], function () {
        Route::get('/', [Client\Servers\PlayerManagerController::class, 'index']);
        Route::post('/command', [Client\Servers\PlayerManagerController::class, 'runCommand']);
    });

    Route::group(['prefix' => '/databases'], function () {
        Route::get('/', [Client\Servers\DatabaseController::class, 'index']);
        Route::post('/', [Client\Servers\DatabaseController::class, 'store']);
        Route::post('/{database}/rotate-password', [Client\Servers\DatabaseController::class, 'rotatePassword']);
        Route::delete('/{database}', [Client\Servers\DatabaseController::class, 'delete']);
    });

    Route::group(['prefix' => '/files'], function () {
        Route::get('/list', [Client\Servers\FileController::class, 'directory']);
        Route::get('/contents', [Client\Servers\FileController::class, 'contents']);
        Route::get('/download', [Client\Servers\FileController::class, 'download']);
        Route::put('/rename', [Client\Servers\FileController::class, 'rename']);
        Route::post('/copy', [Client\Servers\FileController::class, 'copy']);
        Route::post('/write', [Client\Servers\FileController::class, 'write']);
        Route::post('/compress', [Client\Servers\FileController::class, 'compress']);
        Route::post('/decompress', [Client\Servers\FileController::class, 'decompress']);
        Route::post('/delete', [Client\Servers\FileController::class, 'delete']);
        Route::post('/create-folder', [Client\Servers\FileController::class, 'create']);
        Route::post('/chmod', [Client\Servers\FileController::class, 'chmod']);
        Route::post('/pull', [Client\Servers\FileController::class, 'pull'])->middleware(['throttle:10,5']);
        Route::get('/upload', Client\Servers\FileUploadController::class);
    });

    Route::group(['prefix' => '/schedules'], function () {
        Route::get('/', [Client\Servers\ScheduleController::class, 'index']);
        Route::post('/', [Client\Servers\ScheduleController::class, 'store']);
        Route::get('/{schedule}', [Client\Servers\ScheduleController::class, 'view']);
        Route::post('/{schedule}', [Client\Servers\ScheduleController::class, 'update']);
        Route::post('/{schedule}/execute', [Client\Servers\ScheduleController::class, 'execute']);
        Route::delete('/{schedule}', [Client\Servers\ScheduleController::class, 'delete']);

        Route::post('/{schedule}/tasks', [Client\Servers\ScheduleTaskController::class, 'store']);
        Route::post('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'update']);
        Route::delete('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'delete']);
    });

    Route::group(['prefix' => '/network'], function () {
        Route::get('/allocations', [Client\Servers\NetworkAllocationController::class, 'index']);
        Route::post('/allocations', [Client\Servers\NetworkAllocationController::class, 'store']);
        Route::post('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'update']);
        Route::post('/allocations/{allocation}/primary', [Client\Servers\NetworkAllocationController::class, 'setPrimary']);
        Route::delete('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'delete']);
    });

    Route::group(['prefix' => '/users'], function () {
        Route::get('/', [Client\Servers\SubuserController::class, 'index']);
        Route::post('/', [Client\Servers\SubuserController::class, 'store']);
        Route::get('/{user}', [Client\Servers\SubuserController::class, 'view']);
        Route::post('/{user}', [Client\Servers\SubuserController::class, 'update']);
        Route::delete('/{user}', [Client\Servers\SubuserController::class, 'delete']);
    });

    Route::group(['prefix' => '/backups'], function () {
        Route::get('/', [Client\Servers\BackupController::class, 'index']);
        Route::post('/', [Client\Servers\BackupController::class, 'store']);
        Route::get('/{backup}', [Client\Servers\BackupController::class, 'view']);
        Route::get('/{backup}/download', [Client\Servers\BackupController::class, 'download']);
        Route::post('/{backup}/lock', [Client\Servers\BackupController::class, 'toggleLock']);
        Route::post('/{backup}/restore', [Client\Servers\BackupController::class, 'restore']);
        Route::delete('/{backup}', [Client\Servers\BackupController::class, 'delete']);
    });

    Route::group(['prefix' => '/startup'], function () {
        Route::get('/', [Client\Servers\StartupController::class, 'index']);
        Route::put('/variable', [Client\Servers\StartupController::class, 'update']);
    });

    Route::group(['prefix' => '/settings'], function () {
        Route::post('/rename', [Client\Servers\SettingsController::class, 'rename']);
        Route::post('/reinstall', [Client\Servers\SettingsController::class, 'reinstall']);
        Route::put('/docker-image', [Client\Servers\SettingsController::class, 'dockerImage']);
    });
});
