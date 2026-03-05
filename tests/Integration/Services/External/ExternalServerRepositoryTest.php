<?php

namespace Pterodactyl\Tests\Integration\Services\External;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\User;
use Pterodactyl\Models\ExternalServerCache;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Models\ExternalPanelConnection;
use Pterodactyl\Services\External\ExternalServerRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExternalServerRepositoryTest extends IntegrationTestCase
{
    public function testListServersForUserReturnsNormalizedExternalIdentifiers(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://example.com/api/client' || str_starts_with($request->url(), 'https://example.com/api/client?')) {
                return Http::response([
                    'object' => 'list',
                    'data' => [
                        [
                            'object' => 'server',
                            'attributes' => [
                                'identifier' => 'abcd1234',
                                'internal_id' => 1,
                                'uuid' => 'b8fdb3ec-d6b9-4abc-8d99-38cc2f2c6ca1',
                                'name' => 'External One',
                                'node' => 'node-a',
                                'is_node_under_maintenance' => false,
                                'status' => null,
                                'description' => null,
                                'limits' => [
                                    'memory' => 1024,
                                    'swap' => 0,
                                    'disk' => 10240,
                                    'io' => 500,
                                    'cpu' => 100,
                                    'threads' => null,
                                ],
                                'feature_limits' => ['databases' => 1, 'allocations' => 1, 'backups' => 1],
                                'is_transferring' => false,
                                'relationships' => [
                                    'variables' => ['data' => []],
                                    'allocations' => ['data' => []],
                                ],
                            ],
                        ],
                    ],
                    'meta' => [
                        'pagination' => [
                            'total' => 1,
                            'count' => 1,
                            'per_page' => 100,
                            'current_page' => 1,
                            'total_pages' => 1,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $repository = $this->app->make(ExternalServerRepository::class);
        $servers = $repository->listServersForUser($user, []);

        $this->assertCount(1, $servers);
        $this->assertSame("external:{$connection->id}:abcd1234", $servers[0]['attributes']['identifier']);
        $this->assertSame('external', $servers[0]['attributes']['source']);
    }

    public function testGetServerRemovesUnsupportedFeaturePermissions(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_ends_with($url, '/api/client/servers/abcd1234')) {
                return Http::response([
                    'object' => 'server',
                    'attributes' => [
                        'server_owner' => false,
                        'identifier' => 'abcd1234',
                        'internal_id' => 1,
                        'uuid' => 'b8fdb3ec-d6b9-4abc-8d99-38cc2f2c6ca1',
                        'name' => 'External One',
                        'node' => 'node-a',
                        'is_node_under_maintenance' => false,
                        'status' => null,
                        'description' => null,
                        'sftp_details' => [
                            'ip' => 'node-a.example.com',
                            'port' => 2022,
                        ],
                        'limits' => [
                            'memory' => 1024,
                            'swap' => 0,
                            'disk' => 10240,
                            'io' => 500,
                            'cpu' => 100,
                            'threads' => null,
                        ],
                        'feature_limits' => ['databases' => 1, 'allocations' => 1, 'backups' => 1],
                        'is_transferring' => false,
                        'relationships' => [
                            'variables' => ['data' => []],
                            'allocations' => ['data' => []],
                        ],
                    ],
                    'meta' => [
                        'is_server_owner' => false,
                        'user_permissions' => [
                            'websocket.connect',
                            'control.console',
                            'control.start',
                            'control.stop',
                            'control.restart',
                            'file.read',
                            'backup.read',
                            'backup.create',
                            'backup.delete',
                            'backup.download',
                            'backup.restore',
                        ],
                    ],
                ], 200);
            }

            if (str_ends_with($url, '/api/client/servers/abcd1234/backups')) {
                return Http::response(['errors' => [['detail' => 'Not found']]], 404);
            }

            return Http::response(['object' => 'list', 'data' => []], 200);
        });

        $repository = $this->app->make(ExternalServerRepository::class);
        $server = $repository->getServer("external:{$connection->id}:abcd1234", $user);

        $this->assertSame("external:{$connection->id}:abcd1234", $server['attributes']['identifier']);
        $this->assertSame('node-a.example.com', $server['attributes']['sftp_details']['ip']);
        $this->assertSame(1024, $server['attributes']['limits']['memory']);
        $this->assertFalse($server['attributes']['external_capabilities']['backups']);
        $this->assertNotContains('backup.read', $server['meta']['user_permissions']);
        $this->assertContains('file.read', $server['meta']['user_permissions']);
    }

    public function testProxyNoContentThrowsNotSupportedForMissingEndpoints(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('not supported');

        $user = User::factory()->create();
        $connection = $this->createConnection($user);

        Http::fake([
            'https://example.com/api/client/servers/abcd1234/settings/reinstall' => Http::response([
                'errors' => [['detail' => 'Not found']],
            ], 404),
        ]);

        $repository = $this->app->make(ExternalServerRepository::class);
        $repository->proxyNoContent(
            "external:{$connection->id}:abcd1234",
            $user,
            'POST',
            'servers/abcd1234/settings/reinstall'
        );
    }

    public function testGetResourcesFallsBackToCachedSnapshotOnServerError(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);

        ExternalServerCache::query()->create([
            'external_panel_connection_id' => $connection->id,
            'external_server_identifier' => 'abcd1234',
            'name' => 'External One',
            'node' => 'node-a',
            'meta_json' => [
                'resources_snapshot' => [
                    'object' => 'stats',
                    'attributes' => [
                        'current_state' => 'running',
                        'is_suspended' => false,
                        'resources' => [
                            'memory_bytes' => 1000,
                            'cpu_absolute' => 12.3,
                            'disk_bytes' => 5000,
                            'network_rx_bytes' => 120,
                            'network_tx_bytes' => 75,
                            'uptime' => 60000,
                        ],
                    ],
                ],
            ],
            'last_synced_at' => now(),
        ]);

        Http::fake([
            'https://example.com/api/client/servers/abcd1234/resources' => Http::response([
                'error' => 'Bad gateway',
            ], 502),
        ]);

        $repository = $this->app->make(ExternalServerRepository::class);
        $payload = $repository->getResources("external:{$connection->id}:abcd1234", $user);

        $this->assertSame('stats', $payload['object']);
        $this->assertSame('running', $payload['attributes']['current_state']);
        $this->assertSame(1000, $payload['attributes']['resources']['memory_bytes']);
    }

    public function testGetServerHydratesMissingLimitsAndAllocationsFromSnapshot(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);

        ExternalServerCache::query()->create([
            'external_panel_connection_id' => $connection->id,
            'external_server_identifier' => 'abcd1234',
            'name' => 'External One',
            'node' => 'node-a',
            'meta_json' => [
                'server_snapshot' => [
                    'limits' => [
                        'memory' => 4096,
                        'swap' => 0,
                        'disk' => 30720,
                        'io' => 500,
                        'cpu' => 150,
                    ],
                    'feature_limits' => [
                        'databases' => 0,
                        'allocations' => 1,
                        'backups' => 0,
                    ],
                    'sftp_details' => [
                        'ip' => 'node-a.example.com',
                        'port' => 2022,
                    ],
                    'relationships' => [
                        'allocations' => [
                            'data' => [
                                [
                                    'object' => 'allocation',
                                    'attributes' => [
                                        'id' => 11,
                                        'ip' => '0.0.0.0',
                                        'ip_alias' => 'node-a.example.com',
                                        'port' => 25565,
                                        'is_default' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'last_synced_at' => now(),
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_ends_with($url, '/api/client/servers/abcd1234')) {
                return Http::response([
                    'object' => 'server',
                    'attributes' => [
                        'server_owner' => true,
                        'identifier' => 'abcd1234',
                        'internal_id' => 1,
                        'uuid' => 'b8fdb3ec-d6b9-4abc-8d99-38cc2f2c6ca1',
                        'name' => 'External One',
                        'node' => 'node-a',
                        'status' => null,
                        'description' => null,
                        'sftp_details' => [],
                        'feature_limits' => [],
                        'is_transferring' => false,
                        'relationships' => [
                            'variables' => ['data' => []],
                            'allocations' => ['data' => []],
                        ],
                    ],
                    'meta' => [
                        'is_server_owner' => true,
                    ],
                ], 200);
            }

            return Http::response(['object' => 'list', 'data' => []], 200);
        });

        $repository = $this->app->make(ExternalServerRepository::class);
        $server = $repository->getServer("external:{$connection->id}:abcd1234", $user);

        $this->assertSame(4096, $server['attributes']['limits']['memory']);
        $this->assertSame('node-a.example.com', $server['attributes']['sftp_details']['ip']);
        $this->assertNotEmpty($server['attributes']['relationships']['allocations']['data']);
    }

    public function testGetServerUsesCacheForRepeatedRequests(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_ends_with($url, '/api/client/servers/abcd1234')) {
                return Http::response([
                    'object' => 'server',
                    'attributes' => [
                        'server_owner' => true,
                        'identifier' => 'abcd1234',
                        'name' => 'External One',
                        'node' => 'node-a',
                        'sftp_details' => [
                            'ip' => 'node-a.example.com',
                            'port' => 2022,
                        ],
                    ],
                    'meta' => [
                        'is_server_owner' => true,
                    ],
                ], 200);
            }

            return Http::response(['object' => 'list', 'data' => []], 200);
        });

        $repository = $this->app->make(ExternalServerRepository::class);
        $first = $repository->getServer("external:{$connection->id}:abcd1234", $user);
        $second = $repository->getServer("external:{$connection->id}:abcd1234", $user);

        $this->assertSame('External One', $first['attributes']['name']);
        $this->assertSame('External One', $second['attributes']['name']);
        Http::assertSentCount(1);
    }

    public function testGetResourcesUsesCacheForRepeatedRequests(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);

        Http::fake([
            'https://example.com/api/client/servers/abcd1234/resources' => Http::response([
                'attributes' => [
                    'current_state' => 'running',
                    'is_suspended' => false,
                    'resources' => [
                        'memory_bytes' => 1024,
                        'cpu_absolute' => 5.5,
                        'disk_bytes' => 2048,
                        'network' => [
                            'rx_bytes' => 100,
                            'tx_bytes' => 50,
                        ],
                        'uptime' => 1200,
                    ],
                ],
            ], 200),
        ]);

        $repository = $this->app->make(ExternalServerRepository::class);
        $first = $repository->getResources("external:{$connection->id}:abcd1234", $user);
        $second = $repository->getResources("external:{$connection->id}:abcd1234", $user);

        $this->assertSame(1024, $first['attributes']['resources']['memory_bytes']);
        $this->assertSame(1024, $second['attributes']['resources']['memory_bytes']);
        Http::assertSentCount(1);
    }

    public function testMutationInvalidatesServerCaches(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $detailRequests = 0;

        Http::fake(function (Request $request) use (&$detailRequests) {
            $url = $request->url();
            $method = strtoupper($request->method());

            if ($method === 'GET' && str_ends_with($url, '/api/client/servers/abcd1234')) {
                $detailRequests++;

                return Http::response([
                    'object' => 'server',
                    'attributes' => [
                        'server_owner' => true,
                        'identifier' => 'abcd1234',
                        'name' => $detailRequests === 1 ? 'External One' : 'External Renamed',
                        'node' => 'node-a',
                        'sftp_details' => [
                            'ip' => 'node-a.example.com',
                            'port' => 2022,
                        ],
                    ],
                    'meta' => [
                        'is_server_owner' => true,
                    ],
                ], 200);
            }

            if ($method === 'POST' && str_ends_with($url, '/api/client/servers/abcd1234/settings/rename')) {
                return Http::response([], 204);
            }

            return Http::response(['object' => 'list', 'data' => []], 200);
        });

        $repository = $this->app->make(ExternalServerRepository::class);
        $first = $repository->getServer("external:{$connection->id}:abcd1234", $user);
        $repository->proxyNoContent(
            "external:{$connection->id}:abcd1234",
            $user,
            'POST',
            'servers/abcd1234/settings/rename',
            ['json' => ['name' => 'External Renamed']]
        );
        $second = $repository->getServer("external:{$connection->id}:abcd1234", $user);

        $this->assertSame('External One', $first['attributes']['name']);
        $this->assertSame('External Renamed', $second['attributes']['name']);
        Http::assertSentCount(3);
    }

    protected function createConnection(User $user): ExternalPanelConnection
    {
        return ExternalPanelConnection::query()->create([
            'user_id' => $user->id,
            'name' => 'Hosting A',
            'panel_url' => 'https://example.com',
            'api_key_encrypted' => str_repeat('a', 48),
            'default_connection' => true,
            'last_verified_at' => now(),
        ]);
    }
}
