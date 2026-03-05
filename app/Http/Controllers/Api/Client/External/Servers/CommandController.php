<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class CommandController extends ExternalServerApiController
{
    public function index(ClientApiRequest $request, string $externalServer): JsonResponse
    {
        $this->repository->sendCommand(
            $this->externalIdentifier($externalServer),
            $request->user(),
            $request->validate(['command' => 'required|string'])
        );

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
