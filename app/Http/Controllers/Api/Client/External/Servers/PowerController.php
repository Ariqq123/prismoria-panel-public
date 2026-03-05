<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PowerController extends ExternalServerApiController
{
    public function index(ClientApiRequest $request, string $externalServer): JsonResponse
    {
        $this->repository->sendPowerAction(
            $this->externalIdentifier($externalServer),
            $request->user(),
            $request->validate(['signal' => 'required|string|in:start,stop,restart,kill'])
        );

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
