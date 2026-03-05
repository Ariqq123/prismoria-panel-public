<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\PlayerManager\PlayerManagerService;
use Pterodactyl\Services\External\ExternalServerRepository;

class PlayerManagerController extends ExternalServerApiController
{
    public function __construct(
        ExternalServerRepository $repository,
        private PlayerManagerService $playerManagerService
    ) {
        parent::__construct($repository);
    }

    public function index(Request $request, string $externalServer): array
    {
        $server = $this->fetchServerPayload($request, $externalServer);
        $ops = $this->readJsonFile($request, $externalServer, '/ops.json');
        $whitelist = $this->readJsonFile($request, $externalServer, '/whitelist.json');
        $bans = $this->readJsonFile($request, $externalServer, '/banned-players.json');
        $banIps = $this->readJsonFile($request, $externalServer, '/banned-ips.json');

        [$host, $port] = $this->extractHostAndPort($server);

        return [
            'success' => true,
            'data' => [
                'players' => $this->playerManagerService->getPlayersData($host, $port, $ops, $whitelist),
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
    public function runCommand(Request $request, string $externalServer): array
    {
        $payload = $request->validate([
            'command' => 'required|string|min:1',
        ]);

        try {
            $this->repository->sendCommand(
                $this->externalIdentifier($externalServer),
                $request->user(),
                ['command' => trim((string) $payload['command'])]
            );
        } catch (\Throwable) {
            throw new DisplayException(
                'Failed to perform the action. The external server may be offline. Please try again.'
            );
        }

        return [
            'success' => true,
            'data' => [],
        ];
    }

    private function readJsonFile(Request $request, string $externalServer, string $file): array
    {
        try {
            $content = $this->repository->proxyText(
                $this->externalIdentifier($externalServer),
                $request->user(),
                'GET',
                [
                    $this->serverEndpoint($externalServer, 'files/contents'),
                    $this->serverEndpoint($externalServer, 'files/content'),
                ],
                $request,
                [
                    'query' => ['file' => $file],
                    '_timeout' => 3,
                    '_connect_timeout' => 2,
                    '_retry_delays' => [],
                ]
            );
        } catch (\Throwable) {
            return [];
        }

        return $this->playerManagerService->decodeJsonFile($content);
    }

    private function extractHostAndPort(array $payload): array
    {
        $payload = $this->normalizeServerPayload($payload);

        $allocations = Arr::get($payload, 'attributes.relationships.allocations.data', []);
        if (is_array($allocations)) {
            foreach ($allocations as $allocation) {
                if (!is_array($allocation)) {
                    continue;
                }

                $isDefault = filter_var(
                    Arr::get($allocation, 'attributes.is_default', false),
                    FILTER_VALIDATE_BOOLEAN
                );

                if (!$isDefault) {
                    continue;
                }

                $host = $this->hostFromAllocation($allocation);
                if (!is_null($host)) {
                    return [$host, (int) Arr::get($allocation, 'attributes.port', 25565)];
                }
            }

            foreach ($allocations as $allocation) {
                if (!is_array($allocation)) {
                    continue;
                }

                $host = $this->hostFromAllocation($allocation);
                if (!is_null($host)) {
                    return [$host, (int) Arr::get($allocation, 'attributes.port', 25565)];
                }
            }
        }

        $sftpIp = trim((string) Arr::get($payload, 'attributes.sftp_details.ip', ''));
        $sftpPort = (int) Arr::get($payload, 'attributes.sftp_details.port', 25565);

        return [$sftpIp !== '' ? $sftpIp : null, $sftpPort];
    }

    private function fetchServerPayload(Request $request, string $externalServer): array
    {
        $baseEndpoint = rtrim($this->serverEndpoint($externalServer, ''), '/');

        try {
            return $this->proxyJson(
                $request,
                $externalServer,
                'GET',
                [$baseEndpoint, $baseEndpoint . '/'],
                [
                    'query' => ['include' => 'allocations'],
                    '_timeout' => 5,
                    '_connect_timeout' => 3,
                    '_retry_delays' => [],
                ]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizeServerPayload(array $payload): array
    {
        if (is_array(Arr::get($payload, 'attributes'))) {
            return $payload;
        }

        $data = Arr::get($payload, 'data');
        if (is_array($data)) {
            if (is_array(Arr::get($data, 'attributes'))) {
                return $data;
            }

            // Some external panels return attributes directly in data.
            return [
                'attributes' => $data,
            ];
        }

        return [
            'attributes' => $payload,
        ];
    }

    private function hostFromAllocation(array $allocation): ?string
    {
        $alias = trim((string) Arr::get($allocation, 'attributes.ip_alias', Arr::get($allocation, 'attributes.alias', '')));
        if ($alias !== '') {
            return $alias;
        }

        $ip = trim((string) Arr::get($allocation, 'attributes.ip', ''));

        return $ip !== '' ? $ip : null;
    }

}
