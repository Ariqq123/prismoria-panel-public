<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class ActivityLogController extends ExternalServerApiController
{
    public function __invoke(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'activity'), [
            'query' => $request->query(),
        ]);
    }
}
