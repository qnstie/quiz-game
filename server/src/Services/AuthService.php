<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;

final class AuthService
{
    public function __construct(
        private array $config,
        private SeedService $seed,
    ) {}

    public function attemptLogin(string $email, string $password): ?array
    {
        return Connections::withBusyRetry(function () use ($email, $password) {
            $pdo = Connections::appDb();
            $stmt = $pdo->prepare(
                'SELECT * FROM superusers WHERE email = :email COLLATE NOCASE AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(['email' => trim($email)]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                return null;
            }

            // Opportunistic rehash if algo upgraded
            $preferred = $this->seed->preferredAlgo();
            if ($user['password_algo'] !== $preferred || password_needs_rehash($user['password_hash'], $this->algoConst($preferred))) {
                $newHash = $this->seed->hashPassword($password, $preferred);
                $upd = $pdo->prepare('UPDATE superusers SET password_hash = :h, password_algo = :a WHERE id = :id');
                $upd->execute(['h' => $newHash, 'a' => $preferred, 'id' => $user['id']]);
                $user['password_hash'] = $newHash;
                $user['password_algo'] = $preferred;
            }

            $pdo->prepare('UPDATE superusers SET last_login_at = :now WHERE id = :id')
                ->execute(['now' => Id::now(), 'id' => $user['id']]);

            return $user;
        });
    }

    public function issueAdminToken(array $user): string
    {
        $now = time();
        $payload = [
            'sub' => $user['id'],
            'email' => $user['email'],
            'role' => 'admin',
            'iat' => $now,
            'exp' => $now + 12 * 3600,
        ];
        return JWT::encode($payload, $this->config['jwt_secret'], 'HS256');
    }

    public function parseAdminToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->config['jwt_secret'], 'HS256'));
            $data = (array) $decoded;
            if (($data['role'] ?? '') !== 'admin') {
                return null;
            }
            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    public function findAdminById(string $id): ?array
    {
        $stmt = Connections::appDb()->prepare('SELECT * FROM superusers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findActiveAdminByEmail(string $email): ?array
    {
        $stmt = Connections::appDb()->prepare(
            'SELECT * FROM superusers WHERE email = :email COLLATE NOCASE AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['email' => trim($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findFirstActiveAdmin(): ?array
    {
        $row = Connections::appDb()
            ->query('SELECT * FROM superusers WHERE is_active = 1 ORDER BY created_at ASC LIMIT 1')
            ->fetch();
        return $row ?: null;
    }

    public function touchAdminLogin(string $id): void
    {
        Connections::appDb()
            ->prepare('UPDATE superusers SET last_login_at = :now WHERE id = :id')
            ->execute(['now' => Id::now(), 'id' => $id]);
    }

    public function createParticipantToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function algoConst(string $algo): string|int
    {
        if ($algo === 'argon2id' && defined('PASSWORD_ARGON2ID')) {
            return PASSWORD_ARGON2ID;
        }
        return PASSWORD_BCRYPT;
    }
}
