<?php

declare(strict_types=1);

namespace FamilyQuiz\Db;

use PDO;
use PDOException;
use RuntimeException;

final class Connections
{
    private static ?array $config = null;

    /** @var array<string, PDO> */
    private static array $cache = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$cache = [];
    }

    public static function config(): array
    {
        if (self::$config === null) {
            throw new RuntimeException('Connections::configure() must be called first');
        }
        return self::$config;
    }

    public static function dataDir(): string
    {
        $dir = self::config()['data_dir'];
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data_dir: {$dir}");
        }
        return $dir;
    }

    public static function appDb(): PDO
    {
        $path = self::dataDir() . '/app.db';
        return self::openCached('app', $path, 'app');
    }

    public static function projectDb(string $projectId): PDO
    {
        $path = self::projectDir($projectId) . '/project.db';
        return self::openCached("project:{$projectId}", $path, 'project');
    }

    public static function userDb(string $projectId, string $userId): PDO
    {
        $usersDir = self::projectDir($projectId) . '/users';
        if (!is_dir($usersDir) && !mkdir($usersDir, 0750, true) && !is_dir($usersDir)) {
            throw new RuntimeException("Cannot create users dir: {$usersDir}");
        }
        $path = $usersDir . '/' . $userId . '.db';
        return self::openCached("user:{$projectId}:{$userId}", $path, 'user');
    }

    public static function projectDir(string $projectId): string
    {
        $dir = self::dataDir() . '/projects/' . $projectId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create project dir: {$dir}");
        }
        $media = $dir . '/media';
        if (!is_dir($media) && !mkdir($media, 0750, true) && !is_dir($media)) {
            throw new RuntimeException("Cannot create media dir: {$media}");
        }
        return $dir;
    }

    public static function projectDbRelativePath(string $projectId): string
    {
        return 'projects/' . $projectId . '/project.db';
    }

    public static function userDbRelativePath(string $projectId, string $userId): string
    {
        return 'projects/' . $projectId . '/users/' . $userId . '.db';
    }

    /**
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function withBusyRetry(callable $fn, ?PDO $pdo = null): mixed
    {
        $attempts = 0;
        $last = null;
        while ($attempts < 3) {
            try {
                return $fn($pdo ?? self::appDb());
            } catch (PDOException $e) {
                $last = $e;
                if (!str_contains($e->getMessage(), 'database is locked') && ($e->errorInfo[1] ?? null) !== 5) {
                    throw $e;
                }
                $attempts++;
                usleep(50_000);
            }
        }
        throw $last ?? new RuntimeException('SQLite busy retry exhausted');
    }

    private static function openCached(string $key, string $path, string $kind): PDO
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $pdo = self::open($path);
        Migrator::migrate($pdo, $kind);
        self::$cache[$key] = $pdo;
        return $pdo;
    }

    public static function open(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = DELETE');
        return $pdo;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
