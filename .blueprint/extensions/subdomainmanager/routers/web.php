<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Http\Controllers\Admin\Extensions\subdomainmanager\subdomainmanagerExtensionController;

/*
|--------------------------------------------------------------------------
| SubDomain Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /admin/subdomain
|
*/
Route::group(['prefix' => '/admin', 'middleware' => [AdminAuthenticate::class]], function () {
    Route::get('/', [subdomainmanagerExtensionController::class, 'index'])->name('admin.subdomain');
    Route::get('/new', [subdomainmanagerExtensionController::class, 'new'])->name('admin.subdomain.new');
    Route::get('/edit/{id}', [subdomainmanagerExtensionController::class, 'edit'])->name('admin.subdomain.edit');

    Route::post('/settings', [subdomainmanagerExtensionController::class, 'settings'])->name('admin.subdomain.settings');
    Route::post('/create', [subdomainmanagerExtensionController::class, 'create'])->name('admin.subdomain.create');
    Route::post('/update/{id}', [subdomainmanagerExtensionController::class, 'update'])->name('admin.subdomain.update');

    Route::delete('/delete', [subdomainmanagerExtensionController::class, 'delete'])->name('admin.subdomain.delete');
});
