<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class SubuserController extends ExternalServerApiController
{
    public function index(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'users'));
    }

    public function view(Request $request, string $externalServer, string $externalUser): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, "users/$externalUser"));
    }

    public function store(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'users'), [
            'json' => $request->all(),
        ]);
    }

    public function update(Request $request, string $externalServer, string $externalUser): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "users/$externalUser"), [
            'json' => $request->all(),
        ]);
    }

    public function delete(Request $request, string $externalServer, string $externalUser)
    {
        return $this->proxyNoContent($request, $externalServer, 'DELETE', $this->serverEndpoint($externalServer, "users/$externalUser"));
    }
}
