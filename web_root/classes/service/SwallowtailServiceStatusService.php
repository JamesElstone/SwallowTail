<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailServiceStatusService
{
    private const HEARTBEAT_MAX_AGE_SECONDS = 360;
    private const HEARTBEAT_TTL_SECONDS = 720;

    private const PROJECT_SERVICES = [
        [
            'key' => 'swallowtail_conversion',
            'label' => 'RAW conversion worker',
            'pid_file' => '/var/run/swallowtail/swallowtail_conversion.pid',
            'heartbeat_key' => 'swallowtail:service:swallowtail_conversion:last_touched',
        ],
        [
            'key' => 'swallowtail_storage',
            'label' => 'Storage cache worker',
            'pid_file' => '/var/run/swallowtail/storage.pid',
            'heartbeat_key' => 'swallowtail:service:swallowtail_storage:last_touched',
        ],
    ];

    /** @var callable(int): ?bool|null */
    private mixed $processExists;
    /** @var callable(string): ?string|null */
    private mixed $heartbeatReader;
    /** @var callable(string, array, int): bool|null */
    private mixed $heartbeatWriter;

    public function __construct(
        private readonly SwallowtailRedisService $redis = new SwallowtailRedisService(),
        ?callable $processExists = null,
        ?callable $heartbeatReader = null,
        ?callable $heartbeatWriter = null,
    ) {
        $this->processExists = $processExists;
        $this->heartbeatReader = $heartbeatReader;
        $this->heartbeatWriter = $heartbeatWriter;
    }

    public function status(): array
    {
        $services = [];
        foreach (self::PROJECT_SERVICES as $service) {
            $services[] = $this->heartbeatStatus(
                (string)$service['key'],
                (string)$service['label'],
                (string)$service['heartbeat_key'],
                (string)$service['pid_file']
            );
        }

        return [
            'services' => $services,
            'redis' => $this->redisStatus(),
        ];
    }

    public function touchService(string $serviceKey, ?int $touchedAt = null): bool
    {
        $service = $this->projectService($serviceKey);
        if ($service === null) {
            throw new RuntimeException('Unknown SwallowTail service: ' . $serviceKey);
        }

        $touchedAt = $touchedAt ?? time();

        return $this->writeHeartbeat((string)$service['heartbeat_key'], [
            'service' => (string)$service['key'],
            'touched_at' => $touchedAt,
            'touched_at_iso' => gmdate('c', $touchedAt),
        ], self::HEARTBEAT_TTL_SECONDS);
    }

    private function heartbeatStatus(string $key, string $label, string $heartbeatKey, string $pidFile): array
    {
        $json = $this->readHeartbeat($heartbeatKey);
        $pidStatus = $this->pidFileStatus($key, $label, $pidFile);
        $pidDetail = (string)($pidStatus['detail'] ?? '');

        if ($json === null || trim($json) === '') {
            return $this->check(
                $key,
                $label,
                'bad',
                'Stale',
                'No heartbeat was found in Redis. ' . $pidDetail
            );
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return $this->check(
                $key,
                $label,
                'bad',
                'Stale',
                'The Redis heartbeat is not valid JSON. ' . $pidDetail
            );
        }

        $touchedAt = (int)($payload['touched_at'] ?? 0);
        if ($touchedAt <= 0) {
            return $this->check(
                $key,
                $label,
                'bad',
                'Stale',
                'The Redis heartbeat does not include a usable timestamp. ' . $pidDetail
            );
        }

        $ageSeconds = max(0, time() - $touchedAt);
        $detail = 'Last touched ' . $this->formatDuration($ageSeconds) . ' ago at ' . gmdate('Y-m-d H:i:s', $touchedAt) . ' UTC.';

        if ($ageSeconds <= self::HEARTBEAT_MAX_AGE_SECONDS) {
            return $this->check($key, $label, 'ok', 'Fresh', $detail);
        }

        return $this->check($key, $label, 'bad', 'Stale', $detail . ' ' . $pidDetail);
    }

    private function pidFileStatus(string $key, string $label, string $pidFile): array
    {
        clearstatcache(true, $pidFile);

        if (!is_file($pidFile)) {
            return $this->check($key, $label, 'bad', 'Not running', 'PID file is missing.');
        }

        $rawPid = @file_get_contents($pidFile);
        if (!is_string($rawPid)) {
            return $this->check($key, $label, 'warn', 'Unknown', 'PID file could not be read.');
        }

        $pid = (int)trim($rawPid);
        if ($pid <= 0) {
            return $this->check($key, $label, 'bad', 'Not running', 'PID file does not contain a valid PID.');
        }

        $exists = $this->processExists($pid);
        if ($exists === true) {
            return $this->check($key, $label, 'ok', 'Running', 'PID ' . $pid . ' is active.');
        }

        if ($exists === false) {
            return $this->check($key, $label, 'bad', 'Not running', 'PID ' . $pid . ' is not active.');
        }

        return $this->check($key, $label, 'warn', 'Unknown', 'PID ' . $pid . ' could not be verified on this host.');
    }

    private function redisStatus(): array
    {
        try {
            $available = $this->redis->ping();
        } catch (Throwable) {
            $available = false;
        }

        $endpoint = $this->redisEndpoint();

        return $available
            ? $this->check('redis', 'Redis access', 'ok', 'Available', 'PING succeeded at ' . $endpoint . '.')
            : $this->check('redis', 'Redis access', 'bad', 'Unavailable', 'PING failed at ' . $endpoint . '.');
    }

    private function processExists(int $pid): ?bool
    {
        if (is_callable($this->processExists)) {
            $exists = call_user_func($this->processExists, $pid);

            return is_bool($exists) ? $exists : null;
        }

        if (!function_exists('posix_kill')) {
            return null;
        }

        if (@posix_kill($pid, 0)) {
            return true;
        }

        $error = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
        if ($error === 1) {
            return true;
        }

        if ($error === 3) {
            return false;
        }

        return null;
    }

    private function redisEndpoint(): string
    {
        $host = trim((string)AppConfigurationStore::get('swallowtail.redis.host', '127.0.0.1'));
        $port = (int)AppConfigurationStore::get('swallowtail.redis.port', 6379);

        return ($host !== '' ? $host : '127.0.0.1') . ':' . max(0, $port);
    }

    private function readHeartbeat(string $key): ?string
    {
        if (is_callable($this->heartbeatReader)) {
            $value = call_user_func($this->heartbeatReader, $key);

            return is_string($value) ? $value : null;
        }

        return $this->redis->get($key);
    }

    private function writeHeartbeat(string $key, array $payload, int $ttlSeconds): bool
    {
        if (is_callable($this->heartbeatWriter)) {
            return (bool)call_user_func($this->heartbeatWriter, $key, $payload, $ttlSeconds);
        }

        return $this->redis->setJson($key, $payload, $ttlSeconds);
    }

    private function projectService(string $serviceKey): ?array
    {
        foreach (self::PROJECT_SERVICES as $service) {
            if ((string)$service['key'] === $serviceKey) {
                return $service;
            }
        }

        return null;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;
        if ($minutes < 60) {
            return $remainingSeconds > 0 ? $minutes . 'm ' . $remainingSeconds . 's' : $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? $hours . 'h ' . $remainingMinutes . 'm' : $hours . 'h';
    }

    private function check(string $key, string $label, string $state, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
