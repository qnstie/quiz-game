<?php

declare(strict_types=1);

namespace FamilyQuiz\Tests;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Repo\OptionsRepo;
use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Repo\QuizzesRepo;
use FamilyQuiz\Repo\UsersRepo;
use FamilyQuiz\Services\AuthService;
use FamilyQuiz\Services\ScoringService;
use FamilyQuiz\Services\SeedService;
use FamilyQuiz\Support\Id;
use PHPUnit\Framework\TestCase;

final class ScoringTest extends TestCase
{
    private string $dataDir;
    private string $projectId;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/fq-test-' . bin2hex(random_bytes(4));
        mkdir($this->dataDir, 0700, true);
        Connections::configure([
            'data_dir' => $this->dataDir,
            'jwt_secret' => str_repeat('s', 40),
        ]);
        Connections::clearCache();
        Connections::appDb();

        $projects = new ProjectsRepo();
        $project = $projects->create('Test', 'test-' . substr(Id::uuid(), 0, 8));
        $this->projectId = $project['id'];
        $projects->setSetting('active_project_id', $this->projectId);
        $projects->setSetting('public_project_id', $this->projectId);
    }

    protected function tearDown(): void
    {
        Connections::clearCache();
        $this->rmTree($this->dataDir);
    }

    public function testAllCorrectAndTies(): void
    {
        $quizzes = new QuizzesRepo();
        $options = new OptionsRepo();
        $q1 = $quizzes->create($this->projectId, 'Q1');
        $q2 = $quizzes->create($this->projectId, 'Q2');

        $opts1 = $options->listForQuiz($this->projectId, $q1['id']);
        $opts2 = $options->listForQuiz($this->projectId, $q2['id']);
        $correct1 = $opts1[0]['id'];
        $correct2 = $opts2[0]['id'];
        $wrong2 = $opts2[1]['id'];

        $auth = new AuthService(['jwt_secret' => str_repeat('s', 40)], new SeedService());
        $users = new UsersRepo($auth);
        $a = $users->joinOrResume($this->projectId, 'Alice', null, false);
        $b = $users->joinOrResume($this->projectId, 'Bob', null, false);

        $answers = new \FamilyQuiz\Repo\AnswersRepo();
        $answers->upsert($this->projectId, $a['user']['id'], $q1['id'], $correct1);
        $answers->upsert($this->projectId, $a['user']['id'], $q2['id'], $correct2);
        $answers->upsert($this->projectId, $b['user']['id'], $q1['id'], $correct1);
        $answers->upsert($this->projectId, $b['user']['id'], $q2['id'], $wrong2);

        $scoring = new ScoringService(new ProjectsRepo());
        $scoring->computeResults($this->projectId);

        $results = new \FamilyQuiz\Repo\ResultsRepo();
        $board = $results->leaderboard($this->projectId);
        $byName = [];
        foreach ($board as $row) {
            $byName[$row['name']] = $row;
        }
        $this->assertSame(2, (int) $byName['Alice']['score']);
        $this->assertSame(1, (int) $byName['Bob']['score']);
        $this->assertSame(1, (int) $byName['Alice']['rank']);
        $this->assertSame(2, (int) $byName['Bob']['rank']);
    }

    public function testDeletedOptionScoresZero(): void
    {
        $quizzes = new QuizzesRepo();
        $options = new OptionsRepo();
        $q1 = $quizzes->create($this->projectId, 'Q1');
        $opts = $options->listForQuiz($this->projectId, $q1['id']);
        $ghostId = Id::uuid();

        $auth = new AuthService(['jwt_secret' => str_repeat('s', 40)], new SeedService());
        $users = new UsersRepo($auth);
        $a = $users->joinOrResume($this->projectId, 'Carol', null, false);
        $answers = new \FamilyQuiz\Repo\AnswersRepo();
        $answers->upsert($this->projectId, $a['user']['id'], $q1['id'], $ghostId);

        $scoring = new ScoringService(new ProjectsRepo());
        $scoring->computeResults($this->projectId);

        $results = new \FamilyQuiz\Repo\ResultsRepo();
        $summary = $results->userResult($this->projectId, $a['user']['id']);
        $this->assertSame(0, (int) $summary['score']);
        $ans = $results->userAnswers($this->projectId, $a['user']['id']);
        $this->assertSame(0, (int) $ans[0]['is_correct']);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
