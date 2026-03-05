<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\Region\RegionLookupService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\GetServerRequest;

class RegionController extends ClientApiController
{
    public function __construct(private RegionLookupService $lookupService)
    {
        parent::__construct();
    }

    public function __invoke(GetServerRequest $request, Server $server): array
    {
        $host = $this->extractServerHost($server);
        if (is_null($host)) {
            return $this->lookupService->dnsErrorPayload();
        }

        return $this->lookupService->lookupFromHost(
            $host,
            (string) config('region-api.ip', 'ipwho.is'),
            (string) config('region-api.dns', 'DNS-Cloudflare')
        );
    }

    private function extractServerHost(Server $server): ?string
    {
        $allocation = $server->allocation ?? $server->allocations()->first();
        if (is_null($allocation)) {
            return null;
        }

        $alias = trim((string) ($allocation->ip_alias ?? ''));
        if ($alias !== '') {
            return $alias;
        }

        $ip = trim((string) ($allocation->ip ?? ''));

        return $ip !== '' ? $ip : null;
    }
}
