<?php

namespace Pterodactyl\Services\External;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Cache\Repository as CacheRepository;
use Pterodactyl\Models\ExternalServerCache;
use Pterodactyl\Models\ExternalPanelConnection;

class CapabilityDetector
{
    public const CACHE_TTL_SECONDS = 600;
    public const PROBE_TIMEOUT_SECONDS = 4;
    public const PROBE_CONNECT_TIMEOUT_SECONDS = 2;

    public const KEYS = [
        'resources',
        'websocket',
        'power',
        'command',
        'files',
        'backups',
        'startup',
        'network',
        'settings.rename',
        'settings.reinstall',
        'settings.docker-image',
        'activity',
        'databases',
        'schedules',
        'users',
    ];

    public function __construct(
        private CacheRepository $cache,
        private ExternalPanelClient $client
    ) {
    }

    protected bool $cacheWarningLogged = false;

    public function getCapabilities(
        ExternalPanelConnection $connection,
        string $serverIdentifier,
        ?array $serverPayload = null
    ): array {
        $key = $this->cacheKey($connection->id, $serverIdentifier);

        return $this->remember($key, $this->cacheTtlSeconds(), function () use (
            $connection,
            $serverIdentifier,
            $serverPayload
        ) {
            $persisted = $this->loadPersistedCapabilities($connection, $serverIdentifier);
            if (!is_null($persisted)) {
                return $persisted;
            }

            $capabilities = $this->permissionsToCapabilities($serverPayload);
            if (!$this->shouldProbeCapabilities($serverPayload)) {
                $this->persistCapabilities($connection, $serverIdentifier, $capabilities);

                return $capabilities;
            }

            $probeMap = [
                'resources' => ['GET', "servers/$serverIdentifier/resources", []],
                'websocket' => ['GET', "servers/$serverIdentifier/websocket", []],
                'files' => ['GET', "servers/$serverIdentifier/files/list", ['query' => ['directory' => '/']]],
                'backups' => ['GET', "servers/$serverIdentifier/backups", []],
                'startup' => ['GET', "servers/$serverIdentifier/startup", []],
                'network' => ['GET', "servers/$serverIdentifier/network/allocations", []],
                'activity' => ['GET', "servers/$serverIdentifier/activity", []],
                'databases' => ['GET', "servers/$serverIdentifier/databases", []],
                'schedules' => ['GET', "servers/$serverIdentifier/schedules", []],
                'users' => ['GET', "servers/$serverIdentifier/users", []],
            ];

            foreach ($probeMap as $feature => [$method, $endpoint, $options]) {
                if (!$capabilities[$feature]) {
                    continue;
                }

                $probeOptions = array_merge($options, [
                    '_timeout' => $this->probeTimeoutSeconds(),
                    '_connect_timeout' => $this->probeConnectTimeoutSeconds(),
                    '_retry_delays' => [],
                ]);

                try {
                    $response = $this->client->request($connection, $method, $endpoint, $probeOptions);
                } catch (\Throwable $exception) {
                    // If probing times out or fails, treat that capability as unavailable for now.
                    $capabilities[$feature] = false;

                    continue;
                }

                if ($this->isUnsupportedStatus($response->status())) {
                    $capabilities[$feature] = false;
                }
            }

            $this->persistCapabilities($connection, $serverIdentifier, $capabilities);

            return $capabilities;
        });
    }

    public function markUnsupported(ExternalPanelConnection $connection, string $serverIdentifier, string $feature): void
    {
        if (!in_array($feature, self::KEYS, true)) {
            return;
        }

        $key = $this->cacheKey($connection->id, $serverIdentifier);
        $capabilities = $this->get($key, $this->defaultCapabilities());
        $capabilities[$feature] = false;

        $this->put($key, $capabilities, $this->cacheTtlSeconds());
        $this->persistCapabilities($connection, $serverIdentifier, $capabilities);
    }

