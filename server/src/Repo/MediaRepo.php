<?php

declare(strict_types=1);

namespace FamilyQuiz\Repo;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\Id;

final class MediaRepo
{
    public function list(string $projectId): array
    {
        return Connections::projectDb($projectId)
            ->query('SELECT * FROM media ORDER BY created_at DESC')
            ->fetchAll();
    }

    public function find(string $projectId, string $id): ?array
    {
        $stmt = Connections::projectDb($projectId)->prepare('SELECT * FROM media WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $projectId, array $row): array
    {
        Connections::projectDb($projectId)->prepare(
            'INSERT INTO media (id, filename, stored_path, mime, bytes, width, height, duration_s, uploaded_by, created_at)
             VALUES (:id, :filename, :stored_path, :mime, :bytes, :width, :height, :duration_s, :uploaded_by, :created_at)'
        )->execute([
            'id' => $row['id'],
            'filename' => $row['filename'],
            'stored_path' => $row['stored_path'],
            'mime' => $row['mime'],
            'bytes' => $row['bytes'],
            'width' => $row['width'] ?? null,
            'height' => $row['height'] ?? null,
            'duration_s' => $row['duration_s'] ?? null,
            'uploaded_by' => $row['uploaded_by'] ?? null,
            'created_at' => $row['created_at'] ?? Id::now(),
        ]);
        return $this->find($projectId, $row['id']);
    }

    public function delete(string $projectId, string $id): void
    {
        $row = $this->find($projectId, $id);
        if (!$row) {
            return;
        }
        $abs = Connections::projectDir($projectId) . '/' . $row['stored_path'];
        if (is_file($abs)) {
            unlink($abs);
        }
        Connections::projectDb($projectId)->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $id]);
    }

    public function isReferenced(string $projectId, string $mediaId): bool
    {
        $needle = '/media/' . $mediaId;
        $pdo = Connections::projectDb($projectId);
        $checks = [
            "SELECT COUNT(*) FROM quizzes WHERE description_html LIKE :n OR explanation_html LIKE :n",
            "SELECT COUNT(*) FROM options WHERE label_html LIKE :n OR feedback_html LIKE :n",
            "SELECT COUNT(*) FROM meta WHERE value LIKE :n",
        ];
        foreach ($checks as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['n' => '%' . $needle . '%']);
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }
}
