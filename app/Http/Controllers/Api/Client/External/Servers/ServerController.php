<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class ServerController extends ExternalServerApiController
{
    public function index(ClientApiRequest $request, string $externalServer): array
    {
        return $this->repository->getServer($this->externalIdentifier($externalServer), $request->user());
    }
}
