<?php

namespace Pterodactyl\Tests\Integration\Api\Client\External;

use Pterodactyl\Models\User;
use Pterodactyl\Models\ExternalPanelConnection;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Services\External\ExternalWebsocketProxyTicketService;

class WebsocketControllerTest extends ClientApiIntegrationTestCase
{
    public function testWebsocketResponseIsRewrittenToProxyWhenEnabled(): void
    {
        $user = User::factory()->create();
        $connection = ExternalPanelConnection::query()->create([
            'user_id' => $user->id,
            'name' => 'Hosting A',
            'panel_url' => 'https://external.example.com',
            'websocket_origin' => 'https://base-panel.domain',
            'api_key_encrypted' => str_repeat('a', 48),
            'default_connection' => true,
            'last_verified_at' => now(),
        ]);

        config()->set('pterodactyl.external_websocket_proxy.enabled', true);
        config()->set('pterodactyl.external_websocket_proxy.url', 'wss://panel.example.com/ws/external');
        config()->set('pterodactyl.external_websocket_proxy.secret', 'proxy-secret');
        config()->set('pterodactyl.external_websocket_proxy.origin', 'https://panel.example.com');
        config()->set('pterodactyl.external_websocket_proxy.ticket_ttl', 90);

        $repository = \Mockery::mock(ExternalServerRepository::class);
        $repository->shouldReceive('getWebsocket')
            ->once()
            ->with("external:{$connection->id}:abcd1234", $user)
            ->andReturn([
                'data' => [
                    'token' => 'ws-token',
                    'socket' => 'wss://node.external.example.com/api/servers/abcd1234/ws',
                ],
            ]);

        $this->app->instance(ExternalServerRepository::class, $repository);

        $response = $this->actingAs($user)->getJson("/api/client/servers/external:{$connection->id}:abcd1234/websocket");
        $response->assertOk()
            ->assertJsonPath('data.token', 'ws-token')
            ->assertJsonPath('data.proxy', true);

        $socket = $response->json('data.socket');
        $this->assertStringStartsWith('wss://panel.example.com/ws/external?ticket=', $socket);

        $query = parse_url($socket, PHP_URL_QUERY);
        parse_str((string) $query, $params);

        $ticket = $params['ticket'] ?? null;
        $this->assertIsString($ticket);

        /** @var ExternalWebsocketProxyTicketService $service */
        $service = $this->app->make(ExternalWebsocketProxyTicketService::class);
        $payload = $service->decodeTicketPayload($ticket);

        $this->assertSame($user->id, $payload['uid']);
        $this->assertSame("{$connection->id}:abcd1234", $payload['srv']);
        $this->assertSame('wss://node.external.example.com/api/servers/abcd1234/ws', $payload['upstream']);
        $this->assertSame('https://base-panel.domain', $payload['origin']);
        $this->assertSame(['https://base-panel.domain', 'https://panel.example.com'], $payload['origins']);
    }

    public function testWebsocketResponseUsesConnectionPanelUrlWhenOriginOverrideIsMissing(): void
    {
        $user = User::factory()->create();
        $connection = ExternalPanelConnection::query()->create([
            'user_id' => $user->id,
            'name' => 'Hosting B',
            'panel_url' => 'https://panel.na1.host',
            'websocket_origin' => null,
            'api_key_encrypted' => str_repeat('a', 48),
            'default_connection' => true,
            'last_verified_at' => now(),
        ]);

        config()->set('pterodactyl.external_websocket_proxy.enabled', true);
        config()->set('pterodactyl.external_websocket_proxy.url', 'wss://panel.example.com/ws/external');
        config()->set('pterodactyl.external_websocket_proxy.secret', 'proxy-secret');
        config()->set('pterodactyl.external_websocket_proxy.origin', 'https://panel.example.com');
        config()->set('pterodactyl.external_websocket_proxy.ticket_ttl', 90);

        $repository = \Mockery::mock(ExternalServerRepository::class);
        $repository->shouldReceive('getWebsocket')
            ->once()
            ->with("external:{$connection->id}:abcd1234", $user)
            ->andReturn([
                'data' => [
                    'token' => 'ws-token',
                    'socket' => 'wss://node.external.example.com/api/servers/abcd1234/ws',
                ],
            ]);

        $this->app->instance(ExternalServerRepository::class, $repository);

        $response = $this->actingAs($user)->getJson("/api/client/servers/external:{$connection->id}:abcd1234/websocket");
        $response->assertOk()
            ->assertJsonPath('data.token', 'ws-token')
            ->assertJsonPath('data.proxy', true);

        $socket = $response->json('data.socket');
        $query = parse_url($socket, PHP_URL_QUERY);
        parse_str((string) $query, $params);
        $ticket = $params['ticket'] ?? null;
        $this->assertIsString($ticket);

        /** @var ExternalWebsocketProxyTicketService $service */
        $service = $this->app->make(ExternalWebsocketProxyTicketService::class);
        $payload = $service->decodeTicketPayload($ticket);

        $this->assertSame('https://panel.na1.host', $payload['origin']);
        $this->assertSame(['https://panel.na1.host', 'https://panel.example.com'], $payload['origins']);
    }
}
