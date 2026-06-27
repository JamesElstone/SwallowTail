<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailDataIntegrityCheckService;

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function swallowtail_data_integrity_cli(array $argv): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This tool must be run from the command line.\n");
        return 1;
    }

    $processLazy = in_array('--process-lazy-loading', $argv, true);
    $json = in_array('--json', $argv, true);
    $limit = 150;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $limit = max(1, min(150, (int)substr($arg, 8)));
        }
    }

    if (!$processLazy) {
        fwrite(STDERR, "Usage: php tools/php/dataIntegrityCheck.php --process-lazy-loading [--json] [--limit=150]\n");
        return 1;
    }

    try {
        $result = (new SwallowtailDataIntegrityCheckService())->processLazyLoadingPreventionBatch($limit);
        if ($json) {
            echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo (string)($result['message'] ?? 'Data integrity maintenance batch completed.') . "\n";
        }

        return !empty($result['success']) ? 0 : 1;
    } catch (Throwable $exception) {
        if ($json) {
            echo json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
            ], JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            fwrite(STDERR, 'Data integrity maintenance failed: ' . $exception->getMessage() . "\n");
        }

        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    exit(swallowtail_data_integrity_cli($argv));
}
