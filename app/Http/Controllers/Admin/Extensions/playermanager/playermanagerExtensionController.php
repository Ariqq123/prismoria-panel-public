<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\playermanager;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\Http\Controllers\Controller;

class playermanagerExtensionController extends Controller
{
    public function __construct(private ViewFactory $view)
    {
    }

    public function index(): View
    {
        return $this->view->make('admin.extensions.playermanager.index');
    }

    public function update(Request $request): RedirectResponse
    {
        return redirect()->route('admin.extensions.playermanager.index');
    }

    public function post(Request $request): RedirectResponse
    {
        return redirect()->route('admin.extensions.playermanager.index');
    }

    public function put(Request $request): RedirectResponse
    {
        return redirect()->route('admin.extensions.playermanager.index');
    }

    public function delete(string $target, string $id): RedirectResponse
    {
        return redirect()->route('admin.extensions.playermanager.index');
    }
}
