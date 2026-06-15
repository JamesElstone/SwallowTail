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
    'input:',
    'storage-root::',
    'pp3::',
    'help',
]);

if (isset($options['help']) || empty($options['input'])) {
    echo "Usage: php tools/php/enqueueRawConversionTest.php --input=/path/test.CR2 [--storage-root=/storage/1/swallowtail-test] [--pp3=/path/profile.pp3]\n";
    exit(isset($options['help']) ? 0 : 1);
}

$input = (string)$options['input'];
$storageRoot = trim((string)($options['storage-root'] ?? AppConfigurationStore::get('swallowtail.storage.root', PROJECT_ROOT . 'uploads')));
$pp3Path = trim((string)($options['pp3'] ?? ''));

if (!is_file($input) || !is_readable($input)) {
    fwrite(STDERR, "Input CR2 file is not readable: {$input}\n");
    exit(1);
}

if (strtolower(pathinfo($input, PATHINFO_EXTENSION)) !== 'cr2') {
    fwrite(STDERR, "Only CR2 input is supported by this test enqueue tool.\n");
    exit(1);
}

try {
    if (InterfaceDB::tableExists('swallowtail_storage_locations')) {
        $root = (new SwallowtailStorageService($storageRoot))->storageRoot();
        $existingLocationId = InterfaceDB::fetchColumn(
            'SELECT id FROM swallowtail_storage_locations WHERE root_path = :root_path LIMIT 1',
            ['root_path' => $root]
        );

        if ($existingLocationId === false || $existingLocationId === null) {
            (new SwallowtailStorageLocationService())->registerLocation('Raw conversion test storage', $root);
        }
    }

    $queue = new SwallowtailConversionQueueService();
    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($storageRoot),
        new SwallowtailPhotoLibraryService(),
        $queue
    );

    $result = $ingest->ingestLocalRawFile($input, basename($input), ['uploaded_via' => 'cli']);
    if (empty($result['success'])) {
        fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }

    $photoId = (int)$result['photo_id'];
    if ($pp3Path !== '') {
        $queue->enqueuePreviewRefresh($photoId, $pp3Path, 2);
    }

    $jobs = InterfaceDB::fetchAll(
        "SELECT id, derivative_type, priority, status, input_path, output_path, pp3_path
         FROM swallowtail_photo_conversion_jobs
         WHERE photo_id = :photo_id
         ORDER BY id",
        ['photo_id' => $photoId]
    );

    echo json_encode([
        'success' => true,
        'photo_id' => $photoId,
        'status' => $result['status'] ?? null,
        'jobs' => $jobs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
