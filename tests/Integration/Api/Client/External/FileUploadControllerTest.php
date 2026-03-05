<?php

namespace Pterodactyl\Tests\Integration\Api\Client\External;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\User;
use Pterodactyl\Models\ExternalPanelConnection;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Services\External\ExternalFileUploadProxyTicketService;

class FileUploadControllerTest extends ClientApiIntegrationTestCase
{
    public function testUploadUrlIsRewrittenToPanelProxy(): void
    {
        $user = User::factory()->create();
        $connection = ExternalPanelConnection::query()->create([
            'user_id' => $user->id,
            'name' => 'Hosting A',
            'panel_url' => 'https://external.example.com',
            'api_key_encrypted' => str_repeat('a', 48),
            'default_connection' => true,
            'last_verified_at' => now(),
        ]);

        $repository = \Mockery::mock(ExternalServerRepository::class);
        $repository->shouldReceive('proxyJson')
            ->once()
            ->with(
                "external:{$connection->id}:abcd1234",
                $user,
                'GET',
                'servers/abcd1234/files/upload',
                []
            )
            ->andReturn([
                'object' => 'signed_url',
                'attributes' => [
                    'url' => 'https://node.external.example.com/upload/file?token=upload-token',
                ],
            ]);

        $this->app->instance(ExternalServerRepository::class, $repository);

        $response = $this->actingAs($user)
            ->getJson("/api/client/servers/external:{$connection->id}:abcd1234/files/upload");

        $response->assertOk()->assertJsonPath('object', 'signed_url');

        $url = $response->json('attributes.url');
        $this->assertIsString($url);
        $this->assertStringContainsString(
            "/api/client/servers/external:{$connection->id}:abcd1234/files/upload/proxy?ticket=",
            $url
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $ticket = $params['ticket'] ?? null;
        $this->assertIsString($ticket);

        /** @var ExternalFileUploadProxyTicketService $service */
        $service = $this->app->make(ExternalFileUploadProxyTicketService::class);
        $payload = $service->decodeTicketPayload($ticket);

        $this->assertSame($user->id, $payload['uid']);
        $this->assertSame("{$connection->id}:abcd1234", $payload['srv']);
        $this->assertSame('https://node.external.example.com/upload/file?token=upload-token', $payload['upstream']);
    }

    public function testUploadProxyForwardsMultipartRequestToUpstream(): void
    {
        $user = User::factory()->create();
        $externalServer = '1:abcd1234';

        /** @var ExternalFileUploadProxyTicketService $service */
        $service = $this->app->make(ExternalFileUploadProxyTicketService::class);
        $ticket = $service->buildTicket(
            'https://node.external.example.com/upload/file?token=upload-token',
            (int) $user->id,
            $externalServer
        );

        Http::fake([
            'https://node.external.example.com/*' => Http::response('', 204),
        ]);

        $file = UploadedFile::fake()->create('test.txt', 4, 'text/plain');
        $response = $this->actingAs($user)->post(
            "/api/client/servers/external:$externalServer/files/upload/proxy?ticket=" . urlencode($ticket) . '&directory=' . urlencode('/plugins'),
            ['files' => $file]
        );

        $response->assertStatus(204);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://node.external.example.com/upload/file?')
                && str_contains($request->url(), 'token=upload-token')
                && str_contains($request->url(), 'directory=%2Fplugins');
        });
    }
}

