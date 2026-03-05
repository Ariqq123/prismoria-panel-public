<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class SettingsController extends ExternalServerApiController
{
    public function rename(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'settings/rename'), [
            'json' => $request->all(),
        ]);
    }

    public function reinstall(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'settings/reinstall'));
    }

    public function dockerImage(Request $request, string $externalServer)
    {
        return $this->proxyNoContent($request, $externalServer, 'PUT', $this->serverEndpoint($externalServer, 'settings/docker-image'), [
            'json' => $request->all(),
        ]);
    }
}
