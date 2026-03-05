<?php

namespace Pterodactyl\Services\PlayerManager;

use Illuminate\Support\Arr;
use Pterodactyl\Services\PlayerManager\Exceptions\MinecraftQueryException;

class PlayerManagerService
{
    public function decodeJsonFile(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getPlayersData(?string $host, ?int $port, array $ops, array $whitelist): array
    {
        $players = [
            'players' => [
                'max' => 0,
                'online' => 0,
            ],
            'list' => [],
        ];

        $host = trim((string) $host);
        if ($host === '') {
            return $players;
        }

        $opNames = $this->normalizePlayerNames($ops);
        $whitelistNames = $this->normalizePlayerNames($whitelist);

        $query = null;

        try {
            $query = new MinecraftQuery($host, max(1, (int) ($port ?? 25565)), 2);
            $serverInfo = $query->query();

            if ($serverInfo === false) {
                $legacy = $query->queryOldPre17();
                if (is_array($legacy)) {
                    $players['players'] = [
                        'max' => (int) Arr::get($legacy, 'MaxPlayers', 0),
                        'online' => (int) Arr::get($legacy, 'Players', 0),
                    ];
                }

                return $players;
            }

            $playerDetails = Arr::get($serverInfo, 'players', []);
            $sample = Arr::get($playerDetails, 'sample', []);
            if (!is_array($sample)) {
                $sample = [];
            }

            foreach ($sample as $index => $player) {
                if (!is_array($player)) {
                    unset($sample[$index]);

                    continue;
                }

                $name = strtolower(trim((string) Arr::get($player, 'name', '')));
                if ($name === '') {
                    $sample[$index]['isOp'] = false;
                    $sample[$index]['isWhitelist'] = false;

                    continue;
                }

                $sample[$index]['isOp'] = in_array($name, $opNames, true);
                $sample[$index]['isWhitelist'] = in_array($name, $whitelistNames, true);
            }

            $players = [
                'players' => [
                    'max' => (int) Arr::get($playerDetails, 'max', 0),
                    'online' => (int) Arr::get($playerDetails, 'online', 0),
                ],
                'list' => array_values($sample),
            ];
        } catch (MinecraftQueryException) {
            return $players;
        } finally {
            if ($query !== null) {
                $query->close();
            }
        }

        return $players;
    }

    private function normalizePlayerNames(array $entries): array
    {
        $names = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = strtolower(trim((string) Arr::get($entry, 'name', '')));
            if ($name === '') {
                continue;
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }
}
