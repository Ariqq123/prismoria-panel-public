<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\BlueprintFramework\Extensions\subdomainmanager\subdomainmanagerExtensionClientController;

Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::group(['prefix' => '/subdomain'], function () {
        Route::get('/', [subdomainmanagerExtensionClientController::class, 'index']);
        Route::get('/min3/checking', [subdomainmanagerExtensionClientController::class, 'min3Checking']);
        Route::post('/create', [subdomainmanagerExtensionClientController::class, 'create']);
        Route::delete('/delete/{id}', [subdomainmanagerExtensionClientController::class, 'delete']);
    });
});
