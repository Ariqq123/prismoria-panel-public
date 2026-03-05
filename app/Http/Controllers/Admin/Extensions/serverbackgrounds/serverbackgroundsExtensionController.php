<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\serverbackgrounds;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\ExternalServerCache;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Server Backgrounds admin controller.
 *
 * This addon stores per-server and per-egg background settings in Blueprint's key/value store.
 *
 * Keys:
 * - server_background_{identifier}_image_url / server_background_{identifier}_opacity
 * - egg_background_{id}_image_url / egg_background_{id}_opacity
 *
 * For performance, the addon also keeps index keys so it doesn't have to scan every server/egg:
 * - server_background_index (JSON array of server identifiers)
 * - egg_background_index (JSON array of egg IDs)
 */
class serverbackgroundsExtensionController extends Controller
{
    private const NAMESPACE = 'serverbackgrounds';

    private const KEY_DISABLE_FOR_ADMINS = 'disable_for_admins';

    private const KEY_SERVER_INDEX = 'server_background_index';
    private const KEY_EGG_INDEX = 'egg_background_index';
    private const KEY_USER_SERVER_INDEX_PREFIX = 'user_server_background_index_';
    private const USER_BACKGROUND_UPLOAD_DIRECTORY = 'server-backgrounds/user-uploads';

    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
        private ConfigRepository $config,
        private SettingsRepositoryInterface $settings,
    ) {}

    public function index(Request $request)
    {
        $this->assertRootAdmin($request);
        $this->initializeDefaultSettings();

        // Only select fields needed by the view and scripts to reduce memory usage.
        $eggs = Egg::query()->select(['id', 'name'])->orderBy('name')->get();
        $servers = Server::query()->select(['uuid', 'name', 'egg_id'])->orderBy('name')->get();
        $externalServers = $this->fetchAvailableExternalServers();

        return $this->view->make('admin.extensions.serverbackgrounds.index', [
            'eggs' => $eggs,
            'servers' => $servers,
            'externalServers' => $externalServers,
            'configuredEggs' => $this->fetchConfiguredEggBackgrounds(),
            'configuredServers' => $this->fetchConfiguredServerBackgrounds(),
            'blueprint' => $this->blueprint,
        ]);
    }

    /**
     * Client settings used by the dashboard wrapper script.
     */
    public function getSettings(Request $request)
    {
        $rawDisable = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_DISABLE_FOR_ADMINS);
        $disableForAdmins = $rawDisable === null || $rawDisable === '' ? false : (bool) ((int) $rawDisable);

        $user = $request->user();
        $userIsAdmin = $user && (bool) $user->root_admin;

        return [
            'disable_for_admins' => $disableForAdmins,
            'user_is_admin' => $userIsAdmin,
        ];
    }

    /**
     * Returns server backgrounds merged for the current user:
     * global/admin backgrounds + per-user overrides.
     */
    public function fetchEffectiveServerBackgrounds(Request $request): array
    {
        $configured = $this->fetchConfiguredServerBackgrounds();

        /** @var \Pterodactyl\Models\User|null $user */
        $user = $request->user();
        if (is_null($user)) {
            return $configured;
        }

        $userConfigured = $this->fetchConfiguredUserServerBackgrounds($user);
        if ($userConfigured === []) {
            return $configured;
        }

        $merged = [];

        foreach ($configured as $item) {
            $identifier = (string) ($item->identifier ?? $item->uuid ?? '');
            if ($identifier !== '') {
                $merged[$identifier] = $item;
            }
        }

        foreach ($userConfigured as $item) {
            $identifier = (string) ($item->identifier ?? $item->uuid ?? '');
            if ($identifier !== '') {
                $merged[$identifier] = $item;
            }
        }

        return array_values($merged);
    }

    /**
     * Returns the current user's custom background value for a specific server.
     */
    public function getUserServerBackground(Request $request): array
    {
        /** @var \Pterodactyl\Models\User|null $user */
        $user = $request->user();
        if (is_null($user)) {
            throw new AccessDeniedHttpException();
        }

        $request->validate([
            'server_id' => 'required|string|max:191',
        ]);

        $serverIdentifier = $this->normalizeServerIdentifier($request->input('server_id'));
        if (is_null($serverIdentifier)) {
            throw ValidationException::withMessages([
                'server_id' => 'The selected server identifier is invalid.',
            ]);
        }

        $this->assertUserCanAccessServerIdentifier($user, $serverIdentifier);

        return [
            'object' => 'server_background',
            'attributes' => $this->userServerBackgroundAttributes($user->id, $serverIdentifier),
        ];
    }

    /**
     * Stores or removes the current user's custom background value for a specific server.
     */
    public function upsertUserServerBackground(Request $request): array
    {
        /** @var \Pterodactyl\Models\User|null $user */
        $user = $request->user();
        if (is_null($user)) {
            throw new AccessDeniedHttpException();
        }

        $request->validate([
            'server_id' => 'required|string|max:191',
            'image_url' => 'nullable|url|max:2048',
        ]);

        $serverIdentifier = $this->normalizeServerIdentifier($request->input('server_id'));
        if (is_null($serverIdentifier)) {
            throw ValidationException::withMessages([
                'server_id' => 'The selected server identifier is invalid.',
            ]);
        }

        $this->assertUserCanAccessServerIdentifier($user, $serverIdentifier);

        $imageUrl = trim((string) $request->input('image_url', ''));
        $this->applyUserServerBackground($user->id, $serverIdentifier, $imageUrl);

        return [
            'object' => 'server_background',
            'attributes' => $this->userServerBackgroundAttributes($user->id, $serverIdentifier),
        ];
    }

    /**
     * Uploads a user-managed background media file (image/gif/video) and applies it to one server.
     */
    public function uploadUserServerBackground(Request $request): array
    {
        /** @var \Pterodactyl\Models\User|null $user */
        $user = $request->user();
        if (is_null($user)) {
            throw new AccessDeniedHttpException();
        }

        $request->validate([
            'server_id' => 'required|string|max:191',
            'background_file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,mp4,webm|max:51200',
        ]);

        $serverIdentifier = $this->normalizeServerIdentifier($request->input('server_id'));
        if (is_null($serverIdentifier)) {
            throw ValidationException::withMessages([
                'server_id' => 'The selected server identifier is invalid.',
            ]);
        }

        $this->assertUserCanAccessServerIdentifier($user, $serverIdentifier);

        /** @var \Illuminate\Http\UploadedFile $backgroundFile */
        $backgroundFile = $request->file('background_file');
        $storedPath = $this->storeUserBackgroundUpload($backgroundFile, $user->id);
        $publicUrl = '/storage/' . ltrim($storedPath, '/');

        $this->applyUserServerBackground($user->id, $serverIdentifier, $publicUrl, $storedPath);

        return [
            'object' => 'server_background',
            'attributes' => $this->userServerBackgroundAttributes($user->id, $serverIdentifier),
        ];
    }

    /**
     * Performance option: when enabled, root admins will not see server backgrounds.
     * This can significantly reduce dashboard load time for installations with many servers.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'disable_for_admins' => 'required|in:0,1',
        ]);

        $this->blueprint->dbSet(
            self::NAMESPACE,
            self::KEY_DISABLE_FOR_ADMINS,
            $request->boolean('disable_for_admins', false) ? '1' : '0'
        );

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }

    public function bulkSaveBackgrounds(Request $request): RedirectResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'backgrounds' => 'required|array',
            'backgrounds.*.server_id' => 'nullable|string|max:191',
            'backgrounds.*.egg_id' => 'nullable|exists:eggs,id',
            'backgrounds.*.image_url' => 'required|url',
            'backgrounds.*.opacity' => 'nullable|numeric|min:0|max:1',
        ]);
        $this->assertValidServerIdentifierInputs($request->input('backgrounds', []));

        $serverIndex = $this->getServerIndex();
        $eggIndex = $this->getEggIndex();

        foreach ($request->input('backgrounds') as $background) {
            $serverIdentifier = $this->normalizeServerIdentifier($background['server_id'] ?? null);
            $eggId = $background['egg_id'] ?? null;
            $imageUrl = (string) $background['image_url'];
            $opacity = $background['opacity'] ?? 1;

            if (!is_null($serverIdentifier)) {
                $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverIdentifier}_image_url", $imageUrl);
                $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverIdentifier}_opacity", (string) $opacity);

                if (!in_array($serverIdentifier, $serverIndex, true)) {
                    $serverIndex[] = $serverIdentifier;
                }

                continue;
            }

            if ($eggId) {
                $eggKey = (string) $eggId;

                $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_image_url", $imageUrl);
                $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_opacity", (string) $opacity);

                if (!in_array($eggKey, $eggIndex, true)) {
                    $eggIndex[] = $eggKey;
                }
            }
        }

        $this->setServerIndex($serverIndex);
        $this->setEggIndex($eggIndex);

        return redirect()->back()->with('success', 'Background images saved successfully.');
    }

    public function updateAndDeleteBackgroundSettings(Request $request): RedirectResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'backgrounds' => 'array',
            'backgrounds.*.server_id' => 'nullable|string|max:191',
            'backgrounds.*.egg_id' => 'nullable|exists:eggs,id',
            'backgrounds.*.image_url' => 'nullable|url',
            'backgrounds.*.opacity' => 'nullable|numeric|min:0|max:1',
            'delete_backgrounds' => 'array',
            'delete_backgrounds.*' => 'string',
        ]);
        $this->assertValidServerIdentifierInputs($request->input('backgrounds', []));

        $serverIndex = $this->getServerIndex();
        $eggIndex = $this->getEggIndex();

        foreach ($request->input('backgrounds', []) as $background) {
            $serverIdentifier = $this->normalizeServerIdentifier($background['server_id'] ?? null);
            $eggId = $background['egg_id'] ?? null;
            $imageUrl = $background['image_url'] ?? null;
            $opacity = $background['opacity'] ?? 1;

            if (!is_null($serverIdentifier)) {
                if ($imageUrl) {
                    $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverIdentifier}_image_url", (string) $imageUrl);
                    if (!in_array($serverIdentifier, $serverIndex, true)) {
                        $serverIndex[] = $serverIdentifier;
                    }
                }

                $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverIdentifier}_opacity", (string) $opacity);
                continue;
            }

            if ($eggId) {
                $eggKey = (string) $eggId;

                if ($imageUrl) {
                    $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_image_url", (string) $imageUrl);
                    if (!in_array($eggKey, $eggIndex, true)) {
                        $eggIndex[] = $eggKey;
                    }
                }

                $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_opacity", (string) $opacity);
            }
        }

        if ($request->has('delete_backgrounds')) {
            foreach ($request->input('delete_backgrounds') as $id) {
                $identifier = $this->normalizeServerIdentifier($id);
                if (!is_null($identifier) && ($this->isExternalIdentifier($identifier) || Server::where('uuid', $identifier)->exists())) {
                    $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$identifier}_image_url", '');
                    $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$identifier}_opacity", '');
                    $serverIndex = array_values(array_filter($serverIndex, fn ($uuid) => $uuid !== $identifier));
                    continue;
                }

                if (Egg::where('id', $id)->exists()) {
                    $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$id}_image_url", '');
                    $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$id}_opacity", '');
                    $eggIndex = array_values(array_filter($eggIndex, fn ($eggId) => $eggId !== (string) $id));
                }
            }
        }

        $this->setServerIndex($serverIndex);
        $this->setEggIndex($eggIndex);

        return redirect()->back()->with('success', 'Background settings updated successfully.');
    }

    /**
     * Returns configured server backgrounds for use by the dashboard wrapper script and admin UI.
     */
    public function fetchConfiguredServerBackgrounds(): array
    {
        $serverIndex = $this->getServerIndex();
        if ($serverIndex === []) {
            return [];
        }

        $localServerIdentifiers = [];
        $externalServerIdentifiers = [];
        foreach ($serverIndex as $identifier) {
            if ($this->isExternalIdentifier($identifier)) {
                $externalServerIdentifiers[] = $identifier;
                continue;
            }

            $localServerIdentifiers[] = $identifier;
        }

        $localServers = Server::query()
            ->select(['uuid', 'name'])
            ->whereIn('uuid', $localServerIdentifiers)
            ->get()
            ->keyBy('uuid');
        $externalServers = $this->externalServerCacheByCompositeIdentifier($externalServerIdentifiers);

        $configured = [];

        foreach ($serverIndex as $serverIdentifier) {
            $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "server_background_{$serverIdentifier}_image_url", '');
            if ($imageUrl === '') {
                continue;
            }

            $opacity = $this->blueprint->dbGet(self::NAMESPACE, "server_background_{$serverIdentifier}_opacity", 1);
            $name = $serverIdentifier;
            $isExternal = $this->isExternalIdentifier($serverIdentifier);

            if (!$isExternal && isset($localServers[$serverIdentifier])) {
                $name = (string) $localServers[$serverIdentifier]->name;
            } elseif ($isExternal && isset($externalServers[$serverIdentifier])) {
                $cache = $externalServers[$serverIdentifier];
                $connectionName = trim((string) ($cache->connection?->name ?? ''));
                $serverName = trim((string) $cache->name);
                $name = $connectionName !== '' ? sprintf('[%s] %s', $connectionName, $serverName) : $serverName;
            }

            $configured[] = (object) [
                'uuid' => $serverIdentifier,
                'identifier' => $serverIdentifier,
                'name' => $name,
                'image_url' => $imageUrl,
                'opacity' => $opacity,
                'is_external' => $isExternal,
            ];
        }

        return $configured;
    }

    /**
     * Returns configured egg backgrounds for use by the dashboard wrapper script and admin UI.
     */
    public function fetchConfiguredEggBackgrounds(): array
    {
        $eggIndex = $this->getEggIndex();
        if ($eggIndex === []) {
            return [];
        }

        $eggIds = array_map('intval', $eggIndex);
        $eggs = Egg::query()->select(['id', 'name'])->whereIn('id', $eggIds)->get();

        $configured = [];

        foreach ($eggs as $egg) {
            $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "egg_background_{$egg->id}_image_url", '');
            if ($imageUrl === '') {
                continue;
            }

            $opacity = $this->blueprint->dbGet(self::NAMESPACE, "egg_background_{$egg->id}_opacity", 1);

            $configured[] = (object) [
                'id' => $egg->id,
                'name' => $egg->name,
                'image_url' => $imageUrl,
                'opacity' => $opacity,
            ];
        }

        return $configured;
    }

    /**
     * Returns configured user-specific server backgrounds.
     *
     * @return array<int, object>
     */
    private function fetchConfiguredUserServerBackgrounds(User $user): array
    {
        $serverIndex = $this->getUserServerIndex($user->id);
        if ($serverIndex === []) {
            return [];
        }

        $localServerIdentifiers = [];
        $externalServerIdentifiers = [];
        foreach ($serverIndex as $identifier) {
            if ($this->isExternalIdentifier($identifier)) {
                $parts = $this->parseExternalIdentifier($identifier);
                if (is_null($parts) || !$user->externalPanelConnections()->where('id', $parts['connection_id'])->exists()) {
                    continue;
                }

                $externalServerIdentifiers[] = $identifier;
                continue;
            }

            if ($user->accessibleServers()->where(function (Builder $builder) use ($identifier) {
                $builder->where('uuid', $identifier)->orWhere('uuidShort', $identifier);
            })->exists()) {
                $localServerIdentifiers[] = $identifier;
            }
        }

        $localServers = Server::query()
            ->select(['uuid', 'name'])
            ->whereIn('uuid', $localServerIdentifiers)
            ->get()
            ->keyBy('uuid');
        $externalServers = $this->externalServerCacheByCompositeIdentifier($externalServerIdentifiers);

        $configured = [];

        foreach ($serverIndex as $serverIdentifier) {
            $imageUrl = (string) $this->blueprint->dbGet(
                self::NAMESPACE,
                $this->userServerBackgroundImageUrlKey($user->id, $serverIdentifier),
                ''
            );
            if ($imageUrl === '') {
                continue;
            }

            $opacity = $this->blueprint->dbGet(
                self::NAMESPACE,
                $this->userServerBackgroundOpacityKey($user->id, $serverIdentifier),
                1
            );
            $name = $serverIdentifier;
            $isExternal = $this->isExternalIdentifier($serverIdentifier);

            if (!$isExternal && isset($localServers[$serverIdentifier])) {
                $name = (string) $localServers[$serverIdentifier]->name;
            } elseif ($isExternal && isset($externalServers[$serverIdentifier])) {
                $cache = $externalServers[$serverIdentifier];
                $connectionName = trim((string) ($cache->connection?->name ?? ''));
                $serverName = trim((string) $cache->name);
                $name = $connectionName !== '' ? sprintf('[%s] %s', $connectionName, $serverName) : $serverName;
            }

            $configured[] = (object) [
                'uuid' => $serverIdentifier,
                'identifier' => $serverIdentifier,
                'name' => $name,
                'image_url' => $imageUrl,
                'opacity' => $opacity,
                'is_external' => $isExternal,
                'is_user_specific' => true,
            ];
        }

        return $configured;
    }

    private function initializeDefaultSettings(): void
    {
        $current = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_DISABLE_FOR_ADMINS);
        if ($current === null || $current === '') {
            $this->blueprint->dbSet(self::NAMESPACE, self::KEY_DISABLE_FOR_ADMINS, '0');
        }

        // If indexes are missing, keep them null until first use so we can migrate legacy installs.
    }

    private function assertRootAdmin(Request $request): void
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException();
        }
    }

    private function getServerIndex(): array
    {
        $raw = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_SERVER_INDEX);
        if ($raw === null) {
            $index = $this->buildServerIndexFromLegacy();
            $this->setServerIndex($index);
            return $index;
        }

        return $this->decodeIndex($raw);
    }

    private function setServerIndex(array $uuids): void
    {
        $this->blueprint->dbSet(self::NAMESPACE, self::KEY_SERVER_INDEX, $this->encodeIndex($uuids));
    }

    private function getEggIndex(): array
    {
        $raw = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_EGG_INDEX);
        if ($raw === null) {
            $index = $this->buildEggIndexFromLegacy();
            $this->setEggIndex($index);
            return $index;
        }

        return $this->decodeIndex($raw);
    }

    private function setEggIndex(array $eggIds): void
    {
        $this->blueprint->dbSet(self::NAMESPACE, self::KEY_EGG_INDEX, $this->encodeIndex($eggIds));
    }

    private function getUserServerIndex(int $userId): array
    {
        $raw = $this->blueprint->dbGet(self::NAMESPACE, $this->userServerIndexKey($userId));

        return $this->decodeIndex($raw);
    }

    private function setUserServerIndex(int $userId, array $identifiers): void
    {
        $this->blueprint->dbSet(self::NAMESPACE, $this->userServerIndexKey($userId), $this->encodeIndex($identifiers));
    }

    private function buildServerIndexFromLegacy(): array
    {
        $index = [];

        Server::query()->select(['uuid'])->chunk(500, function ($servers) use (&$index) {
            foreach ($servers as $server) {
                $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "server_background_{$server->uuid}_image_url", '');
                if ($imageUrl !== '') {
                    $index[] = $server->uuid;
                }
            }
        });

        return array_values(array_unique($index));
    }

    private function buildEggIndexFromLegacy(): array
    {
        $index = [];

        Egg::query()->select(['id'])->chunk(200, function ($eggs) use (&$index) {
            foreach ($eggs as $egg) {
                $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "egg_background_{$egg->id}_image_url", '');
                if ($imageUrl !== '') {
                    $index[] = (string) $egg->id;
                }
            }
        });

        return array_values(array_unique($index));
    }

    private function decodeIndex(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $value) {
            if (is_int($value)) {
                $out[] = (string) $value;
                continue;
            }

            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private function encodeIndex(array $values): string
    {
        $out = [];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $out[] = $value;
        }

        $out = array_values(array_unique($out));

        $json = json_encode($out, JSON_UNESCAPED_SLASHES);
        return $json === false ? '[]' : $json;
    }

    private function fetchAvailableExternalServers(): array
    {
        $items = [];
        $caches = ExternalServerCache::query()
            ->with('connection:id,name')
            ->orderBy('name')
            ->get([
                'external_panel_connection_id',
                'external_server_identifier',
                'name',
            ]);

        foreach ($caches as $cache) {
            $serverIdentifier = trim((string) $cache->external_server_identifier);
            if ($serverIdentifier === '') {
                continue;
            }

            $identifier = sprintf('external:%d:%s', $cache->external_panel_connection_id, $serverIdentifier);
            $connectionName = trim((string) ($cache->connection?->name ?? ''));
            $serverName = trim((string) $cache->name);
            $label = $connectionName !== ''
                ? sprintf('[%s] %s (%s)', $connectionName, $serverName, $identifier)
                : sprintf('%s (%s)', $serverName, $identifier);

            $items[] = [
                'id' => $identifier,
                'name' => $label,
            ];
        }

        usort($items, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * @return array<string, \Pterodactyl\Models\ExternalServerCache>
     */
    private function externalServerCacheByCompositeIdentifier(array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $connectionIds = [];
        $serverIdentifiers = [];
        $requested = [];

        foreach ($identifiers as $identifier) {
            $parts = $this->parseExternalIdentifier($identifier);
            if (is_null($parts)) {
                continue;
            }

            $connectionIds[] = $parts['connection_id'];
            $serverIdentifiers[] = $parts['server_identifier'];
            $requested[$identifier] = true;
        }

        if ($requested === []) {
            return [];
        }

        $rows = ExternalServerCache::query()
            ->with('connection:id,name')
            ->whereIn('external_panel_connection_id', array_values(array_unique($connectionIds)))
            ->whereIn('external_server_identifier', array_values(array_unique($serverIdentifiers)))
            ->get();

        $mapped = [];
        foreach ($rows as $row) {
            $compositeIdentifier = sprintf(
                'external:%d:%s',
                $row->external_panel_connection_id,
                $row->external_server_identifier
            );

            if (isset($requested[$compositeIdentifier])) {
                $mapped[$compositeIdentifier] = $row;
            }
        }

        return $mapped;
    }

    private function assertValidServerIdentifierInputs(array $backgrounds): void
    {
        foreach ($backgrounds as $index => $background) {
            if (!is_array($background)) {
                continue;
            }

            $serverIdentifier = $this->normalizeServerIdentifier($background['server_id'] ?? null);
            if (is_null($serverIdentifier)) {
                continue;
            }

            if ($this->isExternalIdentifier($serverIdentifier) || Server::where('uuid', $serverIdentifier)->exists()) {
                continue;
            }

            throw ValidationException::withMessages([
                "backgrounds.$index.server_id" => 'The selected server identifier is invalid.',
            ]);
        }
    }

    private function assertUserCanAccessServerIdentifier(User $user, string $serverIdentifier): void
    {
        if ($this->isExternalIdentifier($serverIdentifier)) {
            $parts = $this->parseExternalIdentifier($serverIdentifier);
            if (is_null($parts)) {
                throw ValidationException::withMessages([
                    'server_id' => 'The selected server identifier is invalid.',
                ]);
            }

            $hasAccess = $user->externalPanelConnections()
                ->where('id', $parts['connection_id'])
                ->exists();
            if (!$hasAccess) {
                throw new AccessDeniedHttpException();
            }

            return;
        }

        $hasAccess = $user->accessibleServers()
            ->where(function (Builder $builder) use ($serverIdentifier) {
                $builder->where('uuid', $serverIdentifier)->orWhere('uuidShort', $serverIdentifier);
            })
            ->exists();

        if (!$hasAccess) {
            throw new AccessDeniedHttpException();
        }
    }

    private function applyUserServerBackground(
        int $userId,
        string $serverIdentifier,
        string $imageUrl,
        ?string $managedUploadPath = null
    ): void {
        $normalizedImageUrl = trim($imageUrl);
        $normalizedManagedUploadPath = $this->normalizeUserBackgroundUploadPath($managedUploadPath);

        $imageUrlKey = $this->userServerBackgroundImageUrlKey($userId, $serverIdentifier);
        $opacityKey = $this->userServerBackgroundOpacityKey($userId, $serverIdentifier);
        $uploadPathKey = $this->userServerBackgroundUploadPathKey($userId, $serverIdentifier);

        $currentImageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, $imageUrlKey, '');
        $currentManagedUploadPath = $this->normalizeUserBackgroundUploadPath(
            $this->blueprint->dbGet(self::NAMESPACE, $uploadPathKey, '')
        );
        $serverIndex = $this->getUserServerIndex($userId);

        if ($normalizedImageUrl === '') {
            if (!is_null($currentManagedUploadPath)) {
                $this->deleteManagedUserBackgroundUpload($currentManagedUploadPath);
            }

            $this->blueprint->dbSet(self::NAMESPACE, $imageUrlKey, '');
            $this->blueprint->dbSet(self::NAMESPACE, $opacityKey, '');
            $this->blueprint->dbSet(self::NAMESPACE, $uploadPathKey, '');

            $serverIndex = array_values(array_filter($serverIndex, fn (string $identifier) => $identifier !== $serverIdentifier));
            $this->setUserServerIndex($userId, $serverIndex);

            return;
        }

        if (!is_null($currentManagedUploadPath)) {
            $currentManagedUrl = '/storage/' . ltrim($currentManagedUploadPath, '/');
            $isSameManagedUpload = !is_null($normalizedManagedUploadPath) && $normalizedManagedUploadPath === $currentManagedUploadPath;
            $isSameManagedUrl = $normalizedImageUrl === $currentManagedUrl || $normalizedImageUrl === $currentImageUrl;

            if (!$isSameManagedUpload && !$isSameManagedUrl) {
                $this->deleteManagedUserBackgroundUpload($currentManagedUploadPath);
            }
        }

        $this->blueprint->dbSet(self::NAMESPACE, $imageUrlKey, $normalizedImageUrl);
        $this->blueprint->dbSet(self::NAMESPACE, $opacityKey, '1');
        $this->blueprint->dbSet(self::NAMESPACE, $uploadPathKey, $normalizedManagedUploadPath ?? '');

        if (!in_array($serverIdentifier, $serverIndex, true)) {
            $serverIndex[] = $serverIdentifier;
        }

        $this->setUserServerIndex($userId, $serverIndex);
    }

    private function storeUserBackgroundUpload(UploadedFile $file, int $userId): string
    {
        return $file->store($this->userBackgroundUploadDirectory($userId), 'public');
    }

    private function deleteManagedUserBackgroundUpload(?string $storedPath): void
    {
        $normalizedPath = $this->normalizeUserBackgroundUploadPath($storedPath);
        if (is_null($normalizedPath)) {
            return;
        }

        Storage::disk('public')->delete($normalizedPath);
    }

    private function normalizeUserBackgroundUploadPath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $path = ltrim(trim($value), '/');
        if ($path === '') {
            return null;
        }

        $expectedPrefix = self::USER_BACKGROUND_UPLOAD_DIRECTORY . '/';
        if (!Str::startsWith($path, $expectedPrefix)) {
            return null;
        }

        return $path;
    }

    private function normalizeServerIdentifier(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $identifier = trim($value);

        return $identifier === '' ? null : $identifier;
    }

    private function isExternalIdentifier(string $identifier): bool
    {
        return preg_match('/^external:\d+:.+$/', $identifier) === 1;
    }

    /**
     * @return array{connection_id: int, server_identifier: string}|null
     */
    private function parseExternalIdentifier(string $identifier): ?array
    {
        if (preg_match('/^external:(\d+):(.+)$/', $identifier, $matches) !== 1) {
            return null;
        }

        return [
            'connection_id' => (int) $matches[1],
            'server_identifier' => $matches[2],
        ];
    }

    private function userServerIndexKey(int $userId): string
    {
        return self::KEY_USER_SERVER_INDEX_PREFIX . $userId;
    }

    private function userServerBackgroundImageUrlKey(int $userId, string $serverIdentifier): string
    {
        return sprintf('user_server_background_%d_%s_image_url', $userId, sha1($serverIdentifier));
    }

    private function userServerBackgroundOpacityKey(int $userId, string $serverIdentifier): string
    {
        return sprintf('user_server_background_%d_%s_opacity', $userId, sha1($serverIdentifier));
    }

    private function userServerBackgroundUploadPathKey(int $userId, string $serverIdentifier): string
    {
        return sprintf('user_server_background_%d_%s_upload_path', $userId, sha1($serverIdentifier));
    }

    private function userBackgroundUploadDirectory(int $userId): string
    {
        return sprintf('%s/%d', self::USER_BACKGROUND_UPLOAD_DIRECTORY, $userId);
    }

    private function userServerBackgroundAttributes(int $userId, string $serverIdentifier): array
    {
        $imageUrl = (string) $this->blueprint->dbGet(
            self::NAMESPACE,
            $this->userServerBackgroundImageUrlKey($userId, $serverIdentifier),
            ''
        );
        $opacity = $this->blueprint->dbGet(
            self::NAMESPACE,
            $this->userServerBackgroundOpacityKey($userId, $serverIdentifier),
            1
        );
        $uploadPath = $this->normalizeUserBackgroundUploadPath(
            $this->blueprint->dbGet(self::NAMESPACE, $this->userServerBackgroundUploadPathKey($userId, $serverIdentifier), '')
        );

        return [
            'server_id' => $serverIdentifier,
            'image_url' => $imageUrl,
            'opacity' => $opacity,
            'is_custom' => $imageUrl !== '',
            'is_uploaded' => !is_null($uploadPath),
        ];
    }
}
