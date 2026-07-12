<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use Swallowtail\Store\SwallowtailConfigurationStore;

final class SwallowtailRedisPipelineService
{
    private const SAMPLE_LIMIT = 5;

    public function __construct(private readonly object $redis = new SwallowtailRedisService())
    {
    }

    public function pipelineRows(): array
    {
        $rows = [];
        foreach ($this->definitions() as $definition) {
            $key = trim((string)SwallowtailConfigurationStore::get($definition['config'], $definition['default']));
            $length = $key === '' || !method_exists($this->redis, 'listLength') ? null : $this->redis->listLength($key);
            $available = is_int($length);
            $messages = [];
            if ($available && $length > 0 && method_exists($this->redis, 'listRange')) {
                $rawMessages = $this->redis->listRange($key, -self::SAMPLE_LIMIT, -1);
                if (!is_array($rawMessages)) {
                    $available = false;
                } else {
                    foreach (array_reverse($rawMessages) as $rawMessage) {
                        $messages[] = $this->summariseMessage((string)$rawMessage);
                    }
                }
            }
            $rows[] = $definition + [
                'key' => $key,
                'length' => $length,
                'available' => $available,
                'messages' => $messages,
            ];
        }
        return $rows;
    }

    private function definitions(): array
    {
        return [
            ['name' => 'Conversion urgent', 'purpose' => 'High-priority conversion wake-ups', 'config' => 'redis.urgent_queue', 'default' => 'swallowtail:conversion:urgent'],
            ['name' => 'Conversion normal', 'purpose' => 'Normal conversion wake-ups', 'config' => 'redis.normal_queue', 'default' => 'swallowtail:conversion:normal'],
            ['name' => 'Conversion preempt', 'purpose' => 'Signals that active work may be preempted', 'config' => 'redis.preempt_queue', 'default' => 'swallowtail:conversion:preempt'],
            ['name' => 'Storage wake', 'purpose' => 'Storage worker wake and repair signals', 'config' => 'redis.storage_wake_queue', 'default' => 'swallowtail:conversion:storage_wake'],
            ['name' => 'Metadata profile urgent', 'purpose' => 'Urgent source-profile requests', 'config' => 'redis.metadata_profile_queue', 'default' => 'swallowtail:metadata:profile_urgent'],
            ['name' => 'Metadata asset urgent', 'purpose' => 'Urgent asset inspection hints', 'config' => 'redis.metadata_asset_queue', 'default' => 'swallowtail:metadata:asset_urgent'],
            ['name' => 'Metadata data integrity', 'purpose' => 'Data-integrity maintenance requests', 'config' => 'redis.metadata_data_integrity_queue', 'default' => 'swallowtail:metadata:data_integrity'],
            ['name' => 'RawTherapee profile refresh', 'purpose' => 'Installed-profile rescan requests', 'config' => 'redis.rawtherapee_profile_refresh_queue', 'default' => 'swallowtail:metadata:rawtherapee_profiles'],
        ];
    }

    private function summariseMessage(string $raw): array
    {
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return ['summary' => 'Malformed or non-JSON message', 'queued_at' => null];
        }
        $parts = [];
        foreach (['job_id' => 'Job', 'photo_id' => 'Photo', 'image_type' => 'Type', 'priority' => 'Priority', 'reason' => 'Reason', 'action' => 'Action'] as $key => $label) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string)$payload[$key]) !== '') {
                $parts[] = $label . ': ' . substr((string)$payload[$key], 0, 96);
            }
        }
        foreach (['output_path', 'storage_base_location'] as $pathKey) {
            if (!empty($payload[$pathKey]) && is_scalar($payload[$pathKey])) {
                $parts[] = 'File: ' . basename(str_replace('\\', '/', (string)$payload[$pathKey]));
                break;
            }
        }
        $timestamp = null;
        foreach (['queued_at', 'requested_at', 'generated_at'] as $timestampKey) {
            if (isset($payload[$timestampKey]) && is_numeric($payload[$timestampKey])) {
                $timestamp = max(0, (int)$payload[$timestampKey]);
                break;
            }
        }
        return [
            'summary' => $parts === [] ? 'Recognised JSON message with no displayable fields' : implode(' | ', $parts),
            'queued_at' => $timestamp,
        ];
    }
}
