<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Repo\QuizzesRepo;
use FamilyQuiz\Repo\OptionsRepo;
use FamilyQuiz\Repo\ResultsRepo;
use FamilyQuiz\Repo\UsersRepo;
use ZipArchive;
use RuntimeException;

final class ExportService
{
    public function __construct(
        private ProjectsRepo $projects,
        private QuizzesRepo $quizzes,
        private OptionsRepo $options,
        private ResultsRepo $results,
        private UsersRepo $users,
    ) {}

    public function buildZip(string $projectId): string
    {
        $project = $this->projects->find($projectId);
        if (!$project) {
            throw new RuntimeException('NOT_FOUND');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fqexp');
        if ($tmp === false) {
            throw new RuntimeException('TEMP_FAILED');
        }
        $zipPath = $tmp . '.zip';
        rename($tmp, $zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP_FAILED');
        }

        $quizzes = $this->quizzes->listForProject($projectId);
        $projectJson = [
            'project' => $project,
            'description_html' => $this->projects->getMeta($projectId, 'description_html'),
            'quizzes' => array_map(function ($q) use ($projectId) {
                $q['options'] = $this->options->listForQuiz($projectId, $q['id']);
                return $q;
            }, $quizzes),
        ];
        $zip->addFromString('project.json', json_encode($projectJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $board = $this->results->leaderboard($projectId);
        $csv = "name,score,max_score,answered_count,correct_count,rank\n";
        foreach ($board as $row) {
            $csv .= sprintf(
                "%s,%d,%d,%d,%d,%d\n",
                $this->csv($row['name']),
                $row['score'],
                $row['max_score'],
                $row['answered_count'],
                $row['correct_count'],
                $row['rank']
            );
        }
        $zip->addFromString('results.csv', $csv);

        $answersCsv = "user_id,name,quiz_id,option_id,is_correct,answered_at\n";
        foreach ($this->users->listAll($projectId) as $user) {
            foreach ($this->results->userAnswers($projectId, $user['id']) as $a) {
                $answersCsv .= sprintf(
                    "%s,%s,%s,%s,%d,%s\n",
                    $this->csv($user['id']),
                    $this->csv($user['name_display']),
                    $this->csv($a['quiz_id']),
                    $this->csv((string) ($a['option_id'] ?? '')),
                    (int) $a['is_correct'],
                    $this->csv((string) ($a['answered_at'] ?? ''))
                );
            }
        }
        $zip->addFromString('answers.csv', $answersCsv);
        $zip->close();

        return $zipPath;
    }

    private function csv(string $v): string
    {
        return '"' . str_replace('"', '""', $v) . '"';
    }
}
