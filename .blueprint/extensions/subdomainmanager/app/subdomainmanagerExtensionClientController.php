<?php

namespace Pterodactyl\BlueprintFramework\Extensions\subdomainmanager;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Server;

class subdomainmanagerExtensionClientController extends ClientApiController
{
    private const CLOUDFLARE_API_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(private SettingsRepositoryInterface $settingsRepository)
    {
        parent::__construct();
    }

    public function index(SubdomainManagerClientRequest $request, Server $server): array
    {
        $allDomains = DB::table('subdomain_manager_domains')->get();
        $subdomains = DB::table('subdomain_manager_subdomains')
            ->where('server_id', '=', $server->id)
            ->where(function ($query) {
                $query->where('server_source', '=', 'local')
                    ->orWhereNull('server_source');
            })
            ->get();
        $allocation = DB::table('allocations')->select(['ip', 'ip_alias'])->where('id', '=', $server->allocation_id)->first();
        $domains = $this->domainsForEggWithCloudflareFallback($allDomains, (int) $server->egg_id);

        foreach ($subdomains as $key => $subdomain) {
            foreach ($domains as $domain) {
                if ((int) $subdomain->domain_id === (int) $domain['id']) {
                    $subdomains[$key]->domain = $domain['domain'];
                    break;
                }
            }
        }

        return [
            'success' => true,
            'data' => [
                'domains' => $domains,
                'subdomains' => $subdomains,
                'ipAlias' => $allocation ? $this->resolveAllocationTarget($allocation) : '',
            ],
        ];
    }

    public function min3Checking(SubdomainManagerClientRequest $request, Server $server): array
    {
        $this->validate($request, [
            'subdomain' => 'required|string|min:2|max:20|regex:/^[a-z0-9](?:[a-z0-9-]{0,18}[a-z0-9])?$/',
        ]);

        $subdomain = strtolower(trim((string) $request->query('subdomain', $request->input('subdomain', ''))));
        if ($subdomain === '') {
            throw new DisplayException('Subdomain is required.');
        }

        return [
            'success' => true,
            'data' => [
                'domains' => $this->upsertMin3DomainsForSubdomain($subdomain),
            ],
        ];
    }

