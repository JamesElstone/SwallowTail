<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$options = getopt('', [
    'input::',
    'storage-root::',
    'timeout::',
    'keep-artifacts',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php tools/php/rawConversionSmokeTest.php [--input=/home/james.elstone/TEST.CR2] [--storage-root=/storage/1/swallowtail-raw-smoke] [--timeout=300] [--keep-artifacts]\n";
    exit(0);
}

$input = (string)($options['input'] ?? '/home/james.elstone/TEST.CR2');
$storageRoot = (string)($options['storage-root'] ?? '/storage/1/swallowtail-raw-smoke');
$timeoutSeconds = max(10, (int)($options['timeout'] ?? 300));
$keepArtifacts = array_key_exists('keep-artifacts', $options);
$photoId = null;
$tempInput = null;
$smokeLocationId = null;
$originalSmokeLocationSortOrder = null;
$createdSmokeLocation = false;
$exitCode = 1;

try {
    if (!is_file($input) || !is_readable($input)) {
        throw new RuntimeException('Smoke test input is not readable: ' . $input);
    }

    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('Unable to create smoke storage root: ' . $storageRoot);
    }

    $tempInput = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'smoke-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.CR2';
    if (!copy($input, $tempInput)) {
        throw new RuntimeException('Unable to copy smoke input.');
    }
    file_put_contents($tempInput, "\nSWALLOWTAIL-SMOKE-" . bin2hex(random_bytes(8)) . "\n", FILE_APPEND | LOCK_EX);

    if (InterfaceDB::tableExists('swallowtail_storage_locations')) {
        $root = (new SwallowtailStorageService($storageRoot))->storageRoot();
        $location = InterfaceDB::fetchOne(
            'SELECT id, sort_order FROM swallowtail_storage_locations WHERE root_path = :root_path LIMIT 1',
            ['root_path' => $root]
        );
        if (!is_array($location)) {
            $smokeLocationId = (new SwallowtailStorageLocationService())->registerLocation(
                'Raw conversion smoke storage',
                $root,
                ['sort_order' => -1000]
            );
            $createdSmokeLocation = true;
        } else {
            $smokeLocationId = (int)$location['id'];
            $originalSmokeLocationSortOrder = (int)$location['sort_order'];
            InterfaceDB::prepareExecute(
                "UPDATE swallowtail_storage_locations
                 SET sort_order = -1000,
                     is_active = 1,
                     is_read_only = 0,
                     is_full = 0
                 WHERE id = :id",
                ['id' => $smokeLocationId]
            );
        }
    }

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($storageRoot),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );
    $result = $ingest->ingestLocalRawFile($tempInput, basename($tempInput), ['uploaded_via' => 'cli', 'smoke_test' => true]);
    if (empty($result['success']) || ($result['status'] ?? '') !== 'uploaded') {
        throw new RuntimeException('Smoke input was not ingested as a new uploaded photo: ' . json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    $photoId = (int)$result['photo_id'];
    $deadline = time() + $timeoutSeconds;
    $expectedTypes = ['original_jpeg', 'preview', 'thumbnail', 'jpeg'];

    do {
        $jobs = InterfaceDB::fetchAll(
            "SELECT id, derivative_type, status, last_error, output_path
             FROM swallowtail_photo_conversion_jobs
             WHERE photo_id = :photo_id
             ORDER BY id",
            ['photo_id' => $photoId]
        );
        $failed = array_values(array_filter($jobs, static fn (array $job): bool => ($job['status'] ?? '') === 'failed'));
        if ($failed !== []) {
            throw new RuntimeException('Smoke conversion failed: ' . json_encode($failed, JSON_UNESCAPED_SLASHES));
        }

        $succeededTypes = array_values(array_unique(array_map(
            static fn (array $job): string => (string)($job['derivative_type'] ?? ''),
            array_filter($jobs, static fn (array $job): bool => ($job['status'] ?? '') === 'succeeded')
        )));
        sort($succeededTypes);
        $expectedSorted = $expectedTypes;
        sort($expectedSorted);
        if ($succeededTypes === $expectedSorted) {
            break;
        }

        sleep(2);
    } while (time() < $deadline);

    if ($succeededTypes !== $expectedSorted) {
        throw new RuntimeException('Timed out waiting for smoke conversion jobs. Current jobs: ' . json_encode($jobs, JSON_UNESCAPED_SLASHES));
    }

    $derivatives = InterfaceDB::fetchAll(
        "SELECT derivative_type, storage_path, bytes
         FROM swallowtail_photo_derivatives
         WHERE photo_id = :photo_id",
        ['photo_id' => $photoId]
    );
    if (count($derivatives) !== count($expectedTypes)) {
        throw new RuntimeException('Smoke conversion did not create all derivative rows.');
    }

    foreach ($jobs as $job) {
        $outputPath = (string)($job['output_path'] ?? '');
        if (!is_file($outputPath) || filesize($outputPath) <= 0) {
            throw new RuntimeException('Smoke output file is missing or empty: ' . $outputPath);
        }
    }

    echo json_encode([
        'success' => true,
        'photo_id' => $photoId,
        'jobs' => $jobs,
        'derivatives' => $derivatives,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($tempInput !== null && is_file($tempInput)) {
        @unlink($tempInput);
    }

    if (!$keepArtifacts && $photoId !== null && $photoId > 0) {
        $paths = [];
        foreach (InterfaceDB::fetchAll(
            "SELECT output_path FROM swallowtail_photo_conversion_jobs WHERE photo_id = :photo_id",
            ['photo_id' => $photoId]
        ) as $job) {
            $paths[] = (string)($job['output_path'] ?? '');
        }
        $photo = InterfaceDB::fetchOne(
            "SELECT original_storage_path, storage_location_id FROM swallowtail_photos WHERE id = :id LIMIT 1",
            ['id' => $photoId]
        );
        if (is_array($photo)) {
            $root = $storageRoot;
            if (!empty($photo['storage_location_id'])) {
                $storedRoot = InterfaceDB::fetchColumn(
                    'SELECT root_path FROM swallowtail_storage_locations WHERE id = :id LIMIT 1',
                    ['id' => (int)$photo['storage_location_id']]
                );
                if (is_scalar($storedRoot) && trim((string)$storedRoot) !== '') {
                    $root = (string)$storedRoot;
                }
            }
            $paths[] = (new SwallowtailStorageService($root))->absolutePath((string)$photo['original_storage_path']);
        }

        InterfaceDB::execute('DELETE FROM swallowtail_photo_audit WHERE photo_id = :photo_id', ['photo_id' => $photoId]);
        InterfaceDB::execute('DELETE FROM swallowtail_photo_derivatives WHERE photo_id = :photo_id', ['photo_id' => $photoId]);
        InterfaceDB::execute('DELETE FROM swallowtail_photo_conversion_jobs WHERE photo_id = :photo_id', ['photo_id' => $photoId]);
        InterfaceDB::execute('DELETE FROM swallowtail_event_photos WHERE photo_id = :photo_id', ['photo_id' => $photoId]);
        InterfaceDB::execute('DELETE FROM swallowtail_photos WHERE id = :photo_id', ['photo_id' => $photoId]);

        foreach (array_unique(array_filter($paths)) as $path) {
            @unlink($path);
        }
    }

    if (!$keepArtifacts && $createdSmokeLocation && $smokeLocationId !== null) {
        try {
            InterfaceDB::prepareExecute(
                'DELETE FROM swallowtail_storage_locations WHERE id = :id',
                ['id' => $smokeLocationId]
            );
        } catch (Throwable) {
        }
    } elseif ($smokeLocationId !== null && $originalSmokeLocationSortOrder !== null) {
        try {
            InterfaceDB::prepareExecute(
                'UPDATE swallowtail_storage_locations SET sort_order = :sort_order WHERE id = :id',
                [
                    'sort_order' => $originalSmokeLocationSortOrder,
                    'id' => $smokeLocationId,
                ]
            );
        } catch (Throwable) {
        }
    }
}

exit($exitCode);
