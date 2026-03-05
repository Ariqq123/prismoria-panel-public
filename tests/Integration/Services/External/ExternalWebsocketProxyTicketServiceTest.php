<?php

namespace Pterodactyl\Tests\Integration\Services\External;

use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\External\ExternalWebsocketProxyTicketService;

class ExternalWebsocketProxyTicketServiceTest extends IntegrationTestCase
{
    public function testBuildProxySocketUrlGeneratesTicketWithExpectedPayload(): void
    {
        config()->set('pterodactyl.external_websocket_proxy.enabled', true);
        config()->set('pterodactyl.external_websocket_proxy.url', 'wss://panel.example.com/ws/external');
        config()->set('pterodactyl.external_websocket_proxy.secret', 'proxy-secret');
        config()->set('pterodactyl.external_websocket_proxy.origin', 'https://panel.example.com');
        config()->set('pterodactyl.external_websocket_proxy.ticket_ttl', 120);

        /** @var ExternalWebsocketProxyTicketService $service */
        $service = $this->app->make(ExternalWebsocketProxyTicketService::class);

        $this->assertTrue($service->isEnabled());

        $socket = $service->buildProxySocketUrl(
            'wss://node.example.com/api/servers/abcd1234/ws',
            14,
            '1:abcd1234'
        );

        $this->assertStringStartsWith('wss://panel.example.com/ws/external?ticket=', $socket);

        $query = parse_url($socket, PHP_URL_QUERY);
        parse_str((string) $query, $params);
        $ticket = $params['ticket'] ?? null;

        $this->assertIsString($ticket);

        $payload = $service->decodeTicketPayload($ticket);
        $this->assertSame(1, $payload['v']);
        $this->assertSame(14, $payload['uid']);
        $this->assertSame('1:abcd1234', $payload['srv']);
        $this->assertSame('wss://node.example.com/api/servers/abcd1234/ws', $payload['upstream']);
        $this->assertSame('https://panel.example.com', $payload['origin']);
        $this->assertSame(['https://panel.example.com'], $payload['origins']);
        $this->assertSame(120, $payload['exp'] - $payload['iat']);
    }

    public function testEnabledFallsBackToAppKeyWhenSecretIsNotSet(): void
    {
        config()->set('pterodactyl.external_websocket_proxy.enabled', true);
        config()->set('pterodactyl.external_websocket_proxy.url', 'ws://127.0.0.1:8090/ws/external');
        config()->set('pterodactyl.external_websocket_proxy.secret', '');
        config()->set('app.key', 'base64:' . base64_encode('panel-app-key'));

        /** @var ExternalWebsocketProxyTicketService $service */
        $service = $this->app->make(ExternalWebsocketProxyTicketService::class);

        $this->assertTrue($service->isEnabled());
    }

    public function testBuildProxySocketUrlUsesOriginOverrideWhenProvided(): void
    {
        config()->set('pterodactyl.external_websocket_proxy.enabled', true);
        config()->set('pterodactyl.external_websocket_proxy.url', 'wss://panel.example.com/ws/external');
        config()->set('pterodactyl.external_websocket_proxy.secret', 'proxy-secret');
        config()->set('pterodactyl.external_websocket_proxy.origin', 'https://panel.example.com');
        config()->set('pterodactyl.external_websocket_proxy.ticket_ttl', 120);

        /** @var ExternalWebsocketProxyTicketService $service */
        $service = $this->app->make(ExternalWebsocketProxyTicketService::class);

        $socket = $service->buildProxySocketUrl(
            'wss://node.example.com/api/servers/abcd1234/ws',
            14,
            '1:abcd1234',
            'https://base-panel.domain/path/ignored'
        );

        $query = parse_url($socket, PHP_URL_QUERY);
        parse_str((string) $query, $params);
        $ticket = $params['ticket'] ?? null;

        $this->assertIsString($ticket);

        $payload = $service->decodeTicketPayload($ticket);
        $this->assertSame('https://base-panel.domain', $payload['origin']);
        $this->assertSame(['https://base-panel.domain', 'https://panel.example.com'], $payload['origins']);
    }

    public function testBuildProxySocketUrlIncludesAllConfiguredOriginCandidates(): void
    {
        config()->set('pterodactyl.external_websocket_proxy.enabled', true);
        config()->set('pterodactyl.external_websocket_proxy.url', 'wss://panel.example.com/ws/external');
        config()->set('pterodactyl.external_websocket_proxy.secret', 'proxy-secret');
        config()->set('pterodactyl.external_websocket_proxy.origin', 'https://panel.example.com, https://panel-alt.example.com');
        config()->set('pterodactyl.external_websocket_proxy.ticket_ttl', 120);

        /** @var ExternalWebsocketProxyTicketService $service */
        $service = $this->app->make(ExternalWebsocketProxyTicketService::class);

        $socket = $service->buildProxySocketUrl(
            'wss://node.example.com/api/servers/abcd1234/ws',
            14,
            '1:abcd1234',
            'https://base-panel.domain'
        );

        $query = parse_url($socket, PHP_URL_QUERY);
        parse_str((string) $query, $params);
        $ticket = $params['ticket'] ?? null;
        $this->assertIsString($ticket);

        $payload = $service->decodeTicketPayload($ticket);
        $this->assertSame(
            ['https://base-panel.domain', 'https://panel.example.com', 'https://panel-alt.example.com'],
            $payload['origins']
        );
    }
}
