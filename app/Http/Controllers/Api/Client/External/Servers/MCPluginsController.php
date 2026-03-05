<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\MCPluginsConfig;
use Illuminate\Auth\Access\AuthorizationException;

class MCPluginsController extends ExternalServerApiController
{
    protected array $httpClient;
    private string $directory = '/plugins';

    public function __construct(\Pterodactyl\Services\External\ExternalServerRepository $repository)
    {
        parent::__construct($repository);

        $apiKey = MCPluginsConfig::first()?->curseforge_api_key;
        $this->httpClient = [
            'modrinth' => new Client(['base_uri' => 'https://api.modrinth.com/v2/']),
            'curseforge' => new Client([
                'base_uri' => 'https://api.curseforge.com/v1/',
                'headers' => ['X-API-Key' => $apiKey ?? ''],
            ]),
            'spigotmc' => new Client(['base_uri' => 'https://api.spiget.org/v2/']),
            'hangar' => new Client(['base_uri' => 'https://hangar.papermc.io/api/v1/']),
            'polymart' => new Client(['base_uri' => 'https://api.polymart.org/v1/']),
        ];
    }

    public function index(Request $request, string $externalServer)
    {
        $this->assertExternalPermission($request, $externalServer, Permission::ACTION_FILE_READ);

        $provider = $this->validatedProvider($request->query('provider', 'modrinth'));
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('page_size', 6));
        $searchQuery = (string) $request->query('search_query', '');
        $loader = (string) $request->query('loader', '');
        $sortBy = (string) $request->query('sort_by', '');
        $minecraftVersion = (string) $request->query('minecraft_version', '');

        $url = $this->getPluginsUrl($provider, $page, $pageSize, $searchQuery, $loader, $sortBy, $minecraftVersion);

