<?php

namespace Pterodactyl\Services\External;

use InvalidArgumentException;
use Pterodactyl\Models\ExternalPanelConnection;

class ExternalServerReference
{
    public function __construct(
        public readonly ExternalPanelConnection $connection,
        public readonly string $serverIdentifier
    ) {
    }

    public static function parseCompositeIdentifier(string $identifier): array
    {
        if (!preg_match('/^external:(\d+):(.+)$/', $identifier, $matches)) {
            throw new InvalidArgumentException('Invalid external server identifier.');
        }

        return [
            'connection_id' => (int) $matches[1],
            'server_identifier' => $matches[2],
        ];
    }

    public static function parseRouteParameter(string $value): array
    {
        if (!preg_match('/^(\d+):(.+)$/', $value, $matches)) {
            throw new InvalidArgumentException('Invalid external server route parameter.');
        }

        return [
            'connection_id' => (int) $matches[1],
            'server_identifier' => $matches[2],
        ];
    }

    public function toCompositeIdentifier(): string
    {
        return sprintf('external:%d:%s', $this->connection->id, $this->serverIdentifier);
    }

    public function toRouteParameter(): string
    {
        return sprintf('%d:%s', $this->connection->id, $this->serverIdentifier);
    }
}
