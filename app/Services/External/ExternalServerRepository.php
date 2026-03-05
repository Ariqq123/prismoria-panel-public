<?php

namespace Pterodactyl\Services\External;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Http\Client\Response;
use Illuminate\Cache\Repository as CacheRepository;
use Pterodactyl\Models\User;
use Pterodactyl\Models\ExternalPanelConnection;
use Pterodactyl\Contracts\Servers\ServerDataProvider;
use Pterodactyl\Models\ExternalServerCache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExternalServerRepository implements ServerDataProvider
{
    public const SERVER_LIST_TTL_SECONDS = 45;
    public const SERVER_DETAIL_TTL_SECONDS = 20;
    public const SERVER_RESOURCES_TTL_SECONDS = 12;
    public const SERVER_LIST_REQUEST_TIMEOUT_SECONDS = 12;
    public const SERVER_LIST_CONNECT_TIMEOUT_SECONDS = 4;
    public const SERVER_DETAIL_REQUEST_TIMEOUT_SECONDS = 14;
    public const SERVER_DETAIL_CONNECT_TIMEOUT_SECONDS = 4;
    public const SERVER_RESOURCES_REQUEST_TIMEOUT_SECONDS = 10;
    public const SERVER_RESOURCES_CONNECT_TIMEOUT_SECONDS = 4;

    protected bool $cacheWarningLogged = false;

    public function __construct(
        private CacheRepository $cache,
        private ExternalPanelClient $client,
        private CapabilityDetector $capabilityDetector
    ) {
    }

    public function listServersForUser(User $user, array $context = []): array
    {
        $query = is_string($context['query'] ?? null) ? trim((string) $context['query']) : null;
        $connectionId = isset($context['connection_id']) ? (int) $context['connection_id'] : null;
        $preferCached = (bool) ($context['prefer_cached'] ?? false);
        $cachedOnly = (bool) ($context['cached_only'] ?? false);

        $connections = $user->externalPanelConnections()
            ->when(!is_null($connectionId), fn ($builder) => $builder->where('id', $connectionId))
            ->get();

        $servers = [];
        foreach ($connections as $connection) {
            $cachedServers = [];
            if ($preferCached || $cachedOnly) {
                $cachedServers = $this->listCachedServersForConnection($connection, $query);
                if ($cachedServers !== []) {
                    $servers = array_merge($servers, $cachedServers);
                    if ($preferCached || $cachedOnly) {
                        continue;
                    }
                } elseif ($cachedOnly) {
                    continue;
                }
            }

            try {
                $servers = array_merge($servers, $this->listServersForConnection($connection, $query));
            } catch (\Throwable $exception) {
                Log::warning($exception, [
                    'connection_id' => $connection->id,
                    'user_id' => $user->id,
                ]);

                if ($cachedServers === []) {
                    $servers = array_merge($servers, $this->listCachedServersForConnection($connection, $query));
                }
            }
        }

        return $servers;
    }

    public function getServer(string $identifier, User $user): array
    {
        $reference = $this->resolveReference($identifier, $user);

        return $this->remember(
            $this->serverDetailCacheKey($reference),
            $this->serverDetailTtlSeconds(),
            function () use ($reference) {
                try {
                    $detailRequestOptions = [
                        'query' => [
                            'include' => 'allocations,variables,egg',
                        ],
                        '_timeout' => $this->serverDetailRequestTimeoutSeconds(),
                        '_connect_timeout' => $this->serverDetailConnectTimeoutSeconds(),
                        '_retry_delays' => [150],
                    ];

                    try {
                        $response = $this->request(
                            $reference,
                            'GET',
                            "servers/{$reference->serverIdentifier}",
                            $detailRequestOptions
                        );
                    } catch (HttpException $exception) {
                        // Some external panels reject include=egg on this endpoint.
                        if (!in_array($exception->getStatusCode(), [400, 422], true)) {
                            throw $exception;
                        }

                        $detailRequestOptions['query']['include'] = 'allocations,variables';
                        $response = $this->request(
                            $reference,
                            'GET',
                            "servers/{$reference->serverIdentifier}",
                            $detailRequestOptions
                        );
                    }

                    $payload = $response->json() ?? [];
                } catch (\Throwable $exception) {
                    Log::warning('External server detail request failed; using cached snapshot fallback when available.', [
                        'connection_id' => $reference->connection->id,
                        'server_identifier' => $reference->serverIdentifier,
                        'error' => $exception->getMessage(),
                    ]);

                    $fallback = $this->serverDetailFallbackPayload($reference);
                    if (!is_null($fallback)) {
                        return $fallback;
                    }

                    throw $exception;
                }

                $resource = $this->extractServerResource($payload);
                $normalized = $this->normalizeServerResource($resource, $reference);
                $normalized['attributes'] = $this->withExternalSftpUsername($normalized['attributes'], $reference);
                $payload = $this->normalizeServerPayloadMeta($payload, $normalized['attributes']);

                $capabilities = $this->capabilityDetector->getCapabilities(
                    $reference->connection,
                    $reference->serverIdentifier,
                    $payload
                );
                $normalized['attributes']['external_capabilities'] = $capabilities;

                $permissions = $this->capabilityDetector->permissionsFromCapabilities($capabilities);
                $isOwner = (bool) Arr::get($payload, 'meta.is_server_owner', false);

                $this->updateServerCacheFromResource($reference, $normalized['attributes'], $capabilities);

                return [
                    'object' => $normalized['object'] ?? 'server',
                    'attributes' => $normalized['attributes'],
                    'meta' => [
                        'is_server_owner' => $isOwner,
                        'user_permissions' => $permissions,
                    ],
                ];
            }
        );
    }

    public function getResources(string $identifier, User $user): array
    {
        $reference = $this->resolveReference($identifier, $user);

        return $this->remember(
            $this->serverResourcesCacheKey($reference),
            $this->serverResourcesTtlSeconds(),
            function () use ($reference) {
                $endpoint = "servers/{$reference->serverIdentifier}/resources";
                try {
                    $response = $this->client->request($reference->connection, 'GET', $endpoint, [
                        '_timeout' => $this->serverResourcesRequestTimeoutSeconds(),
                        '_connect_timeout' => $this->serverResourcesConnectTimeoutSeconds(),
                        '_retry_delays' => [],
                    ]);
                } catch (\Throwable $exception) {
                    Log::warning('External server resources request failed; using fallback payload.', [
                        'connection_id' => $reference->connection->id,
                        'server_identifier' => $reference->serverIdentifier,
                        'error' => $exception->getMessage(),
                    ]);

                    return $this->resourcesFallbackPayload($reference);
                }

                if ($response->successful()) {
                    $normalized = $this->normalizeResourcesPayload($response->json() ?? []);
                    $this->persistResourcesSnapshot($reference, $normalized);

                    return $normalized;
                }

                if (in_array($response->status(), [403, 404, 405, 501], true)) {
                    $this->capabilityDetector->markUnsupported($reference->connection, $reference->serverIdentifier, 'resources');

                    return $this->resourcesFallbackPayload($reference);
                }

                // Some panels return 409/422/423/429 for transient server states
                // (maintenance, not fully available, or throttled). Do not break
                // client polling for these states, return fallback stats instead.
                if (in_array($response->status(), [409, 422, 423, 429], true)) {
                    Log::warning('External server resources endpoint returned transient conflict; using fallback payload.', [
                        'connection_id' => $reference->connection->id,
                        'server_identifier' => $reference->serverIdentifier,
                        'status' => $response->status(),
                        'body' => mb_substr($response->body(), 0, 500),
                    ]);

                    return $this->resourcesFallbackPayload($reference);
                }

                if ($response->serverError()) {
                    Log::warning('External server resources endpoint returned server error; using fallback payload.', [
                        'connection_id' => $reference->connection->id,
                        'server_identifier' => $reference->serverIdentifier,
                        'status' => $response->status(),
                        'body' => mb_substr($response->body(), 0, 500),
                    ]);

                    return $this->resourcesFallbackPayload($reference);
                }

                throw new HttpException($response->status(), $this->client->extractErrorMessage($response));
            }
        );
    }

    public function getWebsocket(string $identifier, User $user): array
    {
        $reference = $this->resolveReference($identifier, $user);

        return $this->request(
            $reference,
            'GET',
            "servers/{$reference->serverIdentifier}/websocket",
            [
                '_timeout' => 6,
                '_connect_timeout' => 4,
                '_retry_delays' => [],
            ]
        )->json() ?? [];
    }

    public function sendPowerAction(string $identifier, User $user, array $payload): void
    {
        $reference = $this->resolveReference($identifier, $user);

        $this->request(
            $reference,
            'POST',
            "servers/{$reference->serverIdentifier}/power",
            ['json' => $payload]
        );

        $this->forgetServerCaches($reference);
    }

    public function sendCommand(string $identifier, User $user, array $payload): void
    {
        $reference = $this->resolveReference($identifier, $user);

        $this->request(
            $reference,
            'POST',
            "servers/{$reference->serverIdentifier}/command",
            ['json' => $payload]
        );

        $this->forget($this->serverResourcesCacheKey($reference));
    }

    public function proxyJson(string $identifier, User $user, string $method, string|array $endpoint, array $options = []): array
    {
        $reference = $this->resolveReference($identifier, $user);
        $response = $this->request($reference, $method, $endpoint, $options);
        $this->invalidateServerCachesForMutation($reference, $method);

        return $response->json() ?? [];
    }

    public function proxyNoContent(string $identifier, User $user, string $method, string|array $endpoint, array $options = []): void
    {
        $reference = $this->resolveReference($identifier, $user);
        $this->request($reference, $method, $endpoint, $options);
        $this->invalidateServerCachesForMutation($reference, $method);
    }

    public function proxyText(
        string $identifier,
        User $user,
        string $method,
        string|array $endpoint,
        Request $request,
        array $options = []
    ): string {
        $reference = $this->resolveReference($identifier, $user);
        $response = $this->request($reference, $method, $endpoint, $options);

        return $response->body();
    }

    public function getPermissions(string $identifier, User $user): array
    {
        return Arr::get($this->getServer($identifier, $user), 'meta.user_permissions', []);
    }

    public function listServersForConnection(
        \Pterodactyl\Models\ExternalPanelConnection $connection,
        ?string $query = null
    ): array {
        $cacheKey = sprintf('external:servers:%d:%s', $connection->id, sha1((string) $query));

        return $this->remember(
            $cacheKey,
            $this->serverListTtlSeconds(),
            function () use ($connection, $query) {
                $items = [];
                $page = 1;
                $totalPages = 1;

                do {
                    $queryParams = array_filter([
                        'page' => $page,
                        'per_page' => 100,
                        'filter[*]' => $query ?: null,
                    ]);

                    $response = $this->client->requestWithFallback($connection, 'GET', ['', 'servers'], [
                        'query' => array_merge($queryParams, ['include' => 'egg']),
                        '_timeout' => $this->serverListRequestTimeoutSeconds(),
                        '_connect_timeout' => $this->serverListConnectTimeoutSeconds(),
                        '_retry_delays' => [],
                    ]);

                    if (!$response->successful() && in_array($response->status(), [400, 422], true)) {
                        $response = $this->client->requestWithFallback($connection, 'GET', ['', 'servers'], [
                            'query' => $queryParams,
                            '_timeout' => $this->serverListRequestTimeoutSeconds(),
                            '_connect_timeout' => $this->serverListConnectTimeoutSeconds(),
                            '_retry_delays' => [],
                        ]);
                    }

                    $this->assertSuccessful($response, new ExternalServerReference($connection, 'servers'), '');
                    $payload = $response->json();
                    $totalPages = (int) Arr::get($payload, 'meta.pagination.total_pages', 1);

                    foreach (Arr::get($payload, 'data', []) as $resource) {
                        $attributes = Arr::get($resource, 'attributes', []);
                        $serverIdentifier = (string) Arr::get($attributes, 'identifier', '');
                        if ($serverIdentifier === '') {
                            continue;
                        }

                        $reference = new ExternalServerReference($connection, $serverIdentifier);
                        $normalized = $this->normalizeServerResource($resource, $reference);
                        $items[] = $normalized;
                        $this->updateServerCacheFromResource($reference, $normalized['attributes']);
                    }

                    $page++;
                } while ($page <= $totalPages);

                return $items;
            }
        );
    }

    protected function listCachedServersForConnection(
        \Pterodactyl\Models\ExternalPanelConnection $connection,
        ?string $query = null
    ): array {
        $rows = ExternalServerCache::query()
            ->where('external_panel_connection_id', $connection->id)
            ->orderBy('name')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $reference = new ExternalServerReference($connection, $row->external_server_identifier);
            $resource = [
                'object' => 'server',
                'attributes' => $this->cachedServerAttributes($row),
            ];
            $normalized = $this->normalizeServerResource($resource, $reference);
            if ($query && !$this->externalServerMatchesQuery($normalized, $query)) {
                continue;
            }

            $items[] = $normalized;
        }

        return $items;
    }

    protected function cachedServerAttributes(ExternalServerCache $cache): array
    {
        $meta = is_array($cache->meta_json) ? $cache->meta_json : [];
        $snapshot = Arr::get($meta, 'server_snapshot', []);
        $attributes = is_array($snapshot) ? $snapshot : [];
        $resourcesSnapshot = Arr::get($meta, 'resources_snapshot', []);
        $resourcesAttributes = is_array(Arr::get($resourcesSnapshot, 'attributes')) ? Arr::get($resourcesSnapshot, 'attributes') : [];

        $attributes['identifier'] = (string) $cache->external_server_identifier;
        $attributes['name'] = (string) Arr::get($attributes, 'name', $cache->name);
        $attributes['node'] = Arr::get($attributes, 'node', $cache->node);
        $isSuspended = $this->booleanValue(
            Arr::get($attributes, 'is_suspended', Arr::get($resourcesAttributes, 'is_suspended', false))
        );
        $currentState = Arr::get($resourcesAttributes, 'current_state');
        $attributes['current_state'] = is_string($currentState) ? $currentState : null;
        $attributes['status'] = $this->normalizeServerStatus(
            Arr::get($attributes, 'status'),
            $isSuspended,
            $currentState
        );
        $attributes['is_suspended'] = $isSuspended;
        $attributes['limits'] = is_array(Arr::get($attributes, 'limits')) ? Arr::get($attributes, 'limits') : [];
        $attributes['feature_limits'] = is_array(Arr::get($attributes, 'feature_limits'))
            ? Arr::get($attributes, 'feature_limits')
            : [];
        $attributes['relationships'] = is_array(Arr::get($attributes, 'relationships')) ? Arr::get($attributes, 'relationships') : [];

        $capabilities = Arr::get($meta, 'capabilities');
        if (is_array($capabilities)) {
            $attributes['external_capabilities'] = $capabilities;
        }

        return $attributes;
    }

    protected function externalServerMatchesQuery(array $resource, string $query): bool
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return true;
        }

        $attributes = Arr::get($resource, 'attributes', []);
        if (!is_array($attributes)) {
            return false;
        }

        $defaultAllocation = $this->defaultAllocationFromRelationships((array) Arr::get($attributes, 'relationships', []));
        $allocationText = '';
        if (is_array($defaultAllocation)) {
            $allocationIp = (string) Arr::get($defaultAllocation, 'ip_alias', Arr::get($defaultAllocation, 'ip', ''));
            $allocationPort = (string) Arr::get($defaultAllocation, 'port', '');
            $allocationText = trim($allocationIp . ($allocationPort !== '' ? ':' . $allocationPort : ''));
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) Arr::get($attributes, 'name', ''),
            (string) Arr::get($attributes, 'identifier', ''),
            (string) Arr::get($attributes, 'uuid', ''),
            (string) Arr::get($attributes, 'node', ''),
            (string) Arr::get($attributes, 'external_server_identifier', ''),
            (string) Arr::get($attributes, 'external_panel_name', ''),
            (string) Arr::get($attributes, 'external_panel_url', ''),
            $allocationText,
        ])));
        $normalizedQuery = preg_replace('/^https?:\/\//i', '', $query) ?? $query;
        $normalizedQuery = rtrim($normalizedQuery, '/');

        return str_contains($haystack, $query) || ($normalizedQuery !== '' && str_contains($haystack, $normalizedQuery));
    }

    protected function resolveReference(string $identifier, User $user): ExternalServerReference
    {
        if (Str::startsWith($identifier, 'external:')) {
            $parts = ExternalServerReference::parseCompositeIdentifier($identifier);
        } else {
            $parts = ExternalServerReference::parseRouteParameter($identifier);
        }

        $connection = $user->externalPanelConnections()
            ->where('id', $parts['connection_id'])
            ->first();

        if (is_null($connection)) {
            throw new NotFoundHttpException('The requested external server was not found.');
        }

        return new ExternalServerReference($connection, $parts['server_identifier']);
    }

    protected function request(
        ExternalServerReference $reference,
        string $method,
        string|array $endpoint,
        array $options = []
    ): Response {
        $response = is_array($endpoint)
            ? $this->client->requestWithFallback($reference->connection, $method, $endpoint, $options)
            : $this->client->request($reference->connection, $method, $endpoint, $options);

        $this->assertSuccessful($response, $reference, is_array($endpoint) ? $endpoint[0] : $endpoint);

        return $response;
    }

    protected function assertSuccessful(Response $response, ExternalServerReference $reference, string $endpoint): void
    {
        if ($response->successful()) {
            return;
        }

        $feature = $this->featureForEndpoint($endpoint);
        if (in_array($response->status(), [403, 404, 405, 501], true)) {
            if (!is_null($feature)) {
                $this->capabilityDetector->markUnsupported($reference->connection, $reference->serverIdentifier, $feature);
            }

            throw new HttpException(501, 'This feature is not supported by the connected external panel.');
        }

        if ($response->serverError()) {
            $message = $this->client->extractErrorMessage($response);
            $generic = sprintf('External panel request failed with status code %d.', $response->status());
            if ($message === $generic) {
                $message = 'The external panel is currently unreachable.';
            }

            throw new HttpException(502, $message);
        }

        throw new HttpException($response->status(), $this->client->extractErrorMessage($response));
    }

    protected function normalizeServerResource(array $resource, ExternalServerReference $reference): array
    {
        $attributes = Arr::get($resource, 'attributes', []);
        if (!is_array($attributes)) {
            $attributes = [];
        }

        // Some panel versions return a single server object without nesting values under "attributes".
        if ($attributes === []) {
            $attributes = collect($resource)
                ->except(['object', 'meta', 'relationships'])
                ->all();
        }

        $attributes = $this->mergeServerSnapshotAttributes($reference, $attributes);

        $resourceRelationships = Arr::get($resource, 'relationships', []);
        $attributeRelationships = Arr::get($attributes, 'relationships', []);
        $relationships = is_array($resourceRelationships) ? $resourceRelationships : [];
        if (is_array($attributeRelationships)) {
            $relationships = array_replace_recursive($relationships, $attributeRelationships);
        }

        if (!empty($relationships)) {
            $attributes['relationships'] = $relationships;
        }

        $eggId = $this->extractEggId($attributes);
        if (!is_null($eggId)) {
            $attributes['egg_id'] = $eggId;

            $blueprintFramework = Arr::get($attributes, 'BlueprintFramework');
            if (!is_array($blueprintFramework)) {
                $blueprintFramework = [];
            }

            $blueprintFramework['egg_id'] = $eggId;
            $attributes['BlueprintFramework'] = $blueprintFramework;
        }

        if (
            !is_array(Arr::get($attributes, 'limits')) &&
            is_array(Arr::get($attributes, 'resources')) &&
            $this->isLimitPayload(Arr::get($attributes, 'resources', []))
        ) {
            $attributes['limits'] = Arr::get($attributes, 'resources');
        }

        $sftpDetails = Arr::get($attributes, 'sftp_details');
        if (!is_array($sftpDetails)) {
            $sftpDetails = [];
        }

        $defaultAllocation = $this->defaultAllocationFromRelationships($relationships);
        if (($sftpDetails['ip'] ?? null) === null && !is_null($defaultAllocation)) {
            $sftpDetails['ip'] = Arr::get($defaultAllocation, 'ip_alias', Arr::get($defaultAllocation, 'ip', ''));
        }
        if (($sftpDetails['port'] ?? null) === null && !is_null($defaultAllocation)) {
            $sftpDetails['port'] = Arr::get($defaultAllocation, 'port', 0);
        }

        $attributes['sftp_details'] = $sftpDetails;
        $isSuspended = $this->booleanValue(Arr::get($attributes, 'is_suspended', false));
        $attributes['status'] = $this->normalizeServerStatus(
            Arr::get($attributes, 'status'),
            $isSuspended,
            Arr::get($attributes, 'current_state', Arr::get($attributes, 'state'))
        );
        $attributes['is_node_under_maintenance'] = $this->booleanValue(
            Arr::get($attributes, 'is_node_under_maintenance', false)
        );
        $attributes['is_transferring'] = $this->booleanValue(Arr::get($attributes, 'is_transferring', false));
        $attributes['is_installing'] = $this->booleanValue(Arr::get($attributes, 'is_installing', false));
        $attributes['is_suspended'] = $isSuspended;

        $sourceIdentifier = (string) Arr::get($attributes, 'identifier', $reference->serverIdentifier);
        $compositeIdentifier = sprintf('external:%d:%s', $reference->connection->id, $sourceIdentifier);

        $attributes['identifier'] = $compositeIdentifier;
        $attributes['internal_id'] = $compositeIdentifier;
        $attributes['uuid'] = $compositeIdentifier;
        $attributes['source'] = 'external';
        $attributes['external_server_identifier'] = $sourceIdentifier;
        $attributes['external_panel_connection_id'] = $reference->connection->id;
        $attributes['external_panel_name'] = $reference->connection->name;
        $attributes['external_panel_url'] = $reference->connection->panel_url;
        $attributes['external_capabilities'] = Arr::get($attributes, 'external_capabilities', []);

        return [
            'object' => Arr::get($resource, 'object', 'server'),
            'attributes' => $attributes,
        ];
    }

    protected function extractServerResource(array $payload): array
    {
        $data = Arr::get($payload, 'data');
        if (is_array($data) && Arr::isAssoc($data)) {
            return $data;
        }

        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        return $payload;
    }

    protected function normalizeServerPayloadMeta(array $payload, array $attributes): array
    {
        $meta = Arr::get($payload, 'meta', []);
        if (!is_array($meta)) {
            $meta = [];
        }

        if (!array_key_exists('is_server_owner', $meta)) {
            $meta['is_server_owner'] = $this->booleanValue(Arr::get($attributes, 'server_owner', false));
        }

        if (!array_key_exists('user_permissions', $meta)) {
            $permissions = Arr::get($attributes, 'user_permissions');
            if (is_array($permissions)) {
                $meta['user_permissions'] = $permissions;
            }
        }

        $payload['meta'] = $meta;

        return $payload;
    }

    protected function defaultAllocationFromRelationships(array $relationships): ?array
    {
        $allocations = Arr::get($relationships, 'allocations.data', []);
        if (!is_array($allocations) || empty($allocations)) {
            return null;
        }

        foreach ($allocations as $allocationResource) {
            $attributes = Arr::get($allocationResource, 'attributes', []);
            if (!is_array($attributes)) {
                continue;
            }

            if ($this->booleanValue(Arr::get($attributes, 'is_default', false))) {
                return $attributes;
            }
        }

        $first = Arr::get($allocations, '0.attributes');

        return is_array($first) ? $first : null;
    }

    protected function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', 'null'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    protected function updateServerCacheFromResource(
        ExternalServerReference $reference,
        array $attributes,
        ?array $capabilities = null
    ): void {
        $serverIdentifier = (string) Arr::get($attributes, 'external_server_identifier', $reference->serverIdentifier);
        $cache = ExternalServerCache::query()->firstOrNew([
            'external_panel_connection_id' => $reference->connection->id,
            'external_server_identifier' => $serverIdentifier,
        ]);

        $meta = is_array($cache->meta_json) ? $cache->meta_json : [];
        $incomingMeta = is_array(Arr::get($attributes, 'meta_json')) ? Arr::get($attributes, 'meta_json') : [];
        if ($incomingMeta !== []) {
            $meta = array_replace_recursive($meta, $incomingMeta);
        }

        $serverSnapshot = $this->serverSnapshotFromAttributes($attributes);
        if (!is_string(Arr::get($serverSnapshot, 'status')) || trim((string) Arr::get($serverSnapshot, 'status')) === '') {
            $serverSnapshot['status'] = $this->normalizeServerStatus(
                Arr::get($serverSnapshot, 'status'),
                $this->booleanValue(Arr::get($serverSnapshot, 'is_suspended', false)),
                Arr::get($meta, 'resources_snapshot.attributes.current_state')
            );
        }

        $meta['server_snapshot'] = $serverSnapshot;
        if (!is_null($capabilities)) {
            $meta['capabilities'] = $capabilities;
        }

        $cache->fill([
            'name' => (string) Arr::get($attributes, 'name', $serverIdentifier),
            'node' => Arr::get($attributes, 'node'),
            'meta_json' => $meta,
            'last_synced_at' => CarbonImmutable::now(),
        ])->save();
    }

    protected function mergeServerSnapshotAttributes(ExternalServerReference $reference, array $attributes): array
    {
        $snapshot = $this->loadServerSnapshot($reference);
        if (is_null($snapshot)) {
            return $attributes;
        }

        $currentLimits = Arr::get($attributes, 'limits');
        $snapshotLimits = Arr::get($snapshot, 'limits');
        if (
            (!is_array($currentLimits) || !$this->hasNonZeroLimits($currentLimits)) &&
            is_array($snapshotLimits) &&
            $this->hasNonZeroLimits($snapshotLimits)
        ) {
            $attributes['limits'] = $snapshotLimits;
        }

        $currentFeatureLimits = Arr::get($attributes, 'feature_limits');
        $snapshotFeatureLimits = Arr::get($snapshot, 'feature_limits');
        if ((!is_array($currentFeatureLimits) || $currentFeatureLimits === []) && is_array($snapshotFeatureLimits)) {
            $attributes['feature_limits'] = $snapshotFeatureLimits;
        }

        $currentSftp = Arr::get($attributes, 'sftp_details');
        if (!is_array($currentSftp)) {
            $currentSftp = [];
        }

        $snapshotSftp = Arr::get($snapshot, 'sftp_details');
        if (is_array($snapshotSftp)) {
            $currentIp = trim((string) Arr::get($currentSftp, 'ip', ''));
            $snapshotIp = trim((string) Arr::get($snapshotSftp, 'ip', ''));
            if (($currentIp === '' || $currentIp === '0.0.0.0') && $snapshotIp !== '') {
                $currentSftp['ip'] = $snapshotIp;
            }

            $currentPort = (int) Arr::get($currentSftp, 'port', 0);
            $snapshotPort = (int) Arr::get($snapshotSftp, 'port', 0);
            if ($currentPort <= 0 && $snapshotPort > 0) {
                $currentSftp['port'] = $snapshotPort;
            }
        }

        if ($currentSftp !== []) {
            $attributes['sftp_details'] = $currentSftp;
        }

        $currentAllocations = Arr::get($attributes, 'relationships.allocations.data');
        $snapshotAllocations = Arr::get($snapshot, 'relationships.allocations.data');
        if (
            (!is_array($currentAllocations) || $currentAllocations === []) &&
            is_array($snapshotAllocations) &&
            $snapshotAllocations !== []
        ) {
            Arr::set($attributes, 'relationships.allocations.data', $snapshotAllocations);
        }

        $currentEggRelationship = Arr::get($attributes, 'relationships.egg.data');
        $snapshotEggRelationship = Arr::get($snapshot, 'relationships.egg.data');
        if (!is_array($currentEggRelationship) && is_array($snapshotEggRelationship)) {
            Arr::set($attributes, 'relationships.egg.data', $snapshotEggRelationship);
        }

        $currentEggId = $this->extractEggId($attributes);
        if (is_null($currentEggId)) {
            $snapshotEggId = $this->extractEggId($snapshot);
            if (!is_null($snapshotEggId)) {
                $attributes['egg_id'] = $snapshotEggId;

                $blueprintFramework = Arr::get($attributes, 'BlueprintFramework');
                if (!is_array($blueprintFramework)) {
                    $blueprintFramework = [];
                }

                $blueprintFramework['egg_id'] = $snapshotEggId;
                $attributes['BlueprintFramework'] = $blueprintFramework;
            }
        }

        return $attributes;
    }

    protected function loadServerSnapshot(ExternalServerReference $reference): ?array
    {
        $cache = ExternalServerCache::query()
            ->where('external_panel_connection_id', $reference->connection->id)
            ->where('external_server_identifier', $reference->serverIdentifier)
            ->first();

        if (is_null($cache) || !is_array($cache->meta_json)) {
            return null;
        }

        $snapshot = Arr::get($cache->meta_json, 'server_snapshot');

        return is_array($snapshot) ? $snapshot : null;
    }

    protected function loadResourcesSnapshot(ExternalServerReference $reference): ?array
    {
        $cache = ExternalServerCache::query()
            ->where('external_panel_connection_id', $reference->connection->id)
            ->where('external_server_identifier', $reference->serverIdentifier)
            ->first();

        if (is_null($cache) || !is_array($cache->meta_json)) {
            return null;
        }

        $snapshot = Arr::get($cache->meta_json, 'resources_snapshot');

        return is_array($snapshot) ? $snapshot : null;
    }

    protected function loadCachedCapabilities(ExternalServerReference $reference): ?array
    {
        $cache = ExternalServerCache::query()
            ->where('external_panel_connection_id', $reference->connection->id)
            ->where('external_server_identifier', $reference->serverIdentifier)
            ->first();

        if (is_null($cache) || !is_array($cache->meta_json)) {
            return null;
        }

        $capabilities = Arr::get($cache->meta_json, 'capabilities');

        return is_array($capabilities) ? $capabilities : null;
    }

    protected function serverDetailFallbackPayload(ExternalServerReference $reference): ?array
    {
        $snapshot = $this->loadServerSnapshot($reference);
        if (is_null($snapshot)) {
            return null;
        }

        $attributes = [
            'identifier' => $reference->serverIdentifier,
            'name' => (string) Arr::get($snapshot, 'name', $reference->serverIdentifier),
            'node' => Arr::get($snapshot, 'node'),
            'description' => '',
            'status' => $this->normalizePowerState((string) Arr::get($snapshot, 'status', 'offline')),
            'invocation' => '',
            'docker_image' => '',
            'limits' => is_array(Arr::get($snapshot, 'limits')) ? Arr::get($snapshot, 'limits') : [],
            'feature_limits' => is_array(Arr::get($snapshot, 'feature_limits')) ? Arr::get($snapshot, 'feature_limits') : [],
            'sftp_details' => is_array(Arr::get($snapshot, 'sftp_details')) ? Arr::get($snapshot, 'sftp_details') : [],
            'relationships' => is_array(Arr::get($snapshot, 'relationships')) ? Arr::get($snapshot, 'relationships') : [],
            'is_node_under_maintenance' => false,
            'is_transferring' => false,
            'is_installing' => false,
            'is_suspended' => $this->booleanValue(Arr::get($snapshot, 'is_suspended', false)),
        ];

        $normalized = $this->normalizeServerResource([
            'object' => 'server',
            'attributes' => $attributes,
        ], $reference);
        $normalized['attributes'] = $this->withExternalSftpUsername($normalized['attributes'], $reference);

        $capabilities = $this->loadCachedCapabilities($reference) ?? [];
        $normalized['attributes']['external_capabilities'] = $capabilities;

        return [
            'object' => $normalized['object'] ?? 'server',
            'attributes' => $normalized['attributes'],
            'meta' => [
                'is_server_owner' => false,
                'user_permissions' => $this->capabilityDetector->permissionsFromCapabilities($capabilities),
            ],
        ];
    }

    protected function persistResourcesSnapshot(ExternalServerReference $reference, array $payload): void
    {
        $cache = ExternalServerCache::query()->firstOrNew([
            'external_panel_connection_id' => $reference->connection->id,
            'external_server_identifier' => $reference->serverIdentifier,
        ]);

        $meta = is_array($cache->meta_json) ? $cache->meta_json : [];
        $meta['resources_snapshot'] = $payload;

        $cache->fill([
            'name' => $cache->name ?: $reference->serverIdentifier,
            'node' => $cache->node,
            'meta_json' => $meta,
            'last_synced_at' => CarbonImmutable::now(),
        ])->save();
    }

    protected function resourcesFallbackPayload(ExternalServerReference $reference): array
    {
        $snapshot = $this->loadResourcesSnapshot($reference);
        if (!is_null($snapshot)) {
            return $snapshot;
        }

        $serverSnapshot = $this->loadServerSnapshot($reference) ?? [];
        $status = $this->normalizePowerState((string) Arr::get($serverSnapshot, 'status', 'offline'));

        return [
            'object' => 'stats',
            'attributes' => [
                'current_state' => $status,
                'is_suspended' => $this->booleanValue(Arr::get($serverSnapshot, 'is_suspended', false)),
                'resources' => [
                    'memory_bytes' => 0,
                    'cpu_absolute' => 0,
                    'disk_bytes' => 0,
                    'network_rx_bytes' => 0,
                    'network_tx_bytes' => 0,
                    'uptime' => 0,
                ],
            ],
        ];
    }

    protected function normalizeResourcesPayload(array $payload): array
    {
        $attributes = [];
        if (is_array(Arr::get($payload, 'attributes'))) {
            $attributes = Arr::get($payload, 'attributes', []);
        } elseif (is_array(Arr::get($payload, 'data.attributes'))) {
            $attributes = Arr::get($payload, 'data.attributes', []);
        } elseif (is_array(Arr::get($payload, 'data'))) {
            $attributes = Arr::get($payload, 'data', []);
        }

        if ($attributes === []) {
            $attributes = collect($payload)->except(['object', 'data', 'meta'])->all();
        }

        $resources = Arr::get($attributes, 'resources', []);
        if (!is_array($resources)) {
            $resources = [];
        }

        $network = Arr::get($resources, 'network', []);
        if (!is_array($network)) {
            $network = [];
        }

        return [
            'object' => 'stats',
            'attributes' => [
                'current_state' => $this->normalizePowerState((string) Arr::get($attributes, 'current_state', Arr::get($attributes, 'state', 'offline'))),
                'is_suspended' => $this->booleanValue(Arr::get($attributes, 'is_suspended', false)),
                'resources' => [
                    'memory_bytes' => $this->numericValue(Arr::get($resources, 'memory_bytes', Arr::get($attributes, 'memory_bytes', Arr::get($resources, 'memory', 0)))),
                    'cpu_absolute' => $this->numericValue(Arr::get($resources, 'cpu_absolute', Arr::get($attributes, 'cpu_absolute', Arr::get($resources, 'cpu', 0)))),
                    'disk_bytes' => $this->numericValue(Arr::get($resources, 'disk_bytes', Arr::get($attributes, 'disk_bytes', Arr::get($resources, 'disk', 0)))),
                    'network_rx_bytes' => $this->numericValue(Arr::get($network, 'rx_bytes', Arr::get($resources, 'network_rx_bytes', Arr::get($attributes, 'network_rx_bytes', 0)))),
                    'network_tx_bytes' => $this->numericValue(Arr::get($network, 'tx_bytes', Arr::get($resources, 'network_tx_bytes', Arr::get($attributes, 'network_tx_bytes', 0)))),
                    'uptime' => $this->numericValue(Arr::get($resources, 'uptime', Arr::get($attributes, 'uptime', 0))),
                ],
            ],
        ];
    }

    protected function withExternalSftpUsername(array $attributes, ExternalServerReference $reference): array
    {
        $sftpDetails = Arr::get($attributes, 'sftp_details');
        if (!is_array($sftpDetails)) {
            $sftpDetails = [];
        }

        $providedUsername = trim((string) Arr::get($sftpDetails, 'username', Arr::get($attributes, 'sftp_username', '')));
        if ($providedUsername !== '') {
            $sftpDetails['username'] = $providedUsername;
            $attributes['sftp_details'] = $sftpDetails;

            return $attributes;
        }

        $panelUsername = $this->externalPanelAccountUsername($reference->connection);
        $serverIdentifier = trim((string) Arr::get($attributes, 'external_server_identifier', $reference->serverIdentifier));

        if ($panelUsername !== null && $serverIdentifier !== '') {
            $sftpDetails['username'] = sprintf('%s.%s', $panelUsername, $serverIdentifier);
            $attributes['sftp_details'] = $sftpDetails;
        }

        return $attributes;
    }

    protected function externalPanelAccountUsername(ExternalPanelConnection $connection): ?string
    {
        return $this->remember(
            $this->externalPanelAccountUsernameCacheKey($connection),
            600,
            function () use ($connection) {
                try {
                    $response = $this->client->requestWithFallback($connection, 'GET', ['account'], [
                        '_timeout' => 5,
                        '_connect_timeout' => 2,
                        '_retry_delays' => [],
                    ]);
                } catch (\Throwable $exception) {
                    Log::notice('Unable to resolve external panel account username for SFTP display.', [
                        'connection_id' => $connection->id,
                        'error' => $exception->getMessage(),
                    ]);

                    return null;
                }

                if (!$response->successful()) {
                    return null;
                }

                $payload = $response->json() ?? [];
                $username = trim((string) (
                    Arr::get($payload, 'attributes.username')
                    ?? Arr::get($payload, 'data.attributes.username')
                    ?? Arr::get($payload, 'data.username')
                    ?? ''
                ));

                return $username !== '' ? $username : null;
            }
        );
    }

    protected function externalPanelAccountUsernameCacheKey(ExternalPanelConnection $connection): string
    {
        return sprintf('external:connection:%d:account:username', $connection->id);
    }

    protected function normalizePowerState(string $state): string
    {
        $normalized = strtolower(trim($state));

        return match ($normalized) {
            'running' => 'running',
            'starting' => 'starting',
            'stopping' => 'stopping',
            'stopped', 'offline', '' => 'offline',
            default => 'offline',
        };
    }

    protected function normalizeServerStatus(mixed $status, bool $isSuspended, mixed $fallbackState = null): ?string
    {
        if ($isSuspended) {
            return 'suspended';
        }

        $candidates = [];
        if (is_string($status)) {
            $candidates[] = $status;
        }
        if (is_string($fallbackState)) {
            $candidates[] = $fallbackState;
        }

        foreach ($candidates as $candidate) {
            $normalized = strtolower(trim($candidate));
            if ($normalized === '') {
                continue;
            }

            if ($normalized === 'suspended') {
                return 'suspended';
            }

            if (in_array($normalized, ['installing', 'install_failed', 'reinstall_failed', 'restoring_backup'], true)) {
                return $normalized;
            }

            if (in_array($normalized, ['running', 'starting', 'stopping', 'stopped', 'offline'], true)) {
                return $this->normalizePowerState($normalized);
            }
        }

        return null;
    }

    protected function numericValue(mixed $value): float|int
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized !== '' && is_numeric($normalized)) {
                return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
            }
        }

        return 0;
    }

    protected function hasNonZeroLimits(array $limits): bool
    {
        foreach (['memory', 'disk', 'cpu', 'swap', 'io'] as $key) {
            $value = $this->numericValue(Arr::get($limits, $key, 0));
            if ($value > 0) {
                return true;
            }
        }

        return false;
    }

    protected function isLimitPayload(array $payload): bool
    {
        foreach (['memory', 'swap', 'disk', 'io', 'cpu'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    protected function serverSnapshotFromAttributes(array $attributes): array
    {
        $eggId = $this->extractEggId($attributes);
        $blueprintFramework = Arr::get($attributes, 'BlueprintFramework');
        if (!is_array($blueprintFramework)) {
            $blueprintFramework = [];
        }

        if (!is_null($eggId)) {
            $blueprintFramework['egg_id'] = $eggId;
        }

        return [
            'limits' => is_array(Arr::get($attributes, 'limits')) ? Arr::get($attributes, 'limits') : [],
            'feature_limits' => is_array(Arr::get($attributes, 'feature_limits')) ? Arr::get($attributes, 'feature_limits') : [],
            'sftp_details' => is_array(Arr::get($attributes, 'sftp_details')) ? Arr::get($attributes, 'sftp_details') : [],
            'relationships' => is_array(Arr::get($attributes, 'relationships')) ? Arr::get($attributes, 'relationships') : [],
            'egg_id' => $eggId,
            'BlueprintFramework' => $blueprintFramework,
            'status' => Arr::get($attributes, 'status'),
            'is_suspended' => $this->booleanValue(Arr::get($attributes, 'is_suspended', false)),
            'node' => Arr::get($attributes, 'node'),
            'name' => Arr::get($attributes, 'name'),
        ];
    }

    protected function extractEggId(array $payload): ?int
    {
        $candidates = [
            Arr::get($payload, 'BlueprintFramework.egg_id'),
            Arr::get($payload, 'egg_id'),
            Arr::get($payload, 'egg'),
            Arr::get($payload, 'relationships.egg.data.attributes.id'),
            Arr::get($payload, 'relationships.egg.data.id'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizePositiveInteger($candidate);
            if (!is_null($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    protected function normalizePositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $normalized = (int) $value;

            return $normalized > 0 ? $normalized : null;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '' || !ctype_digit($normalized)) {
                return null;
            }

            $parsed = (int) $normalized;

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    protected function featureForEndpoint(string $endpoint): ?string
    {
        $endpoint = trim($endpoint, '/');
        $map = [
            'resources' => 'resources',
            'websocket' => 'websocket',
            'power' => 'power',
            'command' => 'command',
            'files/' => 'files',
            'backups' => 'backups',
            'startup' => 'startup',
            'network/' => 'network',
            'settings/rename' => 'settings.rename',
            'settings/reinstall' => 'settings.reinstall',
            'settings/docker-image' => 'settings.docker-image',
            'activity' => 'activity',
            'databases' => 'databases',
            'schedules' => 'schedules',
            'users' => 'users',
        ];

        foreach ($map as $needle => $feature) {
            if (Str::contains($endpoint, $needle)) {
                return $feature;
            }
        }

        return null;
    }

    protected function remember(string $key, int $ttlSeconds, \Closure $callback): mixed
    {
        if ($ttlSeconds <= 0) {
            return $callback();
        }

        try {
            return $this->cache->remember($key, CarbonImmutable::now()->addSeconds($ttlSeconds), $callback);
        } catch (\Throwable $exception) {
            // Let upstream HTTP semantics bubble up to callers. Treat only cache
            // backend failures as cache warnings.
            if ($exception instanceof HttpException) {
                throw $exception;
            }

            $this->logCacheWarning($exception, $key);

            return $callback();
        }
    }

    protected function forget(string $key): void
    {
        try {
            $this->cache->forget($key);
        } catch (\Throwable $exception) {
            $this->logCacheWarning($exception, $key);
        }
    }

    protected function forgetServerCaches(ExternalServerReference $reference): void
    {
        $this->forget($this->serverDetailCacheKey($reference));
        $this->forget($this->serverResourcesCacheKey($reference));
    }

    protected function invalidateServerCachesForMutation(ExternalServerReference $reference, string $method): void
    {
        $normalized = strtoupper(trim($method));
        if (in_array($normalized, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $this->forgetServerCaches($reference);
    }

    protected function serverDetailCacheKey(ExternalServerReference $reference): string
    {
        return sprintf(
            'external:server:%d:%s:detail',
            $reference->connection->id,
            $reference->serverIdentifier
        );
    }

    protected function serverResourcesCacheKey(ExternalServerReference $reference): string
    {
        return sprintf(
            'external:server:%d:%s:resources',
            $reference->connection->id,
            $reference->serverIdentifier
        );
    }

    protected function serverListTtlSeconds(): int
    {
        return max(0, (int) config('pterodactyl.external_panel.cache.server_list_ttl', self::SERVER_LIST_TTL_SECONDS));
    }

    protected function serverDetailTtlSeconds(): int
    {
        return max(0, (int) config('pterodactyl.external_panel.cache.server_detail_ttl', self::SERVER_DETAIL_TTL_SECONDS));
    }

    protected function serverResourcesTtlSeconds(): int
    {
        return max(0, (int) config('pterodactyl.external_panel.cache.server_resources_ttl', self::SERVER_RESOURCES_TTL_SECONDS));
    }

    protected function serverListRequestTimeoutSeconds(): int
    {
        return max(2, (int) config('pterodactyl.external_panel.timeouts.server_list', self::SERVER_LIST_REQUEST_TIMEOUT_SECONDS));
    }

    protected function serverListConnectTimeoutSeconds(): int
    {
        return max(1, (int) config('pterodactyl.external_panel.timeouts.server_list_connect', self::SERVER_LIST_CONNECT_TIMEOUT_SECONDS));
    }

    protected function serverDetailRequestTimeoutSeconds(): int
    {
        return max(2, (int) config('pterodactyl.external_panel.timeouts.server_detail', self::SERVER_DETAIL_REQUEST_TIMEOUT_SECONDS));
    }

    protected function serverDetailConnectTimeoutSeconds(): int
    {
        return max(1, (int) config('pterodactyl.external_panel.timeouts.server_detail_connect', self::SERVER_DETAIL_CONNECT_TIMEOUT_SECONDS));
    }

    protected function serverResourcesRequestTimeoutSeconds(): int
    {
        return max(2, (int) config('pterodactyl.external_panel.timeouts.server_resources', self::SERVER_RESOURCES_REQUEST_TIMEOUT_SECONDS));
    }

    protected function serverResourcesConnectTimeoutSeconds(): int
    {
        return max(1, (int) config('pterodactyl.external_panel.timeouts.server_resources_connect', self::SERVER_RESOURCES_CONNECT_TIMEOUT_SECONDS));
    }

    protected function logCacheWarning(\Throwable $exception, string $key): void
    {
        if ($this->cacheWarningLogged) {
            return;
        }

        $this->cacheWarningLogged = true;
        Log::warning('External cache access failed, bypassing cache for this request.', [
            'cache_key' => $key,
            'error' => $exception->getMessage(),
        ]);
    }
}
