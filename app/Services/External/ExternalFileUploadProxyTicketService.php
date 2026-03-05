<?php

namespace Pterodactyl\Services\External;

use Carbon\CarbonImmutable;
use RuntimeException;

class ExternalFileUploadProxyTicketService
{
    public function buildTicket(string $upstreamUrl, int $userId, string $externalServer): string
    {
        $this->assertValidUploadUrl($upstreamUrl);

        $now = CarbonImmutable::now();
        $payload = [
            'v' => 1,
            'iat' => $now->getTimestamp(),
            'exp' => $now->addSeconds($this->ticketTtl())->getTimestamp(),
            'uid' => $userId,
            'srv' => $externalServer,
            'upstream' => $upstreamUrl,
        ];

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $this->secret(), true);

        return sprintf('%s.%s', $encodedPayload, $this->base64UrlEncode($signature));
    }

    public function resolveUpstreamUrl(string $ticket, int $userId, string $externalServer): string
    {
        $payload = $this->decodeTicketPayload($ticket);
        $now = CarbonImmutable::now()->getTimestamp();

        if (($payload['exp'] ?? 0) < $now) {
            throw new RuntimeException('Upload proxy ticket has expired.');
        }

        if ((int) ($payload['uid'] ?? 0) !== $userId) {
            throw new RuntimeException('Upload proxy ticket is not valid for this user.');
        }

        if ((string) ($payload['srv'] ?? '') !== $externalServer) {
            throw new RuntimeException('Upload proxy ticket is not valid for this server.');
        }

        $upstream = (string) ($payload['upstream'] ?? '');
        $this->assertValidUploadUrl($upstream);

        return $upstream;
    }

    /**
     * Exposed for integration tests.
     */
    public function decodeTicketPayload(string $ticket): array
    {
        [$payloadPart, $signaturePart] = explode('.', $ticket, 2) + [null, null];
        if (!is_string($payloadPart) || !is_string($signaturePart)) {
            throw new RuntimeException('Malformed upload proxy ticket.');
        }

        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $payloadPart, $this->secret(), true));
        if (!$this->safeEquals($signaturePart, $expectedSignature)) {
            throw new RuntimeException('Invalid upload proxy ticket signature.');
        }

        $decoded = $this->base64UrlDecode($payloadPart);
        if ($decoded === false) {
            throw new RuntimeException('Invalid upload proxy ticket payload.');
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid upload proxy ticket payload.');
        }

        return $payload;
    }

    protected function ticketTtl(): int
    {
        $ttl = (int) config('pterodactyl.external_file_upload_proxy.ticket_ttl', 900);

        return min(3600, max(60, $ttl));
    }

    protected function secret(): string
    {
        $secret = trim((string) config('pterodactyl.external_websocket_proxy.secret', ''));
        if ($secret !== '') {
            return $secret;
        }

        $appKey = trim((string) config('app.key', ''));
        if ($appKey === '') {
            throw new RuntimeException('Application key is not configured.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $appKey;
    }

    protected function assertValidUploadUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            throw new RuntimeException('Upload proxy target URL is invalid.');
        }

        $scheme = strtolower((string) $parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Upload proxy target URL must use HTTP or HTTPS.');
        }
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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

    protected function safeEquals(string $left, string $right): bool
    {
        if (strlen($left) !== strlen($right)) {
            return false;
        }

        return hash_equals($left, $right);
    }
}

