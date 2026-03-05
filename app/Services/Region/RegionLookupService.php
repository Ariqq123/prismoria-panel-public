<?php

namespace Pterodactyl\Services\Region;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Throwable;

class RegionLookupService
{
    private const UNKNOWN_CITY = 'Unknown';
    private const UNKNOWN_COUNTRY_CODE = 'N/A';

    private const IP_PROVIDERS = [
        'ipwho.is',
        'ipapi.co',
        'geoiplookup.io',
        'ipapi.is',
    ];

    private const DNS_PROVIDERS = [
        'DNS-Cloudflare',
        'DNS-Google',
    ];

    public function __construct(private Client $client)
    {
    }

    public function lookupFromHost(string $host, ?string $preferredIpProvider = null, ?string $preferredDnsProvider = null): array
    {
        $resolvedIp = $this->resolveIpFromHost($host, $preferredDnsProvider);
        if ($resolvedIp === null) {
            return $this->dnsErrorPayload();
        }

        if ($this->isPrivateOrReservedIp($resolvedIp)) {
            return $this->privateNetworkPayload();
        }

        foreach ($this->orderedProviders($preferredIpProvider, self::IP_PROVIDERS) as $provider) {
            $result = $this->lookupWithProvider($provider, $resolvedIp);
            if (!is_null($result)) {
                return $result;
            }
        }

        return $this->ipApiErrorPayload();
    }

    public function dnsErrorPayload(): array
    {
        return $this->buildPayload(self::UNKNOWN_CITY, 'DNS Error', self::UNKNOWN_COUNTRY_CODE);
    }

    public function ipApiErrorPayload(): array
    {
        return $this->buildPayload(self::UNKNOWN_CITY, 'IP API Error', self::UNKNOWN_COUNTRY_CODE);
    }

    public function privateNetworkPayload(): array
    {
        return $this->buildPayload(self::UNKNOWN_CITY, 'Private Network', self::UNKNOWN_COUNTRY_CODE);
    }

    private function resolveIpFromHost(string $host, ?string $preferredDnsProvider = null): ?string
    {
        $host = trim($host);
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $hostname = strtolower(rtrim($host, '.'));
        if (!preg_match('/^[a-z0-9.-]+$/i', $hostname)) {
            return null;
        }

        foreach ($this->orderedProviders($preferredDnsProvider, self::DNS_PROVIDERS) as $provider) {
            $resolved = $this->resolveHostWithDnsProvider($provider, $hostname);
            if (!is_null($resolved)) {
                return $resolved;
            }
        }

        $nativeResolved = gethostbyname($hostname);
        if ($nativeResolved !== $hostname && filter_var($nativeResolved, FILTER_VALIDATE_IP)) {
            return $nativeResolved;
        }

        return null;
    }

