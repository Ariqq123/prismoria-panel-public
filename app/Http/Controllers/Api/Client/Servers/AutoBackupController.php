<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\Response;
use Pterodactyl\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Services\AutoBackups\AutoBackupManagerService;

class AutoBackupController extends ClientApiController
{
    public function __construct(private AutoBackupManagerService $manager)
    {
        parent::__construct();
    }

    public function index(Request $request, Server $server): array
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_READ, $server)) {
            throw new AuthorizationException();
        }

        $profiles = $this->manager->listProfilesForServer($request->user(), $server->uuid);

        return [
            'object' => 'list',
            'data' => array_map(fn ($profile) => $this->manager->toApiResource($profile), $profiles),
            'meta' => [
                'defaults' => $this->manager->clientDefaults(),
            ],
        ];
    }

    public function store(Request $request, Server $server): array
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_CREATE, $server)) {
            throw new AuthorizationException();
        }

        $profile = $this->manager->createProfile($request->user(), $server->uuid, $request->all());

        return $this->manager->toApiResource($profile);
    }

    public function update(Request $request, Server $server, int $autoBackup): array
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_CREATE, $server)) {
            throw new AuthorizationException();
        }

        $profile = $this->manager->updateProfile($request->user(), $server->uuid, $autoBackup, $request->all());

        return $this->manager->toApiResource($profile);
    }

    public function run(Request $request, Server $server, int $autoBackup): array
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_CREATE, $server)) {
            throw new AuthorizationException();
        }

        $profile = $this->manager->triggerNow($request->user(), $server->uuid, $autoBackup);

        return $this->manager->toApiResource($profile);
    }

    public function delete(Request $request, Server $server, int $autoBackup): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_DELETE, $server)) {
            throw new AuthorizationException();
        }

        $this->manager->deleteProfile($request->user(), $server->uuid, $autoBackup);

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
