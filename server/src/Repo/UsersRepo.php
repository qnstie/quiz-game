<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;
use FamilyQuiz\Support\Names;
use FamilyQuiz\Services\AuthService;

final class UsersRepo
{
    public function __construct(private AuthService $auth) {}

    public function findByNameKey(string $projectId, string $nameKey): ?array
    {
        $stmt = Connections::projectDb($projectId)->prepare('SELECT * FROM users WHERE name_key = :k');
        $stmt->execute(['k' => $nameKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByTokenHash(string $projectId, string $tokenHash): ?array
    {
        $stmt = Connections::projectDb($projectId)->prepare('SELECT * FROM users WHERE token_hash = :h');
        $stmt->execute(['h' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function find(string $projectId, string $userId): ?array
    {
        $stmt = Connections::projectDb($projectId)->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id = $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listAll(string $projectId): array
    {
        return Connections::projectDb($projectId)
            ->query('SELECT * FROM users ORDER BY created_at ASC')
            ->fetchAll();
    }

    /**
     * @return array{user: array, token: string, created: bool}
     */
    public function joinOrResume(string $projectId, string $name, ?string $pin, bool $requirePin): array
    {
        $display = Names::normalizeDisplay($name);
        $key = Names::nameKey($display);
        $existing = $this->findByNameKey($projectId, $key);

        if ($existing) {
            if ($requirePin) {
                if ($pin === null || $existing['pin_hash'] === null || !password_verify($pin, $existing['pin_hash'])) {
                    throw new \RuntimeException('INVALID_PIN');
                }
            }
            $token = $this->auth->createParticipantToken();
            $hash = $this->auth->hashToken($token);
            $now = Id::now();
            Connections::projectDb($projectId)->prepare(
                'UPDATE users SET token_hash = :h, last_seen_at = :now, name_display = :display WHERE id = :id'
            )->execute(['h' => $hash, 'now' => $now, 'display' => $display, 'id' => $existing['id']]);

            $userPdo = Connections::userDb($projectId, $existing['id']);
            $userPdo->prepare('INSERT OR REPLACE INTO profile (key, value) VALUES (\'last_seen_at\', :v)')->execute(['v' => $now]);
            $userPdo->prepare('INSERT OR REPLACE INTO profile (key, value) VALUES (\'name_display\', :v)')->execute(['v' => $display]);
            $userPdo->prepare('INSERT INTO activity (at, kind, detail) VALUES (:at, \'resume\', NULL)')->execute(['at' => $now]);

            $user = $this->find($projectId, $existing['id']);
            return ['user' => $user, 'token' => $token, 'created' => false];
        }

        if ($requirePin && ($pin === null || !preg_match('/^\d{4}$/', $pin))) {
            throw new \RuntimeException('PIN_REQUIRED');
        }

        $userId = Id::uuid();
        $token = $this->auth->createParticipantToken();
        $hash = $this->auth->hashToken($token);
        $now = Id::now();
        $seed = random_int(1, 2_147_483_647);
        $rel = Connections::userDbRelativePath($projectId, $userId);
        $pinHash = $pin !== null ? password_hash($pin, PASSWORD_BCRYPT) : null;

        Connections::projectDb($projectId)->prepare(
            'INSERT INTO users (id, name_key, name_display, token_hash, pin_hash, shuffle_seed, db_path, created_at, last_seen_at)
             VALUES (:id, :nk, :nd, :th, :ph, :seed, :db, :now, :now)'
        )->execute([
            'id' => $userId,
            'nk' => $key,
            'nd' => $display,
            'th' => $hash,
            'ph' => $pinHash,
            'seed' => $seed,
            'db' => $rel,
            'now' => $now,
        ]);

        $userPdo = Connections::userDb($projectId, $userId);
        foreach ([
            'user_id' => $userId,
            'name_display' => $display,
            'created_at' => $now,
            'last_seen_at' => $now,
            'shuffle_seed' => (string) $seed,
        ] as $k => $v) {
            $userPdo->prepare('INSERT OR REPLACE INTO profile (key, value) VALUES (:k, :v)')->execute(['k' => $k, 'v' => $v]);
        }
        $userPdo->prepare('INSERT INTO activity (at, kind, detail) VALUES (:at, \'join\', NULL)')->execute(['at' => $now]);

        return ['user' => $this->find($projectId, $userId), 'token' => $token, 'created' => true];
    }

    public function touch(string $projectId, string $userId): void
    {
        $now = Id::now();
        Connections::projectDb($projectId)
            ->prepare('UPDATE users SET last_seen_at = :now WHERE id = :id')
            ->execute(['now' => $now, 'id' => $userId]);
    }

    public function delete(string $projectId, string $userId): void
    {
        $src = Connections::projectDir($projectId) . '/users/' . $userId . '.db';
        $trash = Connections::dataDir() . '/_trash';
        if (!is_dir($trash)) {
            mkdir($trash, 0750, true);
        }
        if (is_file($src)) {
            rename($src, $trash . '/' . $userId . '_' . time() . '.db');
        }
        Connections::projectDb($projectId)->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        Connections::clearCache();
    }
}
