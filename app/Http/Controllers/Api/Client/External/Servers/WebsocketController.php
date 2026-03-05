<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Services\External\ExternalServerReference;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Services\External\ExternalWebsocketProxyTicketService;

class WebsocketController extends ExternalServerApiController
{
    public function __construct(
        ExternalServerRepository $repository,
        private ExternalWebsocketProxyTicketService $ticketService
    ) {
        parent::__construct($repository);
    }

    public function __invoke(ClientApiRequest $request, string $externalServer): JsonResponse
    {
        $payload = $this->repository->getWebsocket($this->externalIdentifier($externalServer), $request->user());
        $attributes = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : ($payload['attributes'] ?? []);
        $originOverride = null;
        $parts = null;

        try {
            $parts = ExternalServerReference::parseRouteParameter($externalServer);
        } catch (\Throwable $exception) {
            try {
                $parts = ExternalServerReference::parseCompositeIdentifier($externalServer);
            } catch (\Throwable $nestedException) {
                $parts = null;
            }
        }

        try {
            if (is_array($parts)) {
                $connection = $request->user()
                    ->externalPanelConnections()
                    ->where('id', $parts['connection_id'])
                    ->first(['websocket_origin', 'panel_url']);

                $origin = null;
                if (!is_null($connection)) {
                    $origin = is_string($connection->websocket_origin) && trim($connection->websocket_origin) !== ''
                        ? $connection->websocket_origin
                        : $connection->panel_url;
                }

                $originOverride = is_string($origin) && trim($origin) !== '' ? trim($origin) : null;
            }
        } catch (\Throwable $exception) {
            Log::debug('Failed to resolve external websocket origin override, falling back to default.', [
                'external_server' => $externalServer,
                'user_id' => $request->user()->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        if ($this->ticketService->isEnabled()) {
            try {
                if (is_string($attributes['socket'] ?? null) && $attributes['socket'] !== '') {
                    $attributes['socket'] = $this->ticketService->buildProxySocketUrl(
                        $attributes['socket'],
                        (int) $request->user()->id,
                        $externalServer,
                        $originOverride
                    );
                    $attributes['proxy'] = true;
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to create external websocket proxy ticket.', [
                    'connection' => $externalServer,
                    'user_id' => $request->user()->id,
                    'exception' => $exception->getMessage(),
                    'origin_override' => $originOverride,
                ]);
                $attributes['proxy'] = false;
            }
        } else {
            $attributes['proxy'] = false;
            Log::warning('External websocket proxy is disabled or not fully configured.', [
                'connection' => $externalServer,
                'user_id' => $request->user()->id,
            ]);
        }

        return new JsonResponse(['data' => $attributes]);
    }
}
