<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;
use PDO;

final class SeedService
{
    public function ensureSeedAdmin(array $config): void
    {
        $pdo = Connections::appDb();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM superusers')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $algo = defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';
        $hash = $this->hashPassword($config['seed_admin_password'], $algo);

        $stmt = $pdo->prepare(
            'INSERT INTO superusers (id, email, password_hash, password_algo, display_name, is_active, created_at)
             VALUES (:id, :email, :hash, :algo, :name, 1, :now)'
        );
        $stmt->execute([
            'id' => Id::uuid(),
            'email' => $config['seed_admin_email'],
            'hash' => $hash,
            'algo' => $algo,
            'name' => 'Admin',
            'now' => Id::now(),
        ]);
    }

    public function seedPasswordStillInUse(array $config): bool
    {
        $pdo = Connections::appDb();
        $stmt = $pdo->prepare(
            'SELECT password_hash FROM superusers WHERE email = :email COLLATE NOCASE AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['email' => $config['seed_admin_email']]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        $seedPassword = (string) ($config['seed_admin_password'] ?? '');
        if ($seedPassword === '') {
            return false;
        }
        return password_verify($seedPassword, $hash);
    }

    public function hashPassword(string $password, string $algo): string
    {
        if ($algo === 'argon2id' && defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 19456,
                'time_cost' => 4,
                'threads' => 1,
            ]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function preferredAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';
    }
}
