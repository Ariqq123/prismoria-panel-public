<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\Settings\AutoBackupsSettingsFormRequest;
use Pterodactyl\Services\AutoBackups\AutoBackupGlobalSettingsService;

class AutoBackupsController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private AutoBackupGlobalSettingsService $globalSettings,
        private ViewFactory $view
    ) {
    }

    public function index(): View
    {
        return $this->view->make('admin.settings.auto-backups', [
            'settings' => $this->globalSettings->all(),
        ]);
    }

    public function update(AutoBackupsSettingsFormRequest $request): RedirectResponse
    {
        $this->globalSettings->updateFromAdminPayload($request->normalize());

        $this->alert
            ->success('Global auto backup settings were updated successfully.')
            ->flash();

        return redirect()->route('admin.settings.auto-backups');
    }
}