        try {
            $response = $this->httpClient[$provider]->get($url);
            if ($response->getStatusCode() !== 200) {
                return response()->json(['status' => 'error', 'message' => 'Error fetching plugins.'], 503);
            }

            $data = json_decode($response->getBody()->getContents(), true);

            return response()->json([
                'data' => $this->formatPluginsResponse($provider, $data),
                'pagination' => $this->pluginsPagination($provider, $data, $page, $pageSize),
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error fetching plugins: ' . $exception->getMessage(),
            ], 500);
        }
    }

    public function versions(Request $request, string $externalServer)
    {
        $this->assertExternalPermission($request, $externalServer, Permission::ACTION_FILE_READ);

        $provider = $this->validatedProvider($request->query('provider', 'modrinth'));
        $pluginId = (string) $request->query('pluginId', '');
        if ($pluginId === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing pluginId.'], 422);
        }

        try {
            $response = $this->httpClient[$provider]->get($this->getVersionsUrl($provider, $pluginId));
            if ($response->getStatusCode() !== 200) {
                return response()->json(['status' => 'error', 'message' => 'Error fetching versions.'], 503);
            }

            $data = json_decode($response->getBody()->getContents(), true);

            return response()->json([
                'data' => $this->formatVersionsResponse($provider, $data),
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error fetching versions: ' . $exception->getMessage(),
            ], 500);
        }
    }

    public function install(Request $request, string $externalServer)
    {
        $this->assertExternalPermission($request, $externalServer, Permission::ACTION_FILE_CREATE);

        $provider = $this->validatedProvider($request->input('provider', 'modrinth'));
        $pluginId = (string) $request->input('pluginId', '');
        $versionId = $request->filled('versionId') ? (string) $request->input('versionId') : null;

        if ($pluginId === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing pluginId.'], 422);
        }

        try {
            $plugin = $this->fetchPluginData($provider, $pluginId, $versionId);

            $payload = [
                'url' => $plugin['url'],
                'directory' => $this->directory,
                'use_header' => true,
                'foreground' => true,
            ];

            if (!empty($plugin['name'])) {
                $payload['filename'] = $plugin['name'];
            }

            $this->proxyNoContent(
                $request,
                $externalServer,
                'POST',
                $this->serverEndpoint($externalServer, 'files/pull'),
                ['json' => $payload]
            );

            return response()->json(['status' => 'success', 'message' => 'Plugin installed successfully.']);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while installing the plugin: ' . $exception->getMessage(),
            ], 503);
        }
    }

    public function settings(Request $request, string $externalServer)
    {
        $this->assertExternalPermission($request, $externalServer, Permission::ACTION_FILE_READ);

        $settings = Cache::rememberForever('mcplugins_settings', function () {
            return MCPluginsConfig::select(
                'default_page_size',
                'default_provider',
                'text_install_button',
                'text_versions_button',
                'text_download_button',
                'text_search',
                'text_search_box',
                'text_version',
                'text_loader',
                'text_sort_by',
                'text_provider',
                'text_page_size',
                'text_not_found',
                'text_showing',
                'text_version_list',
                'text_versions_not_found',
                'text_version_downloads',
                'text_redirect_url',
                'text_download_url',
                'text_install_success',
                'text_install_failed',
            )->first();
        });

        return response()->json($settings);
    }

    private function assertExternalPermission(Request $request, string $externalServer, string $permission): void
    {
        $permissions = $this->repository->getPermissions($this->externalIdentifier($externalServer), $request->user());
        if (!$this->hasPermission($permissions, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function hasPermission(array $permissions, string $required): bool
    {
        if (in_array('*', $permissions, true) || in_array($required, $permissions, true)) {
            return true;
        }

        $namespace = explode('.', $required)[0] . '.*';

        return in_array($namespace, $permissions, true);
    }

    private function validatedProvider(?string $provider): string
    {
        $provider = is_string($provider) ? strtolower($provider) : 'modrinth';
        $allowed = ['modrinth', 'curseforge', 'spigotmc', 'hangar', 'polymart'];

        return in_array($provider, $allowed, true) ? $provider : 'modrinth';
    }

    private function getPluginsUrl(
        string $provider,
        int $page,
        int $pageSize,
        string $searchQuery,
        string $loader,
        string $sortBy,
        string $minecraftVersion
    ): string {
        $offset = ($page - 1) * $pageSize;

        return match ($provider) {
            'modrinth' => $this->getModrinthUrl($pageSize, $searchQuery, $sortBy, $offset, $loader, $minecraftVersion),
            'curseforge' => $this->getCurseForgeUrl($pageSize, $searchQuery, $sortBy, $offset, $loader, $minecraftVersion),
            'hangar' => $this->getHangarUrl($pageSize, $offset, $searchQuery, $sortBy, $minecraftVersion),
            'spigotmc' => $this->getSpigotUrl($pageSize, $page, $searchQuery, $sortBy),
            'polymart' => $this->getPolymartUrl($pageSize, $page, $searchQuery, $sortBy),
        };
    }

    private function getModrinthUrl(
        int $pageSize,
        string $searchQuery,
        string $sortBy,
        int $offset,
        string $loader,
        string $minecraftVersion
    ): string {
        $facets = [
            ["categories:$loader"],
            ['server_side!=unsupported'],
        ];

        if ($minecraftVersion !== '') {
            $facets[] = ["versions:$minecraftVersion"];
        }

        $facetsQuery = urlencode(json_encode($facets));

        return "search?limit={$pageSize}&query={$searchQuery}&index={$sortBy}&offset={$offset}&facets={$facetsQuery}";
    }

    private function getCurseForgeUrl(
        int $pageSize,
        string $searchQuery,
        string $sortBy,
        int $offset,
        string $loader,
        string $minecraftVersion
    ): string {
        return "mods/search?gameId=432&classId=5&pageSize={$pageSize}&index={$offset}&searchFilter={$searchQuery}&modLoaderType={$loader}&gameVersion={$minecraftVersion}&sortField={$sortBy}&sortOrder=desc";
    }

    private function getSpigotUrl(int $pageSize, int $page, string $searchQuery, string $sortBy): string
    {
        $base = $searchQuery !== '' ? "search/resources/{$searchQuery}" : 'resources';

        return "{$base}?size={$pageSize}&page={$page}&sort={$sortBy}";
    }

    private function getHangarUrl(
        int $pageSize,
        int $offset,
        string $searchQuery,
        string $sortBy,
        string $minecraftVersion
    ): string {
        $params = [
            'limit' => $pageSize,
            'offset' => $offset,
            'sort' => $sortBy,
        ];

        if ($minecraftVersion !== '') {
            $params['version'] = $minecraftVersion;
        }

        if ($searchQuery !== '') {
            $params['query'] = $searchQuery;
        }

        return 'projects?' . http_build_query($params);
    }

    private function getPolymartUrl(int $pageSize, int $page, string $searchQuery, string $sortBy): string
    {
        return "search?limit={$pageSize}&start={$page}&query={$searchQuery}&sort={$sortBy}";
    }

    private function pluginsPagination(string $provider, array $data, int $page, int $pageSize): array
    {
        return match ($provider) {
            'modrinth' => [
                'total' => (int) ($data['total_hits'] ?? 0),
                'count' => count($data['hits'] ?? []),
                'per_page' => $pageSize,
                'current_page' => $page,
                'total_pages' => (int) ceil(((int) ($data['total_hits'] ?? 0)) / $pageSize),
            ],
            'curseforge' => [
                'total' => (int) ($data['pagination']['totalCount'] ?? 0),
                'count' => (int) ($data['pagination']['resultCount'] ?? 0),
                'per_page' => $pageSize,
                'current_page' => $page,
                'total_pages' => (int) ceil((min((int) ($data['pagination']['totalCount'] ?? 0), 5000)) / $pageSize),
            ],
            'hangar' => [
                'total' => (int) ($data['pagination']['count'] ?? 0),
                'count' => count($data['result'] ?? []),
                'per_page' => $pageSize,
                'current_page' => $page,
                'total_pages' => (int) ceil(((int) ($data['pagination']['count'] ?? 0)) / $pageSize),
            ],
            'spigotmc' => [
                'total' => (int) ((count($data) < $pageSize) ? count($data) : 300),
                'count' => count($data),
                'per_page' => $pageSize,
                'current_page' => $page,
                'total_pages' => (int) ((count($data) < $pageSize) ? 1 : 50),
            ],
            'polymart' => [
                'total' => (int) ($data['response']['total'] ?? 0),
                'count' => (int) ($data['response']['result_count'] ?? 0),
                'per_page' => $pageSize,
                'current_page' => $page,
                'total_pages' => (int) ceil(((int) ($data['response']['total'] ?? 0)) / $pageSize),
            ],
        };
    }

    private function formatPluginsResponse(string $provider, array $data): array
    {
        return match ($provider) {
            'modrinth' => array_map(fn ($plugin) => [
                'provider' => 'modrinth',
                'id' => $plugin['project_id'],
                'name' => $plugin['title'],
                'description' => $plugin['description'],
                'icon' => $plugin['icon_url'],
                'downloads' => $plugin['downloads'],
                'url' => "https://modrinth.com/plugin/{$plugin['project_id']}",
                'installable' => true,
            ], $data['hits'] ?? []),
            'curseforge' => array_map(fn ($plugin) => [
                'provider' => 'curseforge',
                'id' => $plugin['id'],
                'name' => $plugin['name'],
                'description' => $plugin['summary'],
                'icon' => $plugin['logo']['url'] ?? null,
                'downloads' => $plugin['downloadCount'] ?? 0,
                'url' => "https://www.curseforge.com/minecraft/bukkit-plugins/{$plugin['slug']}",
                'installable' => true,
            ], $data['data'] ?? []),
            'hangar' => array_map(fn ($plugin) => [
                'provider' => 'hangar',
                'id' => $plugin['name'],
                'name' => $plugin['name'],
                'description' => $plugin['description'],
                'icon' => $plugin['avatarUrl'],
                'downloads' => $plugin['stats']['downloads'],
                'url' => "https://hangar.papermc.io/{$plugin['namespace']['owner']}/{$plugin['name']}",
                'installable' => true,
            ], $data['result'] ?? []),
            'spigotmc' => array_map(function ($plugin) {
                $installable = true;
                if (isset($plugin['file']['externalUrl']) && !str_ends_with((string) $plugin['file']['externalUrl'], '.jar')) {
                    $installable = false;
                }
                if (!empty($plugin['premium'])) {
                    $installable = false;
                }

                return [
                    'provider' => 'spigotmc',
                    'id' => $plugin['id'],
                    'name' => $plugin['name'],
                    'description' => $plugin['tag'],
                    'icon' => "https://www.spigotmc.org/{$plugin['icon']['url']}",
                    'downloads' => $plugin['downloads'],
                    'url' => "https://www.spigotmc.org/resources/{$plugin['id']}",
                    'installable' => $installable,
                ];
            }, $data),
            'polymart' => array_map(fn ($plugin) => [
                'provider' => 'polymart',
                'id' => $plugin['id'],
                'name' => $plugin['title'],
                'description' => $plugin['subtitle'],
                'icon' => $plugin['thumbnailURL'],
                'downloads' => $plugin['totalDownloads'],
                'url' => $plugin['url'],
                'installable' => (bool) ($plugin['canDownload'] ?? false),
            ], $data['response']['result'] ?? []),
        };
    }

    private function getVersionsUrl(string $provider, string $pluginId): string
    {
        return match ($provider) {
            'modrinth' => "project/{$pluginId}/version",
            'curseforge' => "mods/{$pluginId}/files",
            'spigotmc' => "resources/{$pluginId}/versions?sort=-releaseDate",
            'hangar' => "projects/{$pluginId}/versions",
            'polymart' => "getResourceUpdates/&resource_id={$pluginId}",
        };
    }

    private function formatVersionsResponse(string $provider, array $data): array
    {
        return match ($provider) {
            'modrinth' => array_map(fn ($version) => [
                'provider' => $provider,
                'versionId' => $version['id'],
                'versionName' => $version['name'],
                'game_versions' => $version['game_versions'],
                'loaders' => $version['loaders'],
                'downloads' => ($version['downloads'] ?? 0) > 0 ? $version['downloads'] : null,
                'downloadUrl' => null,
            ], $data),
            'curseforge' => array_map(fn ($version) => [
                'provider' => $provider,
                'versionId' => $version['id'],
                'versionName' => $version['displayName'],
                'game_versions' => $version['gameVersions'],
                'loaders' => null,
                'downloads' => ($version['downloadCount'] ?? 0) > 0 ? $version['downloadCount'] : null,
                'downloadUrl' => null,
            ], $data['data'] ?? []),
            'hangar' => $this->formatHangarVersions($data['result'] ?? [], $provider),
            'spigotmc' => array_map(fn ($version) => [
                'provider' => $provider,
                'versionId' => $version['id'],
                'versionName' => $version['name'],
                'downloads' => $version['downloads'],
                'game_versions' => null,
                'loaders' => null,
                'downloadUrl' => "https://www.spigotmc.org/resources/{$version['resource']}/download?version={$version['id']}",
            ], $data),
            'polymart' => array_map(fn ($version) => [
                'provider' => $provider,
                'versionId' => $version['id'],
                'versionName' => $version['version'],
                'game_versions' => null,
                'loaders' => null,
                'downloads' => null,
                'downloadUrl' => $version['url'],
            ], $data['response']['updates'] ?? []),
        };
    }

    private function formatHangarVersions(array $versions, string $provider): array
    {
        $unique = [];
        foreach ($versions as $version) {
            $platformDownloads = [
                'PAPER' => $version['stats']['platformDownloads']['PAPER'] ?? 0,
                'WATERFALL' => $version['stats']['platformDownloads']['WATERFALL'] ?? 0,
                'VELOCITY' => $version['stats']['platformDownloads']['VELOCITY'] ?? 0,
            ];

            foreach ($platformDownloads as $platform => $downloads) {
                if ($downloads <= 0) {
                    continue;
                }

                $versionKey = $version['name'] . ' - ' . $platform;
                if (!isset($unique[$versionKey])) {
                    $unique[$versionKey] = [
                        'provider' => $provider,
                        'versionId' => $versionKey,
                        'versionName' => $versionKey,
                        'downloads' => $downloads,
                        'game_versions' => null,
                        'loaders' => null,
                        'downloadUrl' => null,
                    ];
                }
            }
        }

        return array_values($unique);
    }

    private function fetchPluginData(string $provider, string $pluginId, ?string $versionId): array
    {
        return match ($provider) {
            'modrinth' => $this->fetchModrinthPluginData($pluginId, $versionId),
            'curseforge' => $this->fetchCurseForgePluginData($pluginId, $versionId),
            'hangar' => $this->fetchHangarPluginData($pluginId, $versionId),
            'spigotmc' => $this->fetchSpigotPluginData($pluginId),
            'polymart' => $this->fetchPolymartPluginData($pluginId),
            default => throw new \InvalidArgumentException('Unsupported plugin provider.'),
        };
    }

    private function fetchModrinthPluginData(string $pluginId, ?string $versionId): array
    {
        $response = $this->httpClient['modrinth']->get($versionId ? "version/{$versionId}" : "project/{$pluginId}/version");
        $data = json_decode($response->getBody()->getContents(), true);
        $pluginFile = $versionId ? ($data['files'][0] ?? null) : ($data[0]['files'][0] ?? null);
        if (!$pluginFile || empty($pluginFile['url'])) {
            throw new \RuntimeException('Modrinth file URL not found.');
        }

        return ['url' => $pluginFile['url'], 'name' => $pluginFile['filename'] ?? 'plugin.jar'];
    }

    private function fetchCurseForgePluginData(string $pluginId, ?string $versionId): array
    {
        $response = $this->httpClient['curseforge']->get($versionId ? "mods/{$pluginId}/files/{$versionId}" : "mods/{$pluginId}/files");
        $data = json_decode($response->getBody()->getContents(), true);
        $pluginFile = $versionId ? ($data['data'] ?? null) : ($data['data'][0] ?? null);
        $downloadUrl = $pluginFile['downloadUrl'] ?? null;
        if (!$downloadUrl) {
            throw new \RuntimeException('CurseForge download URL not found.');
        }

        return [
            'url' => str_replace('edge', 'mediafiles', $downloadUrl),
            'name' => $pluginFile['fileName'] ?? 'plugin.jar',
        ];
    }

    private function fetchHangarPluginData(string $pluginId, ?string $versionId): array
    {
        $client = $this->httpClient['hangar'];
        if ($versionId) {
            [$versionNumber, $serverType] = explode(' - ', $versionId);
            $response = $client->get("projects/{$pluginId}/versions/{$versionNumber}");
            $data = json_decode($response->getBody()->getContents(), true);
            $download = $data['downloads'][$serverType] ?? null;
        } else {
            $response = $client->get("projects/{$pluginId}/versions");
            $data = json_decode($response->getBody()->getContents(), true);
            $first = $data['result'][0] ?? null;
            $download = $first['downloads']['PAPER'] ?? null;
        }

        $downloadUrl = $download['downloadUrl'] ?? ($download['externalUrl'] ?? null);
        if (!$downloadUrl) {
            throw new \RuntimeException('Hangar download URL not found.');
        }

        return [
            'url' => $downloadUrl,
            'name' => $download['fileInfo']['name'] ?? 'plugin.jar',
        ];
    }

    private function fetchSpigotPluginData(string $pluginId): array
    {
        $response = $this->httpClient['spigotmc']->get("resources/{$pluginId}");
        $plugin = json_decode($response->getBody()->getContents(), true);
        $externalUrl = $plugin['file']['externalUrl'] ?? null;
        $downloadUrl = (is_string($externalUrl) && str_ends_with($externalUrl, '.jar'))
            ? $externalUrl
            : "https://cdn.spiget.org/file/spiget-resources/{$pluginId}.jar";

        return [
            'url' => $downloadUrl,
            'name' => ($plugin['name'] ?? 'plugin') . '.jar',
        ];
    }

    private function fetchPolymartPluginData(string $pluginId): array
    {
        $downloadResponse = $this->httpClient['polymart']->post('getDownloadURL', [
            'form_params' => [
                'allow_redirects' => '0',
                'resource_id' => $pluginId,
            ],
        ]);
        $downloadData = json_decode($downloadResponse->getBody()->getContents(), true);
        $downloadUrl = $downloadData['response']['result']['url'] ?? null;
        if (!$downloadUrl) {
            throw new \RuntimeException('Polymart download URL not found.');
        }

        $resourceResponse = $this->httpClient['polymart']->get("getResourceInfo?resource_id={$pluginId}");
        $resourceData = json_decode($resourceResponse->getBody()->getContents(), true);
        $pluginName = ($resourceData['response']['resource']['title'] ?? 'plugin') . '.jar';

        return ['url' => $downloadUrl, 'name' => $pluginName];
    }
}
