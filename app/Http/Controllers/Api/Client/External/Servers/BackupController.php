<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class BackupController extends ExternalServerApiController
{
    public function index(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'backups'), [
            'query' => $request->query(),
        ]);
    }

    public function store(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'backups'), [
            'json' => $request->all(),
        ]);
    }

    public function view(Request $request, string $externalServer, string $backup): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, "backups/$backup"));
    }

    public function download(Request $request, string $externalServer, string $backup): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, "backups/$backup/download"));
    }

    public function toggleLock(Request $request, string $externalServer, string $backup): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "backups/$backup/lock"));
    }

    public function restore(Request $request, string $externalServer, string $backup)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "backups/$backup/restore"), [
            'json' => $request->all(),
        ]);
    }

    public function delete(Request $request, string $externalServer, string $backup)
    {
        return $this->proxyNoContent($request, $externalServer, 'DELETE', $this->serverEndpoint($externalServer, "backups/$backup"));
    }
}
