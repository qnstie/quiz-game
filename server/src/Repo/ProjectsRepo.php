<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;
use PDO;

final class ProjectsRepo
{
    public function listAll(): array
    {
        return Connections::appDb()
            ->query('SELECT * FROM projects ORDER BY created_at DESC')
            ->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = Connections::appDb()->prepare('SELECT * FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = Connections::appDb()->prepare('SELECT * FROM projects WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $title, string $slug): array
    {
        $id = Id::uuid();
        $now = Id::now();
        $rel = Connections::projectDbRelativePath($id);

        Connections::withBusyRetry(function (PDO $pdo) use ($id, $title, $slug, $rel, $now) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO projects (id, slug, title, state, db_path, shuffle_quizzes, require_pin, results_stale, created_at, updated_at)
                     VALUES (:id, :slug, :title, \'SETUP\', :db_path, 0, 0, 0, :now, :now)'
                );
                $stmt->execute([
                    'id' => $id,
                    'slug' => $slug,
                    'title' => $title,
                    'db_path' => $rel,
                    'now' => $now,
                ]);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });

        // Create project DB + meta
        $projectPdo = Connections::projectDb($id);
        $ins = $projectPdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (:k, :v)');
        $ins->execute(['k' => 'title', 'v' => $title]);
        $ins->execute(['k' => 'description_html', 'v' => '']);
        $ins->execute(['k' => 'schema_version', 'v' => '1']);

        return $this->find($id);
    }

    public function update(string $id, array $fields): ?array
    {
        $allowed = ['title', 'slug', 'shuffle_quizzes', 'require_pin', 'state', 'results_stale', 'closed_at', 'revealed_at'];
        $sets = [];
        $params = ['id' => $id, 'updated_at' => Id::now()];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = :{$key}";
                $params[$key] = $fields[$key];
            }
        }
        if (array_key_exists('description_html', $fields)) {
            Connections::projectDb($id)
                ->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (\'description_html\', :v)')
                ->execute(['v' => $fields['description_html']]);
        }

        if ($sets === []) {
            return $this->find($id);
        }
        $sets[] = 'updated_at = :updated_at';
        $sql = 'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = :id';
        Connections::appDb()->prepare($sql)->execute($params);

        if (isset($fields['title'])) {
            Connections::projectDb($id)
                ->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (\'title\', :v)')
                ->execute(['v' => $fields['title']]);
        }

        return $this->find($id);
    }

    public function softDelete(string $id): void
    {
        $src = Connections::projectDir($id);
        $trashRoot = Connections::dataDir() . '/_trash';
        if (!is_dir($trashRoot)) {
            mkdir($trashRoot, 0750, true);
        }
        $dest = $trashRoot . '/' . $id . '_' . time();
        if (is_dir($src)) {
            rename($src, $dest);
        }
        Connections::appDb()->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $id]);
        Connections::clearCache();
    }

    public function getSetting(string $key): ?string
    {
        $stmt = Connections::appDb()->prepare('SELECT value FROM app_settings WHERE key = :k');
        $stmt->execute(['k' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setSetting(string $key, ?string $value): void
    {
        if ($value === null) {
            Connections::appDb()->prepare('DELETE FROM app_settings WHERE key = :k')->execute(['k' => $key]);
            return;
        }
        Connections::appDb()
            ->prepare('INSERT INTO app_settings (key, value) VALUES (:k, :v)
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute(['k' => $key, 'v' => $value]);
    }

    public function getMeta(string $projectId, string $key, string $default = ''): string
    {
        $stmt = Connections::projectDb($projectId)->prepare('SELECT value FROM meta WHERE key = :k');
        $stmt->execute(['k' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    public function listPublicProjects(): array
    {
        return Connections::appDb()
            ->query("SELECT id, slug, title, state FROM projects WHERE state != 'SETUP' ORDER BY title")
            ->fetchAll();
    }
}
