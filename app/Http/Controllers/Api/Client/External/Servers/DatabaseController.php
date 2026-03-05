<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class DatabaseController extends ExternalServerApiController
{
    public function index(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'databases'), [
            'query' => $request->query(),
        ]);
    }

    public function store(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'databases'), [
            'json' => $request->all(),
        ]);
    }

    public function rotatePassword(Request $request, string $externalServer, string $database): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "databases/$database/rotate-password"));
    }

    public function delete(Request $request, string $externalServer, string $database)
    {
        return $this->proxyNoContent($request, $externalServer, 'DELETE', $this->serverEndpoint($externalServer, "databases/$database"));
    }
}
