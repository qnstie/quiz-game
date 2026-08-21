<?php

declare(strict_types=1);

namespace FamilyQuiz\Db;

use PDO;
use RuntimeException;

final class Migrator
{
    public static function migrate(PDO $pdo, string $kind): void
    {
        $dir = dirname(__DIR__) . '/migrations/' . $kind;
        if (!is_dir($dir)) {
            throw new RuntimeException("Unknown migration kind: {$kind}");
        }

        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        foreach ($files as $file) {
            $base = basename($file);
            if (!preg_match('/^(\d+)_/', $base, $m)) {
                continue;
            }
            $version = (int) $m[1];
            if ($version <= $current) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration: {$file}");
            }

            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $pdo->exec('PRAGMA user_version = ' . $version);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $current = $version;
        }
    }
}
