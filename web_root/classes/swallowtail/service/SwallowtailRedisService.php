<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use AppConfigurationStore;
use Throwable;

final class SwallowtailRedisService
{
    public function get(string $key): ?string
    {
        $response = $this->command('GET', $key);

        return is_string($response) ? $response : null;
    }

    public function setJson(string $key, array $payload, int $ttlSeconds): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }

        return $this->command('SET', $key, $json, 'EX', (string)max(1, $ttlSeconds)) === 'OK';
    }

    public function delete(string $key): void
    {
        $this->command('DEL', $key);
    }

    public function listPushJson(string $key, array $payload, int $maxLength = 0): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }

        $pushed = is_int($this->command('LPUSH', $key, $json));
        if ($pushed && $maxLength > 0) {
            $this->command('LTRIM', $key, '0', (string)($maxLength - 1));
        }

        return $pushed;
    }

    public function listLength(string $key): ?int
    {
        $response = $this->command('LLEN', $key);

        return is_int($response) ? max(0, $response) : null;
    }

    public function listRange(string $key, int $start, int $stop): ?array
    {
        $response = $this->command('LRANGE', $key, (string)$start, (string)$stop);
        if (!is_array($response)) {
            return null;
        }

        return array_values(array_filter($response, static fn(mixed $item): bool => is_string($item)));
    }

    public function ping(): bool
    {
        return $this->command('PING') === 'PONG';
    }

    private function command(string ...$parts): mixed
    {
        $host = trim((string)\Swallowtail\Store\SwallowtailConfigurationStore::get('redis.host', '127.0.0.1'));
        $port = (int)\Swallowtail\Store\SwallowtailConfigurationStore::get('redis.port', 6379);
        if ($host === '' || $port <= 0) {
            return null;
        }

        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.25);
        if (!is_resource($socket)) {
            return null;
        }

        try {
            stream_set_timeout($socket, 1);
            fwrite($socket, $this->encodeCommand($parts));
            $response = $this->readResponse($socket);
            fclose($socket);

            return $response;
        } catch (Throwable) {
            if (is_resource($socket)) {
                fclose($socket);
            }

            return null;
        }
    }

    /**
     * @param array<int, string> $parts
     */
    private function encodeCommand(array $parts): string
    {
        $command = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $command .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        return $command;
    }

    private function readResponse($socket): mixed
    {
        $marker = fread($socket, 1);
        if ($marker === false || $marker === '') {
            return null;
        }

        if ($marker === '+') {
            return $this->readLine($socket);
        }

        if ($marker === '-') {
            return null;
        }

        if ($marker === ':') {
            return (int)$this->readLine($socket);
        }

        if ($marker === '$') {
            $length = (int)$this->readLine($socket);
            if ($length < 0) {
                return null;
            }
            $data = $length > 0 ? fread($socket, $length) : '';
            fread($socket, 2);

            return is_string($data) ? $data : null;
        }

        if ($marker === '*') {
            $count = (int)$this->readLine($socket);
            $items = [];
            for ($index = 0; $index < $count; $index++) {
                $items[] = $this->readResponse($socket);
            }

            return $items;
        }

        return null;
    }

    private function readLine($socket): string
    {
        $line = fgets($socket);
        if (!is_string($line)) {
            return '';
        }

        return rtrim($line, "\r\n");
    }
}
