<?php

namespace Pterodactyl\Http\Controllers\Api\Client\External\Servers;

use Illuminate\Support\Arr;
use Pterodactyl\Services\Region\RegionLookupService;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class RegionController extends ExternalServerApiController
{
    public function __construct(
        ExternalServerRepository $repository,
        private RegionLookupService $lookupService
    ) {
        parent::__construct($repository);
    }

    public function __invoke(ClientApiRequest $request, string $externalServer): array
    {
        // Validate server ownership/access before performing lookup.
        $server = $this->repository->getServer($this->externalIdentifier($externalServer), $request->user());

        $host = $this->extractExternalHost($server);
        if (is_null($host)) {
            return $this->lookupService->dnsErrorPayload();
        }

        return $this->lookupService->lookupFromHost(
            $host,
            (string) config('region-api.ip', 'ipwho.is'),
            (string) config('region-api.dns', 'DNS-Cloudflare')
        );
    }

    private function extractExternalHost(array $payload): ?string
    {
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
                    return $host;
                }
            }

            foreach ($allocations as $allocation) {
                if (!is_array($allocation)) {
                    continue;
                }

                $host = $this->hostFromAllocation($allocation);
                if (!is_null($host)) {
                    return $host;
                }
            }
        }

        $sftpIp = trim((string) Arr::get($payload, 'attributes.sftp_details.ip', ''));

        return $sftpIp !== '' ? $sftpIp : null;
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
