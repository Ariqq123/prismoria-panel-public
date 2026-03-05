<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Traits\Helpers\AvailableLanguages;
use Pterodactyl\Services\Helpers\SoftwareVersionService;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Requests\Admin\Settings\BaseSettingsFormRequest;

class IndexController extends Controller
{
    use AvailableLanguages;

    private const BACKGROUND_IMAGE_KEY = 'settings::pterodactyl:ui:background_image';

    private const BACKGROUND_IMAGE_DIRECTORY = 'panel-backgrounds';

    /**
     * IndexController constructor.
     */
    public function __construct(
        private AlertsMessageBag $alert,
        private Kernel $kernel,
        private SettingsRepositoryInterface $settings,
        private SoftwareVersionService $versionService,
        private ViewFactory $view
    ) {
    }

    /**
     * Render the UI for basic Panel settings.
     */
    public function index(): View
    {
        return $this->view->make('admin.settings.index', [
            'version' => $this->versionService,
            'languages' => $this->getAvailableLanguages(true),
        ]);
    }

    /**
     * Handle settings update.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function update(BaseSettingsFormRequest $request): RedirectResponse
    {
        $data = $request->normalize();
        unset(
            $data['pterodactyl:ui:background_image'],
            $data['pterodactyl:ui:background_image_remove']
        );

        $currentBackgroundImage = $this->settings->get(self::BACKGROUND_IMAGE_KEY, '');
        if ($request->hasFile('pterodactyl:ui:background_image')) {
            $this->settings->set(
                self::BACKGROUND_IMAGE_KEY,
                $this->storeBackgroundImage($request->file('pterodactyl:ui:background_image'))
            );
            $this->deleteManagedBackgroundImage(is_string($currentBackgroundImage) ? $currentBackgroundImage : null);
        } elseif ($request->boolean('pterodactyl:ui:background_image_remove')) {
            $this->settings->set(self::BACKGROUND_IMAGE_KEY, null);
            $this->deleteManagedBackgroundImage(is_string($currentBackgroundImage) ? $currentBackgroundImage : null);
        }

        foreach ($data as $key => $value) {
            $this->settings->set('settings::' . $key, $value);
        }

        $this->kernel->call('queue:restart');
        $this->alert->success('Panel settings have been updated successfully and the queue worker was restarted to apply these changes.')->flash();

        return redirect()->route('admin.settings');
    }

    protected function storeBackgroundImage(UploadedFile $file): string
    {
        $path = $file->store(self::BACKGROUND_IMAGE_DIRECTORY, 'public');

        return '/storage/' . ltrim($path, '/');
    }

    protected function deleteManagedBackgroundImage(?string $backgroundImage): void
    {
        $path = $this->extractManagedBackgroundImagePath($backgroundImage);
        if (is_null($path)) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    protected function extractManagedBackgroundImagePath(?string $backgroundImage): ?string
    {
        if (is_null($backgroundImage) || $backgroundImage === '') {
            return null;
        }

        $path = parse_url($backgroundImage, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $backgroundImage;
        }

        $normalizedPath = ltrim($path, '/');
        $expectedPrefix = 'storage/' . self::BACKGROUND_IMAGE_DIRECTORY . '/';
        if (!Str::startsWith($normalizedPath, $expectedPrefix)) {
            return null;
        }

        return Str::after($normalizedPath, 'storage/');
    }
}
