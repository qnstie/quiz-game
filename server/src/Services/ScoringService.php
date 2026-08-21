<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Support\Id;
use PDO;
use RuntimeException;

/**
 * Scoring reads each participant DB via a short-lived PDO (not ATTACH).
 * ATTACH/DETACH under PDO + DELETE journal proved unreliable in practice;
 * semantics match the plan (unanswered → null option, deleted option → not correct).
 */
final class ScoringService
{
    public function __construct(private ProjectsRepo $projects) {}

    public function computeResults(string $projectId): void
    {
        $lockPath = Connections::projectDir($projectId) . '/.scoring.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Cannot open scoring lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('SCORING_IN_PROGRESS');
            }

            Connections::clearCache();
            $pdo = Connections::projectDb($projectId);
            $users = $pdo->query('SELECT id, db_path, created_at FROM users ORDER BY created_at ASC')->fetchAll();
            $quizzes = $pdo->query('SELECT id, points FROM quizzes')->fetchAll();
            $correctByOption = [];
            foreach ($pdo->query('SELECT id, is_correct FROM options')->fetchAll() as $o) {
                $correctByOption[$o['id']] = (int) $o['is_correct'] === 1;
            }
            $maxScore = (int) array_sum(array_column($quizzes, 'points'));
            $now = Id::now();

            Connections::withBusyRetry(function () use ($pdo, $users, $quizzes, $correctByOption, $maxScore, $now) {
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    $pdo->exec('DELETE FROM results_option_stats');
                    $pdo->exec('DELETE FROM results_answer');
                    $pdo->exec('DELETE FROM results_user');

                    $ins = $pdo->prepare(
                        'INSERT INTO results_answer (user_id, quiz_id, option_id, is_correct, answered_at)
                         VALUES (:user_id, :quiz_id, :option_id, :is_correct, :answered_at)'
                    );

                    foreach ($users as $user) {
                        $answers = $this->readAnswers(Connections::dataDir() . '/' . $user['db_path']);
                        foreach ($quizzes as $q) {
                            $a = $answers[$q['id']] ?? null;
                            $optionId = $a['option_id'] ?? null;
                            $isCorrect = ($optionId !== null && ($correctByOption[$optionId] ?? false)) ? 1 : 0;
                            $ins->execute([
                                'user_id' => $user['id'],
                                'quiz_id' => $q['id'],
                                'option_id' => $optionId,
                                'is_correct' => $isCorrect,
                                'answered_at' => $a['answered_at'] ?? null,
                            ]);
                        }
                    }

                    $pdo->prepare(
                        'INSERT INTO results_user (user_id, score, max_score, answered_count, correct_count, rank, computed_at)
                         SELECT ra.user_id,
                                SUM(CASE WHEN ra.is_correct = 1 THEN q.points ELSE 0 END),
                                :maxScore,
                                SUM(CASE WHEN ra.option_id IS NOT NULL THEN 1 ELSE 0 END),
                                SUM(ra.is_correct),
                                0,
                                :now
                         FROM results_answer ra
                         JOIN quizzes q ON q.id = ra.quiz_id
                         GROUP BY ra.user_id'
                    )->execute(['maxScore' => $maxScore, 'now' => $now]);

                    $ranked = $pdo->query(
                        'SELECT ru.user_id, ru.score, ru.answered_count, u.created_at
                         FROM results_user ru
                         JOIN users u ON u.id = ru.user_id
                         ORDER BY ru.score DESC, ru.answered_count DESC, u.created_at ASC'
                    )->fetchAll();

                    $rank = 0;
                    $prevKey = null;
                    $upd = $pdo->prepare('UPDATE results_user SET rank = :r WHERE user_id = :id');
                    foreach ($ranked as $row) {
                        $key = $row['score'] . ':' . $row['answered_count'];
                        if ($key !== $prevKey) {
                            $rank++;
                            $prevKey = $key;
                        }
                        $upd->execute(['r' => $rank, 'id' => $row['user_id']]);
                    }

                    $pdo->exec(
                        'INSERT INTO results_option_stats (quiz_id, option_id, pick_count)
                         SELECT quiz_id, option_id, COUNT(*)
                         FROM results_answer
                         WHERE option_id IS NOT NULL
                         GROUP BY quiz_id, option_id'
                    );

                    $pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (\'results_computed_at\', :v)')
                        ->execute(['v' => $now]);

                    $pdo->exec('COMMIT');
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }, $pdo);

            $this->projects->update($projectId, ['results_stale' => 0]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, array{option_id: string, answered_at: string}> */
    private function readAnswers(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $userPdo = Connections::open($path);
        try {
            $rows = $userPdo->query('SELECT quiz_id, option_id, answered_at FROM answers')->fetchAll();
            $map = [];
            foreach ($rows as $r) {
                $map[$r['quiz_id']] = [
                    'option_id' => $r['option_id'],
                    'answered_at' => $r['answered_at'],
                ];
            }
            return $map;
        } finally {
            $userPdo = null;
        }
    }
}