    public function permissionsFromCapabilities(array $capabilities): array
    {
        $permissions = Collection::make();

        $permissionsMap = [
            'websocket' => ['websocket.connect'],
            'power' => ['control.start', 'control.stop', 'control.restart'],
            'command' => ['control.console'],
            'files' => [
                'file.create',
                'file.read',
                'file.read-content',
                'file.update',
                'file.delete',
                'file.archive',
                'file.sftp',
            ],
            'backups' => ['backup.read', 'backup.create', 'backup.delete', 'backup.download', 'backup.restore'],
            'startup' => ['startup.read', 'startup.update'],
            'settings.docker-image' => ['startup.docker-image'],
            'network' => ['allocation.read', 'allocation.create', 'allocation.update', 'allocation.delete'],
            'settings.rename' => ['settings.rename'],
            'settings.reinstall' => ['settings.reinstall'],
            'activity' => ['activity.read'],
            'databases' => ['database.read', 'database.create', 'database.update', 'database.delete', 'database.view_password'],
            'schedules' => ['schedule.read', 'schedule.create', 'schedule.update', 'schedule.delete'],
            'users' => ['user.read', 'user.create', 'user.update', 'user.delete'],
        ];

        foreach ($permissionsMap as $feature => $featurePermissions) {
            if (Arr::get($capabilities, $feature, false)) {
                $permissions = $permissions->merge($featurePermissions);
            }
        }

        return $permissions->unique()->values()->all();
    }

    protected function permissionsToCapabilities(?array $serverPayload): array
    {
        $capabilities = $this->defaultCapabilities();
        if (is_null($serverPayload)) {
            return $capabilities;
        }

        $isServerOwner = (bool) Arr::get($serverPayload, 'meta.is_server_owner', false);
        $permissionList = Arr::get($serverPayload, 'meta.user_permissions', []);
        if ($isServerOwner) {
            return array_fill_keys(self::KEYS, true);
        }

        // External panels often omit permission metadata on server detail responses.
        // In that case keep optimistic defaults and let endpoint probes disable features.
        if (!Arr::has($serverPayload, 'meta.user_permissions')) {
            return $capabilities;
        }

        if (!is_array($permissionList)) {
            return $capabilities;
        }

        $hasPermission = function (string|array $permissions) use ($permissionList): bool {
            if (in_array('*', $permissionList, true)) {
                return true;
            }

            $permissions = is_array($permissions) ? $permissions : [$permissions];
            foreach ($permissions as $permission) {
                if (in_array($permission, $permissionList, true)) {
                    return true;
                }
            }

            return false;
        };

        $capabilities['websocket'] = $hasPermission('websocket.connect');
        $capabilities['power'] = $hasPermission(['control.start', 'control.stop', 'control.restart']);
        $capabilities['command'] = $hasPermission('control.console');
        $capabilities['files'] = $hasPermission('file.read');
        $capabilities['backups'] = $hasPermission('backup.read');
        $capabilities['startup'] = $hasPermission('startup.read');
        $capabilities['network'] = $hasPermission('allocation.read');
        $capabilities['settings.rename'] = $hasPermission('settings.rename');
        $capabilities['settings.reinstall'] = $hasPermission('settings.reinstall');
        $capabilities['settings.docker-image'] = $hasPermission('startup.docker-image');
        $capabilities['activity'] = $hasPermission('activity.read');
        $capabilities['databases'] = $hasPermission('database.read');
        $capabilities['schedules'] = $hasPermission('schedule.read');
        $capabilities['users'] = $hasPermission('user.read');
        $capabilities['resources'] = true;

        return $capabilities;
    }

    protected function persistCapabilities(
        ExternalPanelConnection $connection,
        string $serverIdentifier,
        array $capabilities
    ): void {
        $cache = ExternalServerCache::query()->firstOrNew([
            'external_panel_connection_id' => $connection->id,
            'external_server_identifier' => $serverIdentifier,
        ]);

        $meta = is_array($cache->meta_json) ? $cache->meta_json : [];
        $meta['capabilities'] = $capabilities;

        $cache->fill([
            'name' => $cache->name ?: $serverIdentifier,
            'node' => $cache->node,
            'meta_json' => $meta,
            'last_synced_at' => CarbonImmutable::now(),
        ])->save();
    }

