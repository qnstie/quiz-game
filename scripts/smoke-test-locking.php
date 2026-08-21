#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Concurrent SQLite write smoke test for DreamHost / local data_dir.
 * Usage: php scripts/smoke-test-locking.php [/path/to/data]
 */

$dataDir = $argv[1] ?? dirname(__DIR__) . '/data/_smoke';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0750, true);
}
$dbPath = $dataDir . '/smoke.db';
@unlink($dbPath);

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('PRAGMA journal_mode = DELETE');
$pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)');

$workers = 20;
$writesEach = 25;
$pids = [];

for ($w = 0; $w < $workers; $w++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");
        exit(1);
    }
    if ($pid === 0) {
        $c = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $c->exec('PRAGMA busy_timeout = 5000');
        $c->exec('PRAGMA journal_mode = DELETE');
        $stmt = $c->prepare('INSERT INTO t (n) VALUES (:n)');
        for ($i = 0; $i < $writesEach; $i++) {
            $attempts = 0;
            while (true) {
                try {
                    $c->exec('BEGIN IMMEDIATE');
                    $stmt->execute(['n' => $w * 1000 + $i]);
                    $c->exec('COMMIT');
                    break;
                } catch (PDOException $e) {
                    if ($c->inTransaction()) {
                        $c->rollBack();
                    }
                    if (++$attempts >= 8) {
                        fwrite(STDERR, "worker {$w} failed: {$e->getMessage()}\n");
                        exit(2);
                    }
                    usleep(50_000);
                }
            }
        }
        exit(0);
    }
    $pids[] = $pid;
}

$failed = 0;
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        $failed++;
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
$expected = $workers * $writesEach;
echo "rows={$count} expected={$expected} failed_workers={$failed}\n";
exit(($count === $expected && $failed === 0) ? 0 : 1);
