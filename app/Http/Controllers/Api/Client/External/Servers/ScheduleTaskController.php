<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;

class ScheduleTaskController extends ExternalServerApiController
{
    public function store(Request $request, string $externalServer, string $schedule): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "schedules/$schedule/tasks"), [
            'json' => $request->all(),
        ]);
    }

    public function update(Request $request, string $externalServer, string $schedule, string $task): array
    {
        return $this->proxyJson($request, $externalServer, 'POST', $this->serverEndpoint($externalServer, "schedules/$schedule/tasks/$task"), [
            'json' => $request->all(),
        ]);
    }

    public function delete(Request $request, string $externalServer, string $schedule, string $task)
    {
        return $this->proxyNoContent($request, $externalServer, 'DELETE', $this->serverEndpoint($externalServer, "schedules/$schedule/tasks/$task"));
    }
}
