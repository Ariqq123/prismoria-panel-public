<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\External\ExternalServerReference;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;

abstract class ExternalServerApiController extends ClientApiController
{
    public function __construct(protected ExternalServerRepository $repository)
    {
        parent::__construct();
    }

    protected function externalIdentifier(string $routeParameter): string
    {
        return "external:$routeParameter";
    }

    protected function serverEndpoint(string $routeParameter, string $suffix): string
    {
        $parts = ExternalServerReference::parseRouteParameter($routeParameter);

        return sprintf('servers/%s/%s', $parts['server_identifier'], ltrim($suffix, '/'));
    }

    protected function proxyJson(Request $request, string $externalServer, string $method, string|array $endpoint, array $options = []): array
    {
        return $this->repository->proxyJson($this->externalIdentifier($externalServer), $request->user(), $method, $endpoint, $options);
    }

    protected function proxyNoContent(Request $request, string $externalServer, string $method, string|array $endpoint, array $options = []): JsonResponse
    {
        $this->repository->proxyNoContent($this->externalIdentifier($externalServer), $request->user(), $method, $endpoint, $options);

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
