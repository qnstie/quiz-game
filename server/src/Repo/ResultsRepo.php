<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;

final class ResultsRepo
{
    public function leaderboard(string $projectId): array
    {
        $sql = 'SELECT ru.*, u.name_display AS name
                FROM results_user ru
                JOIN users u ON u.id = ru.user_id
                ORDER BY ru.rank ASC, u.created_at ASC';
        return Connections::projectDb($projectId)->query($sql)->fetchAll();
    }

    public function userResult(string $projectId, string $userId): ?array
    {
        $stmt = Connections::projectDb($projectId)->prepare(
            'SELECT ru.*, u.name_display AS name FROM results_user ru
             JOIN users u ON u.id = ru.user_id WHERE ru.user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function userAnswers(string $projectId, string $userId): array
    {
        $stmt = Connections::projectDb($projectId)->prepare(
            'SELECT * FROM results_answer WHERE user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchAll();
    }

    public function optionStats(string $projectId): array
    {
        return Connections::projectDb($projectId)
            ->query('SELECT * FROM results_option_stats')
            ->fetchAll();
    }

    public function optionStatsForQuiz(string $projectId, string $quizId): array
    {
        $stmt = Connections::projectDb($projectId)->prepare(
            'SELECT * FROM results_option_stats WHERE quiz_id = :q'
        );
        $stmt->execute(['q' => $quizId]);
        return $stmt->fetchAll();
    }
}
