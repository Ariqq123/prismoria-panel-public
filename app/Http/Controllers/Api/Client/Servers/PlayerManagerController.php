<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\PlayerManager\PlayerManagerService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;
use Pterodactyl\Http\Requests\Api\Client\Servers\SendCommandRequest;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Requests\Api\Client\Servers\PlayerManager\GetPlayerManagerRequest;

class PlayerManagerController extends ClientApiController
{
    public function __construct(
        private DaemonFileRepository $fileRepository,
        private DaemonCommandRepository $commandRepository,
        private PlayerManagerService $playerManagerService
    ) {
        parent::__construct();
    }

    public function index(GetPlayerManagerRequest $request, Server $server): array
    {
        $ops = $this->readJsonFile($server, '/ops.json');
        $whitelist = $this->readJsonFile($server, '/whitelist.json');
        $bans = $this->readJsonFile($server, '/banned-players.json');
        $banIps = $this->readJsonFile($server, '/banned-ips.json');

        return [
            'success' => true,
            'data' => [
                'players' => $this->playerManagerService->getPlayersData(
                    $this->serverHost($server),
                    $this->serverPort($server),
                    $ops,
                    $whitelist
                ),
                'ops' => $ops,
                'whitelist' => $whitelist,
                'bans' => $bans,
                'banIps' => $banIps,
            ],
        ];
    }

    /**
     * @throws DisplayException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function runCommand(SendCommandRequest $request, Server $server): array
    {
        try {
            $this->commandRepository
                ->setServer($server)
                ->send(trim((string) $request->input('command')));
        } catch (DaemonConnectionException) {
            throw new DisplayException(
                'Failed to perform the action. The server may be offline. Please try again.'
            );
        }

        return [
            'success' => true,
            'data' => [],
        ];
    }

    private function readJsonFile(Server $server, string $file): array
    {
        try {
            $content = $this->fileRepository->setServer($server)->getContent($file);
        } catch (DaemonConnectionException | FileSizeTooLargeException) {
            return [];
        }

        return $this->playerManagerService->decodeJsonFile($content);
    }

    private function serverHost(Server $server): ?string
    {
        $allocation = $server->allocation;
        if ($allocation !== null) {
            $alias = trim((string) ($allocation->getAttribute('ip_alias') ?? $allocation->getAttribute('alias') ?? ''));
            if ($alias !== '') {
                return $alias;
            }

            $ip = trim((string) $allocation->getAttribute('ip'));
            if ($ip !== '') {
                return $ip;
            }
        }

        $fallback = trim((string) $server->node->fqdn);

        return $fallback !== '' ? $fallback : null;
    }

    private function serverPort(Server $server): int
    {
        return max(1, (int) ($server->allocation?->port ?? 25565));
    }
}
