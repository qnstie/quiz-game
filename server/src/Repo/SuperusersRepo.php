<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;
use PDO;

final class SuperusersRepo
{
    public function listAll(): array
    {
        return Connections::appDb()
            ->query('SELECT id, email, display_name, is_active, created_at, last_login_at, password_algo FROM superusers ORDER BY email')
            ->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = Connections::appDb()->prepare(
            'SELECT id, email, display_name, is_active, created_at, last_login_at, password_algo, password_hash FROM superusers WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function countActive(): int
    {
        return (int) Connections::appDb()->query('SELECT COUNT(*) FROM superusers WHERE is_active = 1')->fetchColumn();
    }

    public function create(string $email, string $passwordHash, string $algo, ?string $displayName): array
    {
        $id = Id::uuid();
        Connections::appDb()->prepare(
            'INSERT INTO superusers (id, email, password_hash, password_algo, display_name, is_active, created_at)
             VALUES (:id, :email, :hash, :algo, :name, 1, :now)'
        )->execute([
            'id' => $id,
            'email' => trim($email),
            'hash' => $passwordHash,
            'algo' => $algo,
            'name' => $displayName,
            'now' => Id::now(),
        ]);
        return $this->publicView($this->find($id));
    }

    public function update(string $id, array $fields): ?array
    {
        $allowed = ['email', 'display_name', 'is_active', 'password_hash', 'password_algo'];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = :{$key}";
                $params[$key] = $fields[$key];
            }
        }
        if ($sets === []) {
            return $this->publicView($this->find($id));
        }
        $sql = 'UPDATE superusers SET ' . implode(', ', $sets) . ' WHERE id = :id';
        Connections::appDb()->prepare($sql)->execute($params);
        return $this->publicView($this->find($id));
    }

    public function delete(string $id): void
    {
        Connections::appDb()->prepare('DELETE FROM superusers WHERE id = :id')->execute(['id' => $id]);
    }

    public function publicView(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        unset($row['password_hash']);
        return $row;
    }
}
