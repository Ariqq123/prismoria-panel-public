<?php

namespace Pterodactyl\Services\Servers\Providers;

use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Pterodactyl\Contracts\Servers\ServerDataProvider;

class LocalServerDataProvider implements ServerDataProvider
{
    public function listServersForUser(User $user, array $context = []): array
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function getServer(string $identifier, User $user): array
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function getResources(string $identifier, User $user): array
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function getWebsocket(string $identifier, User $user): array
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function sendPowerAction(string $identifier, User $user, array $payload): void
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function sendCommand(string $identifier, User $user, array $payload): void
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function proxyJson(string $identifier, User $user, string $method, string|array $endpoint, array $options = []): array
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function proxyNoContent(string $identifier, User $user, string $method, string|array $endpoint, array $options = []): void
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function proxyText(
        string $identifier,
        User $user,
        string $method,
        string|array $endpoint,
        Request $request,
        array $options = []
    ): string {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }

    public function getPermissions(string $identifier, User $user): array
    {
        throw new \BadMethodCallException('LocalServerDataProvider is not wired into the legacy local server controllers.');
    }
}
