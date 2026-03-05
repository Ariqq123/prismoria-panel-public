<?php

use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\votifiertester;

Route::group([
	'prefix' => '/servers/{server}',
	'middleware' => [
		ServerSubject::class,
		AuthenticateServerAccess::class,
		ResourceBelongsToServer::class,
	],
], function () {
	Route::post('/classic', [votifiertester\VotiferController::class, 'sendClassic']);
    Route::post('/nu', [votifiertester\VotiferController::class, 'sendNu']);
    Route::post('/nu/v2', [votifiertester\VotiferController::class, 'sendNuV2']);
});
