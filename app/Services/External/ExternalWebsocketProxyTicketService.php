<?php

namespace Pterodactyl\Services\External;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use RuntimeException;

class ExternalWebsocketProxyTicketService
{
    public function isEnabled(): bool
    {
        return (bool) config('pterodactyl.external_websocket_proxy.enabled', false)
            && $this->proxyUrl() !== ''
            && $this->secret() !== '';
    }

    public function buildProxySocketUrl(
        string $upstreamSocket,
        int $userId,
        string $externalServer,
        ?string $originOverride = null
    ): string
    {
        $proxyUrl = $this->proxyUrl();
        if ($proxyUrl === '') {
            throw new RuntimeException('External websocket proxy URL is not configured.');
        }

        $ticket = $this->buildTicket($upstreamSocket, $userId, $externalServer, $originOverride);
        $separator = str_contains($proxyUrl, '?') ? '&' : '?';

        return sprintf('%s%sticket=%s', $proxyUrl, $separator, rawurlencode($ticket));
    }

    protected function buildTicket(
        string $upstreamSocket,
        int $userId,
        string $externalServer,
        ?string $originOverride = null
    ): string
    {
        $now = CarbonImmutable::now();
        $origins = $this->originCandidates($originOverride);
        $payload = [
            'v' => 1,
            'iat' => $now->getTimestamp(),
            'exp' => $now->addSeconds($this->ticketTtl())->getTimestamp(),
            'uid' => $userId,
            'srv' => $externalServer,
            'upstream' => $upstreamSocket,
            'origin' => $origins[0] ?? '',
            'origins' => $origins,
        ];

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $this->secret(), true);

        return sprintf('%s.%s', $encodedPayload, $this->base64UrlEncode($signature));
    }

    protected function proxyUrl(): string
    {
        $url = trim((string) config('pterodactyl.external_websocket_proxy.url', ''));
        if ($url === '') {
            return '';
        }

        $url = preg_replace('/^https:\/\//i', 'wss://', $url) ?? $url;
        $url = preg_replace('/^http:\/\//i', 'ws://', $url) ?? $url;

        return rtrim($url, '/');
    }

    protected function origin(?string $originOverride = null): string
    {
        return $this->originCandidates($originOverride)[0] ?? '';
    }

    protected function ticketTtl(): int
    {
        $ttl = (int) config('pterodactyl.external_websocket_proxy.ticket_ttl', 90);

        return min(300, max(15, $ttl));
    }

    protected function secret(): string
    {
        $secret = trim((string) config('pterodactyl.external_websocket_proxy.secret', ''));
        if ($secret !== '') {
            return $secret;
        }

        $appKey = trim((string) config('app.key', ''));
        if ($appKey === '') {
            return '';
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $appKey;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Exposed for integration tests to validate payload wiring.
     */
    public function decodeTicketPayload(string $ticket): array
    {
        [$payload] = explode('.', $ticket, 2);

        $decoded = $this->base64UrlDecode($payload);
        if ($decoded === false) {
            throw new RuntimeException('Invalid websocket proxy ticket payload.');
        }

        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid websocket proxy ticket payload.');
        }

        return Arr::only($json, ['v', 'iat', 'exp', 'uid', 'srv', 'upstream', 'origin', 'origins']);
    }

    protected function base64UrlDecode(string $value): string|false
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = (4 - (strlen($normalized) % 4)) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', $padding);
        }

        return base64_decode($normalized, true);
    }

    protected function originCandidates(?string $originOverride = null): array
    {
        $origins = [];
        $this->appendOriginCandidates($origins, $originOverride);
        $this->appendOriginCandidates($origins, trim((string) config('pterodactyl.external_websocket_proxy.origin', '')));

        if (empty($origins)) {
            $this->appendOriginCandidates($origins, trim((string) config('app.url', '')));
        }

        return $origins;
    }

    protected function appendOriginCandidates(array &$collector, ?string $value): void
    {
        foreach ($this->splitOriginCandidates($value) as $origin) {
            $normalized = $this->normalizeOriginCandidate($origin);
            if ($normalized === '' || in_array($normalized, $collector, true)) {
                continue;
            }

            $collector[] = $normalized;
        }
    }

    protected function splitOriginCandidates(?string $value): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        if (!preg_match('/[\r\n,]/', $raw)) {
            return [$raw];
        }

        $parts = preg_split('/[\r\n,]+/', $raw);

        return array_values(array_filter(array_map('trim', $parts ?? []), fn (string $part) => $part !== ''));
    }

    protected function normalizeOriginCandidate(string $origin): string
    {
        $value = trim($origin);
        if ($value === '') {
            return '';
        }

        $parsed = parse_url($value);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return '';
        }

        $normalized = strtolower($parsed['scheme']) . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $normalized .= ':' . $parsed['port'];
        }

        return $normalized;
    }
}