    protected function loadPersistedCapabilities(
        ExternalPanelConnection $connection,
        string $serverIdentifier
    ): ?array {
        $cache = ExternalServerCache::query()
            ->where('external_panel_connection_id', $connection->id)
            ->where('external_server_identifier', $serverIdentifier)
            ->first();

        if (is_null($cache) || !is_array($cache->meta_json)) {
            return null;
        }

        $capabilities = Arr::get($cache->meta_json, 'capabilities');
        if (!is_array($capabilities)) {
            return null;
        }

        // Force a fresh probe when persisted capabilities predate newly-added keys.
        if (count(array_intersect(self::KEYS, array_keys($capabilities))) !== count(self::KEYS)) {
            return null;
        }

        return array_replace($this->defaultCapabilities(), $capabilities);
    }

    protected function defaultCapabilities(): array
    {
        return array_fill_keys(self::KEYS, true);
    }

    protected function cacheKey(int $connectionId, string $serverIdentifier): string
    {
        return sprintf('external:capabilities:%d:%s', $connectionId, $serverIdentifier);
    }

    protected function isUnsupportedStatus(int $status): bool
    {
        return in_array($status, [403, 404, 405, 501], true);
    }

    protected function shouldProbeCapabilities(?array $serverPayload): bool
    {
        if (!$this->capabilityProbeEnabled()) {
            return false;
        }

        if (is_null($serverPayload)) {
            return true;
        }

        $isOwner = (bool) Arr::get($serverPayload, 'meta.is_server_owner', false);
        if ($isOwner) {
            return false;
        }

        return Arr::has($serverPayload, 'meta.user_permissions');
    }

    protected function cacheTtlSeconds(): int
    {
        return max(0, (int) config('pterodactyl.external_panel.cache.capabilities_ttl', self::CACHE_TTL_SECONDS));
    }

    protected function capabilityProbeEnabled(): bool
    {
        return (bool) config('pterodactyl.external_panel.capability_probe.enabled', false);
    }

    protected function probeTimeoutSeconds(): int
    {
        return max(1, (int) config('pterodactyl.external_panel.capability_probe.timeout', self::PROBE_TIMEOUT_SECONDS));
    }

    protected function probeConnectTimeoutSeconds(): int
    {
        return max(1, (int) config('pterodactyl.external_panel.capability_probe.connect_timeout', self::PROBE_CONNECT_TIMEOUT_SECONDS));
    }

    protected function remember(string $key, int $ttlSeconds, \Closure $callback): mixed
    {
        if ($ttlSeconds <= 0) {
            return $callback();
        }

        try {
            return $this->cache->remember($key, CarbonImmutable::now()->addSeconds($ttlSeconds), $callback);
        } catch (\Throwable $exception) {
            $this->logCacheWarning($exception, $key);

            return $callback();
        }
    }

    protected function get(string $key, mixed $default = null): mixed
    {
        try {
            return $this->cache->get($key, $default);
        } catch (\Throwable $exception) {
            $this->logCacheWarning($exception, $key);

            return $default;
        }
    }

    protected function put(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            if ($ttlSeconds <= 0) {
                $this->cache->forever($key, $value);

                return;
            }

            $this->cache->put($key, $value, CarbonImmutable::now()->addSeconds($ttlSeconds));
        } catch (\Throwable $exception) {
            $this->logCacheWarning($exception, $key);
        }
    }

    protected function logCacheWarning(\Throwable $exception, string $key): void
    {
        if ($this->cacheWarningLogged) {
            return;
        }

        $this->cacheWarningLogged = true;
        logger()->warning('External capability cache access failed, bypassing cache for this request.', [
            'cache_key' => $key,
            'error' => $exception->getMessage(),
        ]);
    }
}
