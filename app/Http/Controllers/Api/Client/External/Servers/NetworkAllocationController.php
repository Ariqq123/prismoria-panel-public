<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class NetworkAllocationController extends ExternalServerApiController
{
    public function index(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'network/allocations'));
    }

    public function store(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'network/allocations'), [
            'json' => $request->all(),
        ]);
    }

    public function update(Request $request, string $externalServer, string $allocation): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "network/allocations/$allocation"), [
            'json' => $request->all(),
        ]);
    }

    public function setPrimary(Request $request, string $externalServer, string $allocation): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "network/allocations/$allocation/primary"));
    }

    public function delete(Request $request, string $externalServer, string $allocation)
    {
        return $this->proxyNoContent($request, $externalServer, 'DELETE', $this->serverEndpoint($externalServer, "network/allocations/$allocation"));
    }
}
