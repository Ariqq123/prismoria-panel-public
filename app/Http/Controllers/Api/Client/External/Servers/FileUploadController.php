<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Services\External\ExternalFileUploadProxyTicketService;

class FileUploadController extends ExternalServerApiController
{
    public function __construct(
        ExternalServerRepository $repository,
        private ExternalFileUploadProxyTicketService $ticketService
    ) {
        parent::__construct($repository);
    }

    public function __invoke(Request $request, string $externalServer): array
    {
        $payload = $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'files/upload'));
        $urlPath = $this->uploadUrlPath($payload);

        if (is_null($urlPath)) {
            return $payload;
        }

        $upstreamUrl = trim((string) Arr::get($payload, $urlPath, ''));
        if ($upstreamUrl === '') {
            return $payload;
        }

        try {
            $ticket = $this->ticketService->buildTicket($upstreamUrl, (int) $request->user()->id, $externalServer);
            $proxyUrl = sprintf(
                '%s?ticket=%s',
                url("/api/client/servers/external:$externalServer/files/upload/proxy"),
                rawurlencode($ticket)
            );

            Arr::set($payload, $urlPath, $proxyUrl);
        } catch (\Throwable $exception) {
            Log::warning('Failed to rewrite external file upload URL to proxy endpoint.', [
                'server' => $externalServer,
                'user_id' => (int) $request->user()->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $payload;
    }

    protected function uploadUrlPath(array $payload): ?string
    {
        $paths = [
            'attributes.url',
            'data.attributes.url',
            'data.url',
            'url',
        ];

        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return $path;
            }
        }

        return null;
    }
}
