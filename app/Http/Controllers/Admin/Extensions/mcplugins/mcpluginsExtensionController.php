<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\mcplugins;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;

class mcpluginsExtensionController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.mcplugins');
    }

    public function update(Request $request): RedirectResponse
    {
        return redirect()->route('admin.mcplugins');
    }

    public function post(Request $request): RedirectResponse
    {
        return redirect()->route('admin.mcplugins');
    }

    public function put(Request $request): RedirectResponse
    {
        return redirect()->route('admin.mcplugins');
    }

    public function delete(string $target, string $id): RedirectResponse
    {
        return redirect()->route('admin.mcplugins');
    }
}
