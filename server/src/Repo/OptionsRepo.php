<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;
use InvalidArgumentException;

final class OptionsRepo
{
    public function listForQuiz(string $projectId, string $quizId): array
    {
        $stmt = Connections::projectDb($projectId)->prepare(
            'SELECT * FROM options WHERE quiz_id = :qid ORDER BY position ASC'
        );
        $stmt->execute(['qid' => $quizId]);
        return $stmt->fetchAll();
    }

    /**
     * Replace all four options atomically.
     *
     * @param list<array{id?: string, label_html: string, is_correct: bool|int, feedback_html?: string}> $options
     */
    public function replaceAll(string $projectId, string $quizId, array $options): array
    {
        if (count($options) !== 4) {
            throw new InvalidArgumentException('Exactly 4 options required');
        }
        $correct = 0;
        foreach ($options as $o) {
            if (!empty($o['is_correct'])) {
                $correct++;
            }
        }
        if ($correct !== 1) {
            throw new InvalidArgumentException('Exactly one option must be correct');
        }

        $pdo = Connections::projectDb($projectId);
        $now = Id::now();

        Connections::withBusyRetry(function () use ($pdo, $quizId, $options, $now) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $pdo->prepare('DELETE FROM options WHERE quiz_id = :qid')->execute(['qid' => $quizId]);
                $ins = $pdo->prepare(
                    'INSERT INTO options (id, quiz_id, position, label_html, is_correct, feedback_html, created_at, updated_at)
                     VALUES (:id, :quiz_id, :pos, :label, :correct, :feedback, :now, :now)'
                );
                foreach (array_values($options) as $i => $o) {
                    $ins->execute([
                        'id' => $o['id'] ?? Id::uuid(),
                        'quiz_id' => $quizId,
                        'pos' => $i,
                        'label' => $o['label_html'] ?? '',
                        'correct' => !empty($o['is_correct']) ? 1 : 0,
                        'feedback' => $o['feedback_html'] ?? '',
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

        return $this->listForQuiz($projectId, $quizId);
    }
}
