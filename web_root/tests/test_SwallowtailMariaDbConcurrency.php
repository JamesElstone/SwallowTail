<?php
/**
 * Optional MariaDB concurrency smoke test for SwallowTail ingest primitives.
 *
 * Set SWALLOWTAIL_MARIADB_CONCURRENCY_DSN (and optionally
 * SWALLOWTAIL_MARIADB_CONCURRENCY_USER/PASS) to a disposable MariaDB database.
 * The test creates and removes uniquely named tables; it never uses application
 * tables. Without a DSN it reports SKIP and exits successfully.
 */
declare(strict_types=1);

function concurrencyPdo(): PDO
{
    $dsn = trim((string)getenv('SWALLOWTAIL_MARIADB_CONCURRENCY_DSN'));
    $user = (string)getenv('SWALLOWTAIL_MARIADB_CONCURRENCY_USER');
    $pass = (string)getenv('SWALLOWTAIL_MARIADB_CONCURRENCY_PASS');

    return new PDO($dsn, $user !== '' ? $user : null, $pass !== '' ? $pass : null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function isDuplicateKey(PDOException $exception): bool
{
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'duplicate')
        || str_contains($message, '23000')
        || str_contains($message, '1062');
}

function worker(string $suffix, string $profileValue): never
{
    $pdo = concurrencyPdo();
    $photos = 'st_concurrency_photos_' . $suffix;
    $jobs = 'st_concurrency_jobs_' . $suffix;
    $profile = 'st_concurrency_profile_' . $suffix;
    $checksum = str_repeat('a', 64);
    $photoId = 0;
    $created = false;

    try {
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare("INSERT INTO {$photos} (sha256, original_filename) VALUES (:sha256, :filename)");
            $statement->execute(['sha256' => $checksum, 'filename' => 'IMG_0001.CR2']);
            $photoId = (int)$pdo->lastInsertId();
            $created = true;
        } catch (PDOException $exception) {
            $pdo->rollBack();
            if (!isDuplicateKey($exception)) {
                throw $exception;
            }
            $statement = $pdo->prepare("SELECT id FROM {$photos} WHERE sha256 = :sha256 LIMIT 1");
            $statement->execute(['sha256' => $checksum]);
            $photoId = (int)$statement->fetchColumn();
            if ($photoId <= 0) {
                throw new RuntimeException('Duplicate winner was not visible after conflicting insert.');
            }
            $pdo->beginTransaction();
        }

        foreach (['embedded', 'thumbnail', 'original'] as $imageType) {
            $statement = $pdo->prepare(
                "INSERT INTO {$jobs} (photo_id, image_type) VALUES (:photo_id, :image_type)
                 ON DUPLICATE KEY UPDATE photo_id = VALUES(photo_id)"
            );
            $statement->execute(['photo_id' => $photoId, 'image_type' => $imageType]);
        }

        $statement = $pdo->prepare(
            "UPDATE {$profile}
             SET profile_value = :profile_value
             WHERE photo_id = :photo_id AND profile_type = :profile_type
               AND profile_key = :profile_key AND revision = 0"
        );
        $params = [
            'photo_id' => $photoId,
            'profile_type' => 'status',
            'profile_key' => 'conversion',
            'profile_value' => $profileValue,
        ];
        $statement->execute($params);
        if ($statement->rowCount() === 0) {
            $existing = $pdo->prepare(
                "SELECT 1 FROM {$profile}
                 WHERE photo_id = :photo_id AND profile_type = :profile_type
                   AND profile_key = :profile_key AND revision = 0 LIMIT 1"
            );
            $existing->execute([
                'photo_id' => $photoId,
                'profile_type' => 'status',
                'profile_key' => 'conversion',
            ]);
            if ($existing->fetchColumn() === false) {
                try {
                    $insert = $pdo->prepare(
                        "INSERT INTO {$profile}
                         (photo_id, profile_type, profile_key, revision, profile_value)
                         VALUES (:photo_id, :profile_type, :profile_key, 0, :profile_value)"
                    );
                    $insert->execute($params);
                } catch (PDOException $exception) {
                    if (!isDuplicateKey($exception)) {
                        throw $exception;
                    }
                    $statement->execute($params);
                }
            }
        }
        $pdo->commit();

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $next = $pdo->prepare(
                    "SELECT COALESCE(MAX(revision), 0) + 1
                     FROM {$profile}
                     WHERE photo_id = :photo_id AND profile_type = :profile_type"
                );
                $next->execute(['photo_id' => $photoId, 'profile_type' => 'edit']);
                $revision = (int)$next->fetchColumn();
                $insert = $pdo->prepare(
                    "INSERT INTO {$profile}
                     (photo_id, profile_type, profile_key, revision, profile_value)
                     VALUES (:photo_id, 'edit', 'exposure', :revision, :profile_value)"
                );
                $insert->execute([
                    'photo_id' => $photoId,
                    'revision' => $revision,
                    'profile_value' => $profileValue,
                ]);
                break;
            } catch (PDOException $exception) {
                if (!isDuplicateKey($exception) || $attempt === 3) {
                    throw $exception;
                }
                usleep(25000 * ($attempt + 1));
            }
        }

        echo json_encode(['created' => $created, 'photo_id' => $photoId], JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, $throwable::class . ': ' . $throwable->getMessage() . PHP_EOL);
        exit(1);
    }
}

