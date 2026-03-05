<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\pterodactylregion;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;

class pterodactylregionExtensionController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.settings.pterodactyl-region');
    }

    public function update(Request $request): RedirectResponse
    {
        return redirect()->route('admin.settings.pterodactyl-region');
    }

    public function post(Request $request): RedirectResponse
    {
        return redirect()->route('admin.settings.pterodactyl-region');
    }

    public function put(Request $request): RedirectResponse
    {
        return redirect()->route('admin.settings.pterodactyl-region');
    }

    public function delete(string $target, string $id): RedirectResponse
    {
        return redirect()->route('admin.settings.pterodactyl-region');
    }
}
