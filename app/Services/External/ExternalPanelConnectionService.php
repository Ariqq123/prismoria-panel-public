<?php

namespace Pterodactyl\Services\External;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Pterodactyl\Models\User;
use Illuminate\Support\Str;
use Pterodactyl\Models\ExternalPanelConnection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExternalPanelConnectionService
{
    public function __construct(private ExternalPanelClient $client)
    {
    }

    public function create(User $user, array $data): ExternalPanelConnection
    {
        $panelUrl = $this->normalizeUrl((string) $data['panel_url']);
        $websocketOrigin = $this->normalizeOrigin(
            Arr::get($data, 'websocket_origin', Arr::get($data, 'allowed_origin')),
            $panelUrl
        );
        $apiKey = trim((string) $data['api_key']);
        $this->verifyCredentials($panelUrl, $apiKey);

        $connection = new ExternalPanelConnection();
        $connection->fill([
            'user_id' => $user->id,
            'name' => Arr::get($data, 'name'),
            'panel_url' => $panelUrl,
            'websocket_origin' => $websocketOrigin,
            'api_key_encrypted' => $apiKey,
            'default_connection' => (bool) Arr::get($data, 'default_connection', false),
            'last_verified_at' => CarbonImmutable::now(),
        ]);
        $connection->save();

        if ($connection->default_connection) {
            $this->resetOtherDefaultConnections($user, $connection->id);
        }

        return $connection;
    }

    public function update(ExternalPanelConnection $connection, array $data): ExternalPanelConnection
    {
        $panelUrl = $this->normalizeUrl((string) Arr::get($data, 'panel_url', $connection->panel_url));
        $websocketOrigin = $this->normalizeOrigin(
            Arr::get($data, 'websocket_origin', Arr::get($data, 'allowed_origin', $connection->websocket_origin)),
            $panelUrl
        );
        $apiKey = trim((string) Arr::get($data, 'api_key', ''));
        $keyToUse = $apiKey !== '' ? $apiKey : $connection->api_key;

        $this->verifyCredentials($panelUrl, $keyToUse);

        $connection->fill([
            'name' => Arr::exists($data, 'name') ? Arr::get($data, 'name') : $connection->name,
            'panel_url' => $panelUrl,
            'websocket_origin' => $websocketOrigin,
            'default_connection' => (bool) Arr::get($data, 'default_connection', $connection->default_connection),
            'last_verified_at' => CarbonImmutable::now(),
        ]);

        if ($apiKey !== '') {
            $connection->api_key_encrypted = $apiKey;
        }

        $connection->save();

        if ($connection->default_connection) {
            $this->resetOtherDefaultConnections($connection->user, $connection->id);
        }

        return $connection;
    }

    public function verify(ExternalPanelConnection $connection): bool
    {
        try {
            $this->verifyCredentials($connection->panel_url, $connection->api_key);
        } catch (\Throwable $exception) {
            $connection->forceFill(['last_verified_at' => null])->save();

            return false;
        }

        $connection->forceFill(['last_verified_at' => CarbonImmutable::now()])->save();

        return true;
    }

    public function normalizeUrl(string $panelUrl): string
    {
        $panelUrl = trim($panelUrl);
        if (!Str::startsWith($panelUrl, ['http://', 'https://'])) {
            $panelUrl = "https://$panelUrl";
        }

        return $this->client->normalizePanelUrl($panelUrl);
    }

    public function normalizeOrigin(null|string $origin, string $fallbackPanelUrl): ?string
    {
        $value = is_string($origin) ? trim($origin) : '';
        if ($value === '') {
            $value = $fallbackPanelUrl;
        }

        if (!Str::startsWith($value, ['http://', 'https://'])) {
            $value = "https://$value";
        }

        $parsed = parse_url($value);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $normalized = strtolower($parsed['scheme']) . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $normalized .= ':' . $parsed['port'];
        }

        return $normalized;
    }

    public function verifyCredentials(string $panelUrl, string $apiKey): void
    {
        $temporary = new ExternalPanelConnection();
        $temporary->panel_url = $panelUrl;
        $temporary->api_key_encrypted = $apiKey;

        $response = $this->client->requestWithFallback($temporary, 'GET', ['account', '']);
        if (!$response->successful()) {
            $message = $this->client->extractErrorMessage($response);
            throw new HttpException(422, "Unable to verify external panel credentials: $message");
        }
    }

    protected function resetOtherDefaultConnections(User $user, int $activeConnectionId): void
    {
        $user->externalPanelConnections()
            ->where('id', '!=', $activeConnectionId)
            ->where('default_connection', true)
            ->update(['default_connection' => false]);
    }
}
