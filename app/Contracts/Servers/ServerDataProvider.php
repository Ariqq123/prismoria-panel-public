<?php

namespace Pterodactyl\Contracts\Servers;

use Illuminate\Http\Request;
use Pterodactyl\Models\User;

interface ServerDataProvider
{
    public function listServersForUser(User $user, array $context = []): array;

    public function getServer(string $identifier, User $user): array;

    public function getResources(string $identifier, User $user): array;

    public function getWebsocket(string $identifier, User $user): array;

    public function sendPowerAction(string $identifier, User $user, array $payload): void;

    public function sendCommand(string $identifier, User $user, array $payload): void;

    public function proxyJson(string $identifier, User $user, string $method, string|array $endpoint, array $options = []): array;

    public function proxyNoContent(string $identifier, User $user, string $method, string|array $endpoint, array $options = []): void;

    public function proxyText(string $identifier, User $user, string $method, string|array $endpoint, Request $request, array $options = []): string;

    public function getPermissions(string $identifier, User $user): array;
}
