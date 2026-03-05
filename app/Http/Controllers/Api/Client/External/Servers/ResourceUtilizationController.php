<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class ResourceUtilizationController extends ExternalServerApiController
{
    public function __invoke(ClientApiRequest $request, string $externalServer): array
    {
        return $this->repository->getResources($this->externalIdentifier($externalServer), $request->user());
    }
}