    public function create(SubdomainManagerClientRequest $request, Server $server): array
    {
        $this->validate($request, [
            'subdomain' => 'required|min:2|max:32',
            'domainId' => 'required|integer',
            'srv_service' => 'nullable|string|min:1|max:63|regex:/^_?[a-zA-Z0-9-]+$/',
            'srv_protocol_type' => 'nullable|string|in:tcp,udp,tls',
            'srv_priority' => 'nullable|integer|min:0|max:65535',
            'srv_weight' => 'nullable|integer|min:0|max:65535',
            'srv_port' => 'nullable|integer|min:1|max:65535',
        ]);

        $subdomain = strtolower(trim((string) strip_tags((string) $request->input('subdomain'))));
        $domainId = (int) $request->input('domainId');
        $forceAdvancedSrv = filter_var($request->input('advanced_srv', false), FILTER_VALIDATE_BOOLEAN)
            || $request->filled('srv_service')
            || $request->filled('srv_protocol_type')
            || $request->filled('srv_priority')
            || $request->filled('srv_weight')
            || $request->filled('srv_port');

        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $subdomain)) {
            throw new DisplayException('Invalid subdomain format. Use letters, numbers, and hyphens only.');
        }

        $domain = DB::table('subdomain_manager_domains')->where('id', '=', $domainId)->first();
        if (!$domain) {
            throw new DisplayException('Domain not found.');
        }
        $provider = $this->resolveDomainProvider($domain);

        $domainsForEgg = $this->domainsForEggWithCloudflareFallback(
            DB::table('subdomain_manager_domains')->get(),
            (int) $server->egg_id
        );
        $allowedDomainIds = array_map(static fn (array $item): int => (int) $item['id'], $domainsForEgg);
        if (!in_array($domainId, $allowedDomainIds, true)) {
            throw new DisplayException('Selected domain is not enabled for this server type.');
        }

        $maxSubdomains = (int) $this->settingsRepository->get('settings::subdomain::max_subdomain', 1);
        $subdomainCount = DB::table('subdomain_manager_subdomains')
            ->where('server_id', '=', $server->id)
            ->where(function ($query) {
                $query->where('server_source', '=', 'local')
                    ->orWhereNull('server_source');
            })
            ->count();
        if ($subdomainCount >= $maxSubdomains) {
            throw new DisplayException(sprintf('You can create maximum %d subdomain(s).', $maxSubdomains));
        }

        $subdomainExists = DB::table('subdomain_manager_subdomains')
            ->where('domain_id', '=', $domainId)
            ->where('subdomain', '=', $subdomain)
            ->exists();
        if ($subdomainExists) {
            throw new DisplayException('This subdomain is already taken: ' . $subdomain);
        }

        $allocation = DB::table('allocations')->select(['ip', 'ip_alias', 'port'])->where('id', '=', $server->allocation_id)->first();
        if (!$allocation) {
            throw new DisplayException('No allocation is assigned to this server.');
        }

        $allocationTarget = $this->resolveAllocationTarget($allocation);
        $allocationIp = trim((string) ($allocation->ip ?? ''));
        $isIpTarget = filter_var($allocationTarget, FILTER_VALIDATE_IP) !== false;

        if ($provider === 'min3') {
            $fullDomain = sprintf('%s.%s', $subdomain, $domain->domain);
            $port = $this->normalizeSrvInteger($request->input('srv_port'), (int) $allocation->port, 1, 65535, 'Min3 port');
            $publicIp = $this->resolvePublicIpForMin3([$allocationIp, $allocationTarget]);

            $this->createMin3Domain($fullDomain, $publicIp, $port);

            DB::table('subdomain_manager_subdomains')->insert([
                'server_id' => $server->id,
                'server_source' => 'local',
                'server_identifier' => null,
                'domain_id' => $domainId,
                'subdomain' => $subdomain,
                'port' => $port,
                'record_type' => 'MIN3',
                'dns_record_name' => $fullDomain,
                'srv_service' => null,
                'srv_protocol_type' => null,
                'srv_priority' => null,
                'srv_weight' => null,
                'srv_port' => null,
            ]);

            return ['success' => true];
        }

        [$protocol, $type] = $this->resolveProtocolAndType($domain, (int) $server->egg_id);
        $zoneId = $this->getZoneId((string) $domain->domain);
        $recordType = 'CNAME';
        $dnsRecordName = '';
        $srvService = null;
        $srvProtocolType = null;
        $srvPriority = null;
        $srvWeight = null;
        $srvPort = null;

        if ($protocol === '' && !$forceAdvancedSrv) {
            if (!$isIpTarget && !$this->isValidDnsHostname($allocationTarget)) {
                throw new DisplayException(
                    'Allocation IP alias must be a valid hostname (for example node1.example.com) or a valid IP address.'
                );
            }

            $recordType = $isIpTarget ? 'A' : 'CNAME';
            $dnsRecordName = sprintf('%s.%s', $subdomain, $domain->domain);
            if (!empty($this->listDnsRecords($zoneId, $recordType, $dnsRecordName))) {
                throw new DisplayException('This subdomain is already taken: ' . $subdomain);
            }

            $payload = [
                'type' => $recordType,
                'name' => $subdomain,
                'content' => $allocationTarget,
                'proxied' => false,
                'ttl' => 120,
            ];
        } else {
            if (!$this->isValidDnsHostname($allocationTarget)) {
                throw new DisplayException(
                    'SRV records require an allocation alias hostname (for example node1.example.com).'
                );
            }

            $recordType = 'SRV';
            $srvService = $this->normalizeSrvService((string) $request->input('srv_service', ''), $protocol !== '' ? $protocol : '_minecraft');
            $srvProtocolType = $this->normalizeSrvProtocolType((string) $request->input('srv_protocol_type', ''), $type !== '' ? $type : 'tcp');
            $srvPriority = $this->normalizeSrvInteger($request->input('srv_priority'), 1, 0, 65535, 'SRV priority');
            $srvWeight = $this->normalizeSrvInteger($request->input('srv_weight'), 1, 0, 65535, 'SRV weight');
            $srvPort = $this->normalizeSrvInteger($request->input('srv_port'), (int) $allocation->port, 1, 65535, 'SRV port');

            $dnsRecordName = sprintf('%s._%s.%s.%s', $srvService, $srvProtocolType, $subdomain, $domain->domain);
            if (!empty($this->listDnsRecords($zoneId, 'SRV', $dnsRecordName))) {
                throw new DisplayException('This subdomain is already taken: ' . $subdomain);
            }

            $payload = [
                'type' => 'SRV',
                'name' => $dnsRecordName,
                'data' => [
                    'priority' => $srvPriority,
                    'weight' => $srvWeight,
                    'port' => $srvPort,
                    'target' => $allocationTarget,
                ],
                'ttl' => 120,
            ];
        }

        $this->createDnsRecord($zoneId, $payload);

        DB::table('subdomain_manager_subdomains')->insert([
            'server_id' => $server->id,
            'server_source' => 'local',
            'server_identifier' => null,
            'domain_id' => $domainId,
            'subdomain' => $subdomain,
            'port' => $recordType === 'SRV' ? $srvPort : (int) $allocation->port,
            'record_type' => $recordType,
            'dns_record_name' => $dnsRecordName,
            'srv_service' => $srvService,
            'srv_protocol_type' => $srvProtocolType,
            'srv_priority' => $srvPriority,
            'srv_weight' => $srvWeight,
            'srv_port' => $srvPort,
        ]);

        return ['success' => true];
    }

    public function delete(SubdomainManagerClientRequest $request, Server $server, $id): array
    {
        $id = (int) $id;

        $subdomain = DB::table('subdomain_manager_subdomains')
            ->where('id', '=', $id)
            ->where('server_id', '=', $server->id)
            ->where(function ($query) {
                $query->where('server_source', '=', 'local')
                    ->orWhereNull('server_source');
            })
            ->first();
        if (!$subdomain) {
            throw new DisplayException('Subdomain not found.');
        }

        $domain = DB::table('subdomain_manager_domains')->where('id', '=', $subdomain->domain_id)->first();
        if (!$domain) {
            throw new DisplayException('Domain not found.');
        }
        $provider = $this->resolveDomainProvider($domain);

        if ($provider === 'min3' || strtoupper((string) ($subdomain->record_type ?? '')) === 'MIN3') {
            $recordName = trim((string) ($subdomain->dns_record_name ?? ''));
            if ($recordName === '') {
                $recordName = sprintf('%s.%s', $subdomain->subdomain, $domain->domain);
            }

            $this->deleteMin3Domain($recordName);

            DB::table('subdomain_manager_subdomains')
                ->where('id', '=', $id)
                ->where('server_id', '=', $server->id)
                ->where(function ($query) {
                    $query->where('server_source', '=', 'local')
                        ->orWhereNull('server_source');
                })
                ->delete();

            return ['success' => true];
        }

        $zoneId = $this->getZoneId((string) $domain->domain);

        $recordType = strtoupper((string) ($subdomain->record_type ?? ''));
        if ($recordType === 'SRV') {
            $recordName = trim((string) ($subdomain->dns_record_name ?? ''));

            if ($recordName === '') {
                [$protocol, $type] = $this->resolveProtocolAndType($domain, (int) $server->egg_id);
                $service = $this->normalizeSrvService((string) ($subdomain->srv_service ?? ''), $protocol);
                $protoType = $this->normalizeSrvProtocolType((string) ($subdomain->srv_protocol_type ?? ''), $type);
                $recordName = sprintf('%s._%s.%s.%s', $service, $protoType, $subdomain->subdomain, $domain->domain);
            }

            $records = $this->listDnsRecords($zoneId, 'SRV', $recordName);
        } else {
            $recordType = in_array($recordType, ['A', 'CNAME'], true) ? $recordType : 'CNAME';
            $recordName = trim((string) ($subdomain->dns_record_name ?? ''));
            if ($recordName === '') {
                $recordName = sprintf('%s.%s', $subdomain->subdomain, $domain->domain);
            }

            $records = $this->listDnsRecords($zoneId, $recordType, $recordName);
        }

        if (count($records) < 1 || empty($records[0]['id'])) {
            throw new DisplayException('Failed to delete subdomain from Cloudflare.');
        }

        $this->deleteDnsRecord($zoneId, (string) $records[0]['id']);

        DB::table('subdomain_manager_subdomains')
            ->where('id', '=', $id)
            ->where('server_id', '=', $server->id)
            ->where(function ($query) {
                $query->where('server_source', '=', 'local')
                    ->orWhereNull('server_source');
            })
            ->delete();

        return ['success' => true];
    }

    private function domainSupportsEgg(object $domain, int $eggId): bool
    {
        $eggIds = array_filter(array_map('intval', explode(',', (string) $domain->egg_ids)));

        return in_array($eggId, $eggIds, true);
    }

    /**
     * @param iterable<object> $allDomains
     * @return array<int, array{id: int, domain: string, provider: string}>
     */
    private function domainsForEggWithCloudflareFallback(iterable $allDomains, int $eggId): array
    {
        $normalized = [];
        $matched = [];
        $cloudflare = [];
        $matchedCloudflare = [];

        foreach ($allDomains as $domain) {
            $item = [
                'id' => (int) $domain->id,
                'domain' => (string) $domain->domain,
                'provider' => $this->resolveDomainProvider($domain),
            ];

            $normalized[] = $item;
            if ($item['provider'] === 'cloudflare') {
                $cloudflare[] = $item;
            }

            if ($this->domainSupportsEgg($domain, $eggId)) {
                $matched[] = $item;
                if ($item['provider'] === 'cloudflare') {
                    $matchedCloudflare[] = $item;
                }
            }
        }

        if (count($matched) < 1) {
            return $normalized;
        }

        if (count($cloudflare) > 0 && count($matchedCloudflare) < 1) {
            $merged = [];
            foreach (array_merge($cloudflare, $matched) as $item) {
                $merged[(string) $item['id']] = $item;
            }

            return array_values($merged);
        }

        return $matched;
    }

    /**
     * @return array<int, array{id: int, domain: string, provider: string}>
     */
    private function upsertMin3DomainsForSubdomain(string $subdomain): array
    {
        $fullDomains = $this->fetchMin3Availability($subdomain);
        $rootDomains = $this->extractMin3RootDomains($subdomain, $fullDomains);
        if (count($rootDomains) < 1) {
            return [];
        }

        $eggIds = array_values(array_filter(
            array_map('intval', DB::table('eggs')->pluck('id')->all()),
            static fn (int $value) => $value > 0
        ));
        if (count($eggIds) < 1) {
            return [];
        }

        $protocols = [];
        $types = [];
        foreach ($eggIds as $eggId) {
            $protocols[$eggId] = '';
            $types[$eggId] = 'tcp';
        }

        $existing = [];
        foreach (DB::table('subdomain_manager_domains')->select(['id', 'domain', 'provider'])->get() as $domain) {
            $normalized = strtolower(trim((string) $domain->domain));
            if ($normalized === '') {
                continue;
            }

            $existing[$normalized] = [
                'id' => (int) $domain->id,
                'domain' => (string) $domain->domain,
                'provider' => strtolower(trim((string) ($domain->provider ?? 'cloudflare'))),
            ];
        }

        $result = [];
        foreach ($rootDomains as $rootDomain) {
            if (isset($existing[$rootDomain])) {
                if (($existing[$rootDomain]['provider'] ?? 'cloudflare') !== 'min3') {
                    continue;
                }

                $result[] = [
                    'id' => (int) $existing[$rootDomain]['id'],
                    'domain' => (string) $existing[$rootDomain]['domain'],
                    'provider' => 'min3',
                ];
                continue;
            }

            $id = (int) DB::table('subdomain_manager_domains')->insertGetId([
                'domain' => $rootDomain,
                'provider' => 'min3',
                'egg_ids' => implode(',', $eggIds),
                'protocol' => serialize($protocols),
                'protocol_types' => serialize($types),
            ]);

            $result[] = [
                'id' => $id,
                'domain' => $rootDomain,
                'provider' => 'min3',
            ];
        }

        usort($result, static fn (array $a, array $b) => strcmp($a['domain'], $b['domain']));

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function fetchMin3Availability(string $subdomain): array
    {
        $apiKey = trim((string) $this->settingsRepository->get('settings::subdomain::min3_api_key', ''));
        if ($apiKey === '') {
            throw new DisplayException('Min3 API key is missing. Configure it in Admin > SubDomain Manager settings.');
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get(sprintf('https://min3.online/api/checking/%s', rawurlencode($subdomain)));
        } catch (ConnectionException) {
            throw new DisplayException('Failed to connect to Min3 availability API.');
        }

        $json = $response->json();
        if (!$response->successful() || !is_array($json)) {
            throw new DisplayException('Failed to check Min3 domain availability.');
        }

        $records = array_is_list($json)
            ? $json
            : (is_array($json['data'] ?? null) ? $json['data'] : []);

        $domains = [];
        foreach ($records as $record) {
            if (!is_string($record)) {
                continue;
            }

            $domainName = rtrim(strtolower(trim($record)), '.');
            if ($domainName !== '' && $this->isValidDnsHostname($domainName)) {
                $domains[] = $domainName;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param array<int, string> $availableDomains
     * @return array<int, string>
     */
    private function extractMin3RootDomains(string $subdomain, array $availableDomains): array
    {
        $roots = [];
        $prefix = $subdomain . '.';
        $prefixLen = strlen($prefix);

        foreach ($availableDomains as $domainName) {
            if (!str_starts_with($domainName, $prefix)) {
                continue;
            }

            $root = substr($domainName, $prefixLen);
            $root = rtrim(strtolower(trim((string) $root)), '.');

            if ($root !== '' && $this->isValidDnsHostname($root)) {
                $roots[$root] = true;
            }
        }

        return array_keys($roots);
    }

    private function resolveProtocolAndType(object $domain, int $eggId): array
    {
        $protocols = @unserialize((string) $domain->protocol, ['allowed_classes' => false]);
        $types = @unserialize((string) $domain->protocol_types, ['allowed_classes' => false]);

        $protocols = is_array($protocols) ? $protocols : [];
        $types = is_array($types) ? $types : [];

        $protocol = trim((string) ($protocols[$eggId] ?? ''));
        $protocol = $protocol === '' ? '' : '_' . ltrim(strtolower($protocol), '_');

        $type = strtolower(trim((string) ($types[$eggId] ?? 'tcp')));
        $type = in_array($type, ['tcp', 'udp', 'tls'], true) ? $type : 'tcp';

        return [$protocol, $type];
    }

    private function resolveDomainProvider(object $domain): string
    {
        $provider = strtolower(trim((string) ($domain->provider ?? 'cloudflare')));

        return $provider === 'min3' ? 'min3' : 'cloudflare';
    }

    /**
     * @param array<int, string> $candidates
     */
    private function resolvePublicIpForMin3(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }

            if ($this->isPublicIp($value)) {
                return $value;
            }

            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                continue;
            }

            if ($this->isValidDnsHostname($value)) {
                $resolved = @gethostbynamel($value);
                if (is_array($resolved)) {
                    foreach ($resolved as $resolvedIp) {
                        if ($this->isPublicIp($resolvedIp)) {
                            return $resolvedIp;
                        }
                    }
                }
            }
        }

        throw new DisplayException('Min3 requires a resolvable public IP address for this server allocation.');
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function normalizeSrvService(string $service, string $fallback): string
    {
        $service = strtolower(trim($service));
        if ($service === '') {
            $service = strtolower(trim($fallback));
        }

        $service = '_' . ltrim($service, '_');
        if (!preg_match('/^_[a-z0-9-]{1,62}$/', $service)) {
            throw new DisplayException('Invalid SRV service. Use letters, numbers, and hyphens.');
        }

        return $service;
    }

    private function normalizeSrvProtocolType(string $type, string $fallback): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            $type = strtolower(trim($fallback));
        }

        if (!in_array($type, ['tcp', 'udp', 'tls'], true)) {
            throw new DisplayException('Invalid SRV protocol type. Allowed: tcp, udp, tls.');
        }

        return $type;
    }

    private function normalizeSrvInteger(mixed $value, int $fallback, int $min, int $max, string $label): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (!is_numeric($value)) {
            throw new DisplayException(sprintf('%s must be a number.', $label));
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new DisplayException(sprintf('%s must be between %d and %d.', $label, $min, $max));
        }

        return $number;
    }

    private function createMin3Domain(string $fullDomain, string $ip, int $port): void
    {
        $domainName = strtolower(trim($fullDomain));
        $this->min3Request('POST', '/domains', [
            'name' => $domainName,
            'ip' => $ip,
            'port' => $port,
        ]);
    }

    private function deleteMin3Domain(string $fullDomain): void
    {
        $domainName = strtolower(trim($fullDomain));
        $encodedDomain = rawurlencode($domainName);

        $this->min3Request('DELETE', sprintf('/domains/%s', $encodedDomain));
    }

    /**
     * @return array<mixed>
     */
    private function min3Request(string $method, string $uri, array $payload = []): array
    {
        $apiKey = trim((string) $this->settingsRepository->get('settings::subdomain::min3_api_key', ''));
        if ($apiKey === '') {
            throw new DisplayException('Min3 API key is missing. Configure it in Admin > SubDomain Manager settings.');
        }

        $request = Http::timeout(15)
            ->acceptJson()
            ->withToken($apiKey)
            ->withHeaders(['Content-Type' => 'application/json']);

        $baseUrl = 'https://min3.online/api';

        try {
            $response = match (strtoupper($method)) {
                'POST' => $request->post($baseUrl . $uri, $payload),
                'DELETE' => $request->delete($baseUrl . $uri),
                default => throw new DisplayException('Unsupported Min3 request method.'),
            };
        } catch (ConnectionException) {
            throw new DisplayException('Failed to connect to Min3 API server.');
        }

        $json = $response->json();
        if (!$response->successful()) {
            $message = 'Min3 API request failed.';

            if (is_array($json)) {
                $message = (string) ($json['message'] ?? $json['error'] ?? $message);
            }

            throw new DisplayException($message);
        }

        return is_array($json) ? $json : [];
    }

    private function resolveAllocationTarget(object $allocation): string
    {
        return trim((string) ($allocation->ip_alias ?? ''));
    }

    private function isValidDnsHostname(string $hostname): bool
    {
        $hostname = rtrim(strtolower(trim($hostname)), '.');
        if ($hostname === '' || strlen($hostname) > 253) {
            return false;
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $hostname);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<mixed>
     */
    private function cloudflareRequest(string $method, string $uri, array $query = [], array $payload = []): array
    {
        $apiToken = trim((string) $this->settingsRepository->get('settings::subdomain::cf_api_token', ''));
        $email = trim((string) $this->settingsRepository->get('settings::subdomain::cf_email', ''));
        $apiKey = trim((string) $this->settingsRepository->get('settings::subdomain::cf_api_key', ''));

        $request = Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['Content-Type' => 'application/json']);

        if ($apiToken !== '') {
            $request = $request->withToken($apiToken);
        } else {
            if ($email === '' || $apiKey === '') {
                throw new DisplayException(
                    'Cloudflare credentials are missing. Configure API Token or Email + Global API Key in Subdomain Manager settings.'
                );
            }

            $request = $request->withHeaders([
                'X-Auth-Email' => $email,
                'X-Auth-Key' => $apiKey,
            ]);
        }

        if (!empty($query)) {
            $request = $request->withOptions(['query' => $query]);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $request->get(self::CLOUDFLARE_API_URL . $uri),
                'POST' => $request->post(self::CLOUDFLARE_API_URL . $uri, $payload),
                'DELETE' => $request->delete(self::CLOUDFLARE_API_URL . $uri),
                default => throw new DisplayException('Unsupported Cloudflare request method.'),
            };
        } catch (ConnectionException) {
            throw new DisplayException('Failed to connect to Cloudflare server.');
        }

        $json = $response->json();
        if (!$response->successful() || !is_array($json) || !($json['success'] ?? false)) {
            $message = 'Cloudflare API request failed.';
            if (is_array($json) && !empty($json['errors'][0]['message'])) {
                $message = 'Cloudflare: ' . $json['errors'][0]['message'];
            }

            throw new DisplayException($message);
        }

        return $json;
    }

    /**
     * @return array<mixed>
     */
    private function listDnsRecords(string $zoneId, string $recordType, string $recordName): array
    {
        $response = $this->cloudflareRequest('GET', sprintf('/zones/%s/dns_records', $zoneId), [
            'type' => $recordType,
            'name' => strtolower($recordName),
            'per_page' => 20,
            'page' => 1,
        ]);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function createDnsRecord(string $zoneId, array $payload): void
    {
        $this->cloudflareRequest('POST', sprintf('/zones/%s/dns_records', $zoneId), [], $payload);
    }

    private function deleteDnsRecord(string $zoneId, string $recordId): void
    {
        $this->cloudflareRequest('DELETE', sprintf('/zones/%s/dns_records/%s', $zoneId, $recordId));
    }

    private function getZoneId(string $domain): string
    {
        $response = $this->cloudflareRequest('GET', '/zones', [
            'name' => strtolower(trim($domain)),
            'status' => 'active',
            'match' => 'all',
            'per_page' => 1,
            'page' => 1,
        ]);

        $zoneId = $response['result'][0]['id'] ?? '';
        if (!is_string($zoneId) || trim($zoneId) === '') {
            throw new DisplayException('Unable to resolve Cloudflare zone for this domain.');
        }

        return $zoneId;
    }
}

class SubdomainManagerClientRequest extends ClientApiRequest
{
    public function permission()
    {
        return 'subdomain.manage';
    }
}
