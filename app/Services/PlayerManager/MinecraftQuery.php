<?php

namespace Pterodactyl\Services\PlayerManager;

use Pterodactyl\Services\PlayerManager\Exceptions\MinecraftQueryException;

class MinecraftQuery
{
    /** @var resource|null */
    private $socket = null;
    private string $serverAddress;
    private int $serverPort;
    private int $timeout;

    /**
     * @throws MinecraftQueryException
     */
    public function __construct(string $address, int $port = 25565, int $timeout = 2, bool $resolveSrv = true)
    {
        $this->serverAddress = $address;
        $this->serverPort = $port;
        $this->timeout = $timeout;

        if ($resolveSrv) {
            $this->resolveSrv();
        }

        $this->connect();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * @throws MinecraftQueryException
     */
    public function connect(): void
    {
        $this->socket = @fsockopen(
            $this->serverAddress,
            $this->serverPort,
            $errno,
            $errstr,
            $this->timeout
        );

        if ($this->socket === false) {
            $this->socket = null;
            throw new MinecraftQueryException("Failed to connect or create a socket: $errno ($errstr)");
        }

        stream_set_timeout($this->socket, $this->timeout);
    }

    /**
     * @throws MinecraftQueryException
     */
    public function query(): array|false
    {
        if ($this->socket === null) {
            throw new MinecraftQueryException('Socket is not connected.');
        }

        $timeStart = microtime(true);

        $data = "\x00"; // packet id = 0 (handshake)
        $data .= "\x04"; // protocol version (varint)
        $data .= pack('C', strlen($this->serverAddress)) . $this->serverAddress;
        $data .= pack('n', $this->serverPort);
        $data .= "\x01"; // next state: status
        $data = pack('C', strlen($data)) . $data;

        fwrite($this->socket, $data);
        fwrite($this->socket, "\x01\x00"); // status ping

        $length = $this->readVarInt();
        if ($length < 10) {
            return false;
        }

        $this->readVarInt(); // packet type
        $length = $this->readVarInt(); // json payload length

        $buffer = '';
        while (strlen($buffer) < $length) {
            if ((microtime(true) - $timeStart) > $this->timeout) {
                throw new MinecraftQueryException('Server read timed out.');
            }

            $remaining = $length - strlen($buffer);
            $block = fread($this->socket, $remaining);
            if ($block === false || $block === '') {
                throw new MinecraftQueryException('Server returned too little data.');
            }

            $buffer .= $block;
        }

        $decoded = json_decode($buffer, true);
        if (!is_array($decoded)) {
            throw new MinecraftQueryException('JSON parsing failed.');
        }

        return $decoded;
    }

    public function queryOldPre17(): array|false
    {
        if ($this->socket === null) {
            return false;
        }

        fwrite($this->socket, "\xFE\x01");
        $data = fread($this->socket, 512);
        if ($data === false || strlen($data) < 4 || $data[0] !== "\xFF") {
            return false;
        }

        $data = substr($data, 3);
        $data = iconv('UTF-16BE', 'UTF-8', $data);
        if ($data === false) {
            return false;
        }

        if (isset($data[1], $data[2]) && $data[1] === "\xA7" && $data[2] === "\x31") {
            $parts = explode("\x00", $data);

            return [
                'HostName' => $parts[3] ?? '',
                'Players' => (int) ($parts[4] ?? 0),
                'MaxPlayers' => (int) ($parts[5] ?? 0),
                'Protocol' => (int) ($parts[1] ?? 0),
                'Version' => $parts[2] ?? '',
            ];
        }

        $parts = explode("\xA7", $data);

        return [
            'HostName' => isset($parts[0]) ? substr($parts[0], 0, -1) : '',
            'Players' => isset($parts[1]) ? (int) $parts[1] : 0,
            'MaxPlayers' => isset($parts[2]) ? (int) $parts[2] : 0,
            'Protocol' => 0,
            'Version' => '1.3',
        ];
    }

    /**
     * @throws MinecraftQueryException
     */
    private function readVarInt(): int
    {
        if ($this->socket === null) {
            return 0;
        }

        $value = 0;
        $position = 0;

        while (true) {
            $byte = @fgetc($this->socket);
            if ($byte === false) {
                return 0;
            }

            $byte = ord($byte);
            $value |= ($byte & 0x7F) << ($position++ * 7);

            if ($position > 5) {
                throw new MinecraftQueryException('VarInt is too large.');
            }

            if (($byte & 0x80) !== 0x80) {
                break;
            }
        }

        return $value;
    }

    private function resolveSrv(): void
    {
        if (filter_var($this->serverAddress, FILTER_VALIDATE_IP)) {
            return;
        }

        $record = @dns_get_record('_minecraft._tcp.' . $this->serverAddress, DNS_SRV);
        if (empty($record) || !is_array($record[0])) {
            return;
        }

        if (!empty($record[0]['target'])) {
            $this->serverAddress = (string) $record[0]['target'];
        }

        if (!empty($record[0]['port'])) {
            $this->serverPort = (int) $record[0]['port'];
        }
    }
}