    private function resolveHostWithDnsProvider(string $provider, string $hostname): ?string
    {
        $request = match ($provider) {
            'DNS-Google' => [
                'url' => 'https://dns.google/resolve',
                'query' => ['name' => $hostname, 'type' => 'A'],
                'headers' => ['Accept' => 'application/json'],
            ],
            'DNS-Cloudflare' => [
                'url' => 'https://cloudflare-dns.com/dns-query',
                'query' => ['name' => $hostname, 'type' => 'A'],
                'headers' => ['Accept' => 'application/dns-json'],
            ],
            default => null,
        };

        if (is_null($request)) {
            return null;
        }

        try {
            $response = $this->client->request('GET', $request['url'], [
                'query' => $request['query'],
                'headers' => $request['headers'],
                'http_errors' => false,
                'timeout' => 6,
                'connect_timeout' => 6,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $payload = json_decode((string) $response->getBody(), true);
            if (!is_array($payload)) {
                return null;
            }

            $answers = Arr::get($payload, 'Answer', []);
            if (!is_array($answers)) {
                return null;
            }

            foreach ($answers as $answer) {
                if (!is_array($answer)) {
                    continue;
                }

                // Type 1 = A record.
                if ((int) Arr::get($answer, 'type', 0) !== 1) {
                    continue;
                }

                $ip = (string) Arr::get($answer, 'data', '');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function lookupWithProvider(string $provider, string $ip): ?array
    {
        $request = match ($provider) {
            'ipwho.is' => [
                'url' => "https://ipwho.is/$ip",
                'query' => [],
            ],
            'ipapi.co' => [
                'url' => "https://ipapi.co/$ip/json",
                'query' => [],
            ],
            'geoiplookup.io' => [
                'url' => "https://json.geoiplookup.io/$ip",
                'query' => [],
            ],
            'ipapi.is' => [
                'url' => 'https://api.ipapi.is',
                'query' => ['q' => $ip],
            ],
            default => null,
        };

        if (is_null($request)) {
            return null;
        }

        try {
            $response = $this->client->request('GET', $request['url'], [
                'query' => $request['query'],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => sprintf('%s/%s', config('app.name', 'Pterodactyl'), 'region-module'),
                ],
                'http_errors' => false,
                'timeout' => 8,
                'connect_timeout' => 8,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $payload = json_decode((string) $response->getBody(), true);
            if (!is_array($payload)) {
                return null;
            }

            return $this->parseProviderResponse($provider, $payload);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseProviderResponse(string $provider, array $payload): ?array
    {
        return match ($provider) {
            'ipwho.is' => $this->parseIpWho($payload),
            'ipapi.co' => $this->parseIpApiCo($payload),
            'geoiplookup.io' => $this->parseGeoIpLookupIo($payload),
            'ipapi.is' => $this->parseIpApiIs($payload),
            default => null,
        };
    }

    private function parseIpWho(array $payload): ?array
    {
        if (Arr::get($payload, 'success') === false || !Arr::has($payload, 'ip')) {
            return null;
        }

        return $this->buildPayload(
            (string) Arr::get($payload, 'city', self::UNKNOWN_CITY),
            (string) Arr::get($payload, 'country', 'Unknown'),
            (string) Arr::get($payload, 'country_code', self::UNKNOWN_COUNTRY_CODE)
        );
    }

    private function parseIpApiCo(array $payload): ?array
    {
        if (Arr::get($payload, 'error') === true || !Arr::has($payload, 'ip')) {
            return null;
        }

        return $this->buildPayload(
            (string) Arr::get($payload, 'city', self::UNKNOWN_CITY),
            (string) Arr::get($payload, 'country_name', 'Unknown'),
            (string) Arr::get($payload, 'country_code', self::UNKNOWN_COUNTRY_CODE)
        );
    }

    private function parseGeoIpLookupIo(array $payload): ?array
    {
        if (!Arr::has($payload, 'ip')) {
            return null;
        }

        return $this->buildPayload(
            (string) Arr::get($payload, 'city', self::UNKNOWN_CITY),
            (string) Arr::get($payload, 'country_name', 'Unknown'),
            (string) Arr::get($payload, 'country_code', self::UNKNOWN_COUNTRY_CODE)
        );
    }

    private function parseIpApiIs(array $payload): ?array
    {
        if (!Arr::has($payload, 'ip')) {
            return null;
        }

        return $this->buildPayload(
            (string) Arr::get($payload, 'location.city', self::UNKNOWN_CITY),
            (string) Arr::get($payload, 'location.country', 'Unknown'),
            (string) Arr::get($payload, 'location.country_code', self::UNKNOWN_COUNTRY_CODE)
        );
    }

    private function orderedProviders(?string $preferred, array $available): array
    {
        $preferred = trim((string) $preferred);
        if ($preferred === '' || !in_array($preferred, $available, true)) {
            return $available;
        }

        return array_values(array_unique([$preferred, ...$available]));
    }

    private function buildPayload(string $city, string $countryName, string $countryCode): array
    {
        $city = trim($city);
        $countryName = trim($countryName);
        $countryCode = strtoupper(trim($countryCode));

        return [
            'city' => $city !== '' ? $city : self::UNKNOWN_CITY,
            'country_name' => $countryName !== '' ? $countryName : 'Unknown',
            'country_code' => $countryCode !== '' ? $countryCode : self::UNKNOWN_COUNTRY_CODE,
        ];
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
