<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;
use PDO;

final class QuizzesRepo
{
    public function listForProject(string $projectId): array
    {
        return Connections::projectDb($projectId)
            ->query('SELECT * FROM quizzes ORDER BY position ASC, created_at ASC')
            ->fetchAll();
    }

    public function find(string $projectId, string $quizId): ?array
    {
        $stmt = Connections::projectDb($projectId)->prepare('SELECT * FROM quizzes WHERE id = :id');
        $stmt->execute(['id' => $quizId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $projectId, string $title = 'Untitled quiz'): array
    {
        $pdo = Connections::projectDb($projectId);
        $maxPos = (int) $pdo->query('SELECT COALESCE(MAX(position), -1) FROM quizzes')->fetchColumn();
        $quizId = Id::uuid();
        $now = Id::now();

        Connections::withBusyRetry(function () use ($pdo, $quizId, $title, $maxPos, $now) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $pdo->prepare(
                    'INSERT INTO quizzes (id, position, title, description_html, explanation_html, points, shuffle_options, created_at, updated_at)
                     VALUES (:id, :pos, :title, \'\', \'\', 1, 1, :now, :now)'
                )->execute([
                    'id' => $quizId,
                    'pos' => $maxPos + 1,
                    'title' => $title,
                    'now' => $now,
                ]);

                for ($i = 0; $i < 4; $i++) {
                    $pdo->prepare(
                        'INSERT INTO options (id, quiz_id, position, label_html, is_correct, feedback_html, created_at, updated_at)
                         VALUES (:id, :quiz_id, :pos, \'\', :correct, \'\', :now, :now)'
                    )->execute([
                        'id' => Id::uuid(),
                        'quiz_id' => $quizId,
                        'pos' => $i,
                        'correct' => $i === 0 ? 1 : 0,
                        'now' => $now,
                    ]);
                }
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }, $pdo);

        return $this->find($projectId, $quizId);
    }

    public function update(string $projectId, string $quizId, array $fields): ?array
    {
        $allowed = ['title', 'description_html', 'explanation_html', 'points', 'shuffle_options', 'position'];
        $sets = [];
        $params = ['id' => $quizId, 'updated_at' => Id::now()];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = :{$key}";
                $params[$key] = $fields[$key];
            }
        }
        if ($sets === []) {
            return $this->find($projectId, $quizId);
        }
        $sets[] = 'updated_at = :updated_at';
        $sql = 'UPDATE quizzes SET ' . implode(', ', $sets) . ' WHERE id = :id';
        Connections::projectDb($projectId)->prepare($sql)->execute($params);
        return $this->find($projectId, $quizId);
    }

    public function delete(string $projectId, string $quizId): void
    {
        Connections::projectDb($projectId)
            ->prepare('DELETE FROM quizzes WHERE id = :id')
            ->execute(['id' => $quizId]);
    }

    public function reorder(string $projectId, array $orderedIds): void
    {
        $pdo = Connections::projectDb($projectId);
        Connections::withBusyRetry(function () use ($pdo, $orderedIds) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $stmt = $pdo->prepare('UPDATE quizzes SET position = :pos, updated_at = :now WHERE id = :id');
                foreach (array_values($orderedIds) as $i => $id) {
                    $stmt->execute(['pos' => $i, 'now' => Id::now(), 'id' => $id]);
                }
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }, $pdo);
    }
}
