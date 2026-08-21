<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;

final class AnswersRepo
{
    public function getAnswersMap(string $projectId, string $userId): array
    {
        $rows = Connections::userDb($projectId, $userId)
            ->query('SELECT quiz_id, option_id, answered_at, updated_at, change_count FROM answers')
            ->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['quiz_id']] = $r;
        }
        return $map;
    }

    public function getAnswer(string $projectId, string $userId, string $quizId): ?array
    {
        $stmt = Connections::userDb($projectId, $userId)->prepare('SELECT * FROM answers WHERE quiz_id = :q');
        $stmt->execute(['q' => $quizId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function upsert(string $projectId, string $userId, string $quizId, string $optionId): array
    {
        $pdo = Connections::userDb($projectId, $userId);
        $existing = $this->getAnswer($projectId, $userId, $quizId);
        $now = Id::now();

        if ($existing) {
            $pdo->prepare(
                'UPDATE answers SET option_id = :o, updated_at = :now, change_count = change_count + 1 WHERE quiz_id = :q'
            )->execute(['o' => $optionId, 'now' => $now, 'q' => $quizId]);
            $pdo->prepare('INSERT INTO activity (at, kind, detail) VALUES (:at, \'change\', :d)')
                ->execute(['at' => $now, 'd' => $quizId]);
        } else {
            $pdo->prepare(
                'INSERT INTO answers (quiz_id, option_id, answered_at, updated_at, change_count)
                 VALUES (:q, :o, :now, :now, 0)'
            )->execute(['q' => $quizId, 'o' => $optionId, 'now' => $now]);
            $pdo->prepare('INSERT INTO activity (at, kind, detail) VALUES (:at, \'answer\', :d)')
                ->execute(['at' => $now, 'd' => $quizId]);
        }

        return $this->getAnswer($projectId, $userId, $quizId);
    }

    public function answeredCount(string $projectId, string $userId): int
    {
        return (int) Connections::userDb($projectId, $userId)
            ->query('SELECT COUNT(*) FROM answers')
            ->fetchColumn();
    }
}
