<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class StartupController extends ExternalServerApiController
{
    public function index(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'startup'));
    }

    public function update(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'PUT', $this->serverEndpoint($externalServer, 'startup/variable'), [
            'json' => $request->all(),
        ]);
    }
}
