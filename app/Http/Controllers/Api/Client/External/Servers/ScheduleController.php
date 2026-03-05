<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class ScheduleController extends ExternalServerApiController
{
    public function index(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, 'schedules'), [
            'query' => $request->query(),
        ]);
    }

    public function store(Request $request, string $externalServer): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, 'schedules'), [
            'json' => $request->all(),
        ]);
    }

    public function view(Request $request, string $externalServer, string $schedule): array
    {
        return $this->proxyJson($request, $externalServer, 'GET', $this->serverEndpoint($externalServer, "schedules/$schedule"));
    }

    public function update(Request $request, string $externalServer, string $schedule): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "schedules/$schedule"), [
            'json' => $request->all(),
        ]);
    }

    public function execute(Request $request, string $externalServer, string $schedule)
    {
        return $this->proxyNoContent($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "schedules/$schedule/execute"));
    }

    public function delete(Request $request, string $externalServer, string $schedule)
    {
        return $this->proxyNoContent($request, $externalServer, 'DELETE', $this->serverEndpoint($externalServer, "schedules/$schedule"));
    }
}
