<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\AutoBackups\AutoBackupManagerService;

class AutoBackupController extends ExternalServerApiController
{
    public function __construct(
        \Pterodactyl\Services\External\ExternalServerRepository $repository,
        private AutoBackupManagerService $manager
    ) {
        parent::__construct($repository);
    }

    public function index(Request $request, string $externalServer): array
    {
        $identifier = $this->externalIdentifier($externalServer);
        $profiles = $this->manager->listProfilesForServer($request->user(), $identifier);

        return [
            'object' => 'list',
            'data' => array_map(fn ($profile) => $this->manager->toApiResource($profile), $profiles),
            'meta' => [
                'defaults' => $this->manager->clientDefaults(),
            ],
        ];
    }

    public function store(Request $request, string $externalServer): array
    {
        $identifier = $this->externalIdentifier($externalServer);
        $profile = $this->manager->createProfile($request->user(), $identifier, $request->all());

        return $this->manager->toApiResource($profile);
    }

    public function update(Request $request, string $externalServer, int $autoBackup): array
    {
        $identifier = $this->externalIdentifier($externalServer);
        $profile = $this->manager->updateProfile($request->user(), $identifier, $autoBackup, $request->all());

        return $this->manager->toApiResource($profile);
    }

    public function run(Request $request, string $externalServer, int $autoBackup): array
    {
        $identifier = $this->externalIdentifier($externalServer);
        $profile = $this->manager->triggerNow($request->user(), $identifier, $autoBackup);

        return $this->manager->toApiResource($profile);
    }

    public function delete(Request $request, string $externalServer, int $autoBackup): JsonResponse
    {
        $identifier = $this->externalIdentifier($externalServer);
        $this->manager->deleteProfile($request->user(), $identifier, $autoBackup);

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