$dsn = trim((string)getenv('SWALLOWTAIL_MARIADB_CONCURRENCY_DSN'));
if ($dsn === '') {
    echo "SKIP SwallowTail MariaDB concurrency test: SWALLOWTAIL_MARIADB_CONCURRENCY_DSN is not configured.\n";
    exit(0);
}

if (($argv[1] ?? '') === '--worker') {
    $suffix = (string)($argv[2] ?? '');
    $profileValue = (string)($argv[3] ?? '');
    if (!preg_match('/^[a-f0-9]{12}$/', $suffix) || $profileValue === '') {
        fwrite(STDERR, "Invalid concurrency worker arguments.\n");
        exit(2);
    }
    worker($suffix, $profileValue);
}

$suffix = bin2hex(random_bytes(6));
$photos = 'st_concurrency_photos_' . $suffix;
$jobs = 'st_concurrency_jobs_' . $suffix;
$profile = 'st_concurrency_profile_' . $suffix;

try {
    $pdo = concurrencyPdo();
} catch (Throwable $throwable) {
    echo 'SKIP SwallowTail MariaDB concurrency test: database unavailable: ' . $throwable->getMessage() . PHP_EOL;
    exit(0);
}

try {
    $pdo->exec("CREATE TABLE {$photos} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sha256 CHAR(64) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        UNIQUE KEY uq_sha256 (sha256)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE {$jobs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        photo_id BIGINT UNSIGNED NOT NULL,
        image_type VARCHAR(32) NOT NULL,
        UNIQUE KEY uq_photo_type (photo_id, image_type)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE {$profile} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        photo_id BIGINT UNSIGNED NOT NULL,
        profile_type VARCHAR(64) NOT NULL,
        profile_key VARCHAR(128) NOT NULL,
        revision INT UNSIGNED NOT NULL,
        profile_value TEXT NOT NULL,
        UNIQUE KEY uq_profile_revision (photo_id, profile_type, profile_key, revision)
    ) ENGINE=InnoDB");

    $processes = [];
    foreach (['worker-a', 'worker-b'] as $value) {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__FILE__)
            . ' --worker ' . escapeshellarg($suffix)
            . ' ' . escapeshellarg($value);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start a MariaDB concurrency worker.');
        }
        $processes[] = [$process, $pipes];
    }

    $createdCount = 0;
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Concurrency worker failed: ' . trim((string)$stderr));
        }
        $result = json_decode((string)$stdout, true, flags: JSON_THROW_ON_ERROR);
        $createdCount += !empty($result['created']) ? 1 : 0;
    }

    $photoCount = (int)$pdo->query("SELECT COUNT(*) FROM {$photos}")->fetchColumn();
    $jobCount = (int)$pdo->query("SELECT COUNT(*) FROM {$jobs}")->fetchColumn();
    $revisionZeroCount = (int)$pdo->query("SELECT COUNT(*) FROM {$profile} WHERE revision = 0")->fetchColumn();
    $editRevisionCount = (int)$pdo->query("SELECT COUNT(*) FROM {$profile} WHERE profile_type = 'edit'")->fetchColumn();
    if ($createdCount !== 1 || $photoCount !== 1 || $jobCount !== 3 || $revisionZeroCount !== 1 || $editRevisionCount !== 2) {
        throw new RuntimeException(sprintf(
            'Unexpected race result: creators=%d photos=%d jobs=%d revision_zero=%d edit_revisions=%d',
            $createdCount,
            $photoCount,
            $jobCount,
            $revisionZeroCount,
            $editRevisionCount
        ));
    }

    echo "PASS SwallowTail MariaDB concurrency test.\n";
} finally {
    foreach ([$jobs, $profile, $photos] as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        } catch (Throwable) {
        }
    }
}
