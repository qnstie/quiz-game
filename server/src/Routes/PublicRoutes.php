<?php

declare(strict_types=1);

namespace FamilyQuiz\Routes;

use FamilyQuiz\Repo\AnswersRepo;
use FamilyQuiz\Repo\OptionsRepo;
use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Repo\QuizzesRepo;
use FamilyQuiz\Repo\ResultsRepo;
use FamilyQuiz\Repo\UsersRepo;
use FamilyQuiz\Services\AuthService;
use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\ConfigBag;
use FamilyQuiz\Support\JsonResponse;
use FamilyQuiz\Support\Names;
use FamilyQuiz\Support\SessionCookie;
use FamilyQuiz\Support\Shuffle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

final class PublicRoutes
{
    public static function register(App $app): void
    {
        $app->get('/health', function (): ResponseInterface {
            return JsonResponse::ok(['ok' => true]);
        });

        $app->get('/api/bootstrap', [self::class, 'bootstrap']);
        $app->post('/api/session/join', [self::class, 'join'])
            ->add(new \FamilyQuiz\Middleware\RateLimitMiddleware('join', 10, 60));
        $app->post('/api/session/leave', [self::class, 'leave']);
        $app->post('/api/session/reset-answers', [self::class, 'resetAnswers']);
        $app->get('/api/quizzes', [self::class, 'listQuizzes']);
        $app->get('/api/quizzes/{quizId}', [self::class, 'getQuiz']);
        $app->put('/api/answers/{quizId}', [self::class, 'putAnswer'])
            ->add(new \FamilyQuiz\Middleware\RateLimitMiddleware('answers', 120, 60));
        $app->get('/api/me/results', [self::class, 'myResults']);
        $app->get('/api/leaderboard', [self::class, 'leaderboard']);
        $app->get('/media/{projectId}/{file}', [self::class, 'serveMedia']);
    }

    public function bootstrap(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
    ): ResponseInterface {
        $projectId = $request->getAttribute('publicProjectId');
        $participant = $request->getAttribute('participant');

        if (!$projectId) {
            return JsonResponse::ok([
                'project' => null,
                'projects' => $projects->listPublicProjects(),
                'session' => null,
                'seedPasswordWarning' => false,
            ]);
        }

        $project = $projects->find($projectId);
        if (!$project || !\FamilyQuiz\Services\StateService::isParticipantVisible($project['state'] ?? null)) {
            // SETUP is not joinable — surface other live/test projects instead of a stuck blocked shell.
            return JsonResponse::ok([
                'project' => null,
                'projects' => $projects->listPublicProjects(),
                'session' => null,
            ]);
        }

        return JsonResponse::ok([
            'project' => [
                'id' => $project['id'],
                'slug' => $project['slug'],
                'title' => $project['title'],
                'description_html' => $projects->getMeta($projectId, 'description_html'),
                'state' => $project['state'],
                'require_pin' => (bool) $project['require_pin'],
                'shuffle_quizzes' => (bool) $project['shuffle_quizzes'],
            ],
            'session' => $participant ? [
                'displayName' => $participant['name_display'],
                'userId' => $participant['id'],
                'answersResetAt' => Connections::userDb($projectId, $participant['id'])
                    ->query("SELECT value FROM profile WHERE key = 'answers_reset_at'")
                    ->fetchColumn() ?: null,
            ] : null,
        ]);
    }

    public function join(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        UsersRepo $users,
        ConfigBag $config,
    ): ResponseInterface {
        $projectId = $request->getAttribute('publicProjectId');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'No public project', 404);
        }
        $project = $projects->find($projectId);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        if (!\FamilyQuiz\Services\StateService::isParticipantVisible($project['state'] ?? null)) {
            return JsonResponse::error('LOCKED', 'Quiz is being updated', 423, ['currentState' => $project['state']]);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = (string) ($body['name'] ?? '');
        $pin = isset($body['pin']) ? (string) $body['pin'] : null;

        if (!Names::isValid($name)) {
            return JsonResponse::error('VALIDATION', 'Invalid name', 400);
        }

        try {
            $result = $users->joinOrResume($projectId, $name, $pin, (bool) $project['require_pin']);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'INVALID_PIN') {
                return JsonResponse::error('FORBIDDEN', 'Invalid PIN', 403);
            }
            if ($code === 'PIN_REQUIRED') {
                return JsonResponse::error('VALIDATION', 'PIN required', 400);
            }
            throw $e;
        }

        $response = JsonResponse::ok([
            'displayName' => $result['user']['name_display'],
            'userId' => $result['user']['id'],
            'created' => $result['created'],
            'token' => $result['token'],
        ]);

        return self::setUserCookie($response, $result['token'], $config->all());
    }

    public function leave(ServerRequestInterface $request, ConfigBag $config): ResponseInterface
    {
        $response = JsonResponse::ok(['ok' => true]);
        return SessionCookie::apply(
            $response,
            SessionCookie::clearHeaders('fq_user', $config->all()),
        );
    }

    public function resetAnswers(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        AnswersRepo $answers,
    ): ResponseInterface {
        [$project, $user, $err] = $this->requireActiveParticipant($request, $projects);
        if ($err) {
            return $err;
        }
        if (!\FamilyQuiz\Services\StateService::isAnswerable($project['state'] ?? null)) {
            return JsonResponse::error('LOCKED', 'Answers closed', 423, ['currentState' => $project['state']]);
        }
        $cleared = $answers->clearAll($project['id'], $user['id']);
        return JsonResponse::ok(['ok' => true, 'cleared' => $cleared]);
    }

    public function listQuizzes(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        AnswersRepo $answers,
    ): ResponseInterface {
        [$project, $user, $err] = $this->requireActiveParticipant($request, $projects);
        if ($err) {
            return $err;
        }

        $list = $quizzes->listForProject($project['id']);
        if ((int) $project['shuffle_quizzes'] === 1) {
            $list = Shuffle::seededShuffle($list, (int) $user['shuffle_seed'], 'quizzes');
        }
        $map = $answers->getAnswersMap($project['id'], $user['id']);
        $items = array_map(static function ($q) use ($map) {
            return [
                'id' => $q['id'],
                'title' => $q['title'],
                'answered' => isset($map[$q['id']]),
            ];
        }, $list);

        $answeredCount = count(array_filter($items, static fn ($i) => $i['answered']));
        return JsonResponse::ok([
            'quizzes' => $items,
            'answeredCount' => $answeredCount,
            'total' => count($items),
        ]);
    }

    public function getQuiz(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        AnswersRepo $answers,
        string $quizId,
    ): ResponseInterface {
        [$project, $user, $err] = $this->requireActiveParticipant($request, $projects, allowClosedRead: true);
        if ($err) {
            return $err;
        }

        $quiz = $quizzes->find($project['id'], $quizId);
        if (!$quiz) {
            return JsonResponse::error('NOT_FOUND', 'Quiz not found', 404);
        }

        $opts = $options->listForQuiz($project['id'], $quizId);
        if ((int) $quiz['shuffle_options'] === 1) {
            // Re-index so JSON stays a dense array in shuffled display order
            // (and never accidentally re-sorts by original position keys).
            $opts = array_values(Shuffle::seededShuffle($opts, (int) $user['shuffle_seed'], $quizId));
        }

        $revealed = $project['state'] === 'REVEALED';
        $answer = $answers->getAnswer($project['id'], $user['id'], $quizId);

        $publicOpts = array_values(array_map(static function ($o) use ($revealed) {
            $row = [
                'id' => $o['id'],
                'label_html' => $o['label_html'],
            ];
            if ($revealed) {
                $row['is_correct'] = (bool) $o['is_correct'];
                $row['feedback_html'] = $o['feedback_html'];
            }
            return $row;
        }, $opts));

        $payload = [
            'id' => $quiz['id'],
            'title' => $quiz['title'],
            'description_html' => $quiz['description_html'],
            'options' => $publicOpts,
            'selectedOptionId' => $answer['option_id'] ?? null,
        ];
        if ($revealed) {
            $payload['explanation_html'] = $quiz['explanation_html'];
        }

        return JsonResponse::ok($payload);
    }

    public function putAnswer(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        AnswersRepo $answers,
        string $quizId,
    ): ResponseInterface {
        [$project, $user, $err] = $this->requireActiveParticipant($request, $projects);
        if ($err) {
            return $err;
        }
        if (!\FamilyQuiz\Services\StateService::isAnswerable($project['state'] ?? null)) {
            return JsonResponse::error('LOCKED', 'Answers closed', 423, ['currentState' => $project['state']]);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $optionId = (string) ($body['optionId'] ?? '');
        $quiz = $quizzes->find($project['id'], $quizId);
        if (!$quiz) {
            return JsonResponse::error('NOT_FOUND', 'Quiz not found', 404);
        }
        $opts = $options->listForQuiz($project['id'], $quizId);
        $ids = array_column($opts, 'id');
        if (!in_array($optionId, $ids, true)) {
            return JsonResponse::error('VALIDATION', 'Invalid option', 400);
        }

        $answers->upsert($project['id'], $user['id'], $quizId, $optionId);

        $list = $quizzes->listForProject($project['id']);
        if ((int) $project['shuffle_quizzes'] === 1) {
            $list = Shuffle::seededShuffle($list, (int) $user['shuffle_seed'], 'quizzes');
        }
        $idsOrdered = array_column($list, 'id');
        $idx = array_search($quizId, $idsOrdered, true);
        $next = null;
        if ($idx !== false && isset($idsOrdered[$idx + 1])) {
            $next = $idsOrdered[$idx + 1];
        }

        $map = $answers->getAnswersMap($project['id'], $user['id']);
        return JsonResponse::ok([
            'next' => $next,
            'answeredCount' => count($map),
            'total' => count($list),
        ]);
    }

    public function myResults(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        ResultsRepo $results,
    ): ResponseInterface {
        [$project, $user, $err] = $this->requireActiveParticipant($request, $projects, allowClosedRead: true);
        if ($err) {
            return $err;
        }
        if ($project['state'] !== 'REVEALED') {
            return JsonResponse::error('FORBIDDEN', 'Results not revealed', 403);
        }

        $summary = $results->userResult($project['id'], $user['id']);
        $answerRows = $results->userAnswers($project['id'], $user['id']);
        $byQuiz = [];
        foreach ($answerRows as $a) {
            $byQuiz[$a['quiz_id']] = $a;
        }

        $quizList = $quizzes->listForProject($project['id']);
        $detail = [];
        foreach ($quizList as $q) {
            $opts = $options->listForQuiz($project['id'], $q['id']);
            $a = $byQuiz[$q['id']] ?? null;
            $chosen = null;
            $correct = null;
            $feedback = '';
            foreach ($opts as $o) {
                if ((int) $o['is_correct'] === 1) {
                    $correct = $o;
                }
                if ($a && $a['option_id'] === $o['id']) {
                    $chosen = $o;
                    $feedback = $o['feedback_html'];
                }
            }
            $detail[] = [
                'quizId' => $q['id'],
                'title' => $q['title'],
                'description_html' => $q['description_html'],
                'explanation_html' => $q['explanation_html'],
                'chosenOptionId' => $a['option_id'] ?? null,
                'chosenLabelHtml' => $chosen['label_html'] ?? null,
                'correctOptionId' => $correct['id'] ?? null,
                'correctLabelHtml' => $correct['label_html'] ?? null,
                'isCorrect' => (bool) ($a['is_correct'] ?? false),
                'feedback_html' => $feedback,
            ];
        }

        return JsonResponse::ok([
            'summary' => $summary,
            'quizzes' => $detail,
        ]);
    }

    public function leaderboard(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        ResultsRepo $results,
    ): ResponseInterface {
        $projectId = $request->getAttribute('publicProjectId');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'No project', 404);
        }
        $project = $projects->find($projectId);
        if (!$project || $project['state'] !== 'REVEALED') {
            return JsonResponse::error('FORBIDDEN', 'Results not revealed', 403);
        }
        $board = array_map(static fn ($r) => [
            'name' => $r['name'],
            'score' => (int) $r['score'],
            'rank' => (int) $r['rank'],
        ], $results->leaderboard($projectId));
        return JsonResponse::ok(['leaderboard' => $board]);
    }

    public function serveMedia(
        ServerRequestInterface $request,
        string $projectId,
        string $file,
    ): ResponseInterface {
        if (!preg_match('/^[a-f0-9\-]+\.[a-z0-9]+$/i', $file)) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $base = \FamilyQuiz\Db\Connections::projectDir($projectId);
        $path = $base . '/uploads/' . $file;
        if (!is_file($path)) {
            // Legacy uploads stored under media/
            $path = $base . '/media/' . $file;
        }
        if (!is_file($path)) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: 'application/octet-stream';
        $response = new \Slim\Psr7\Response(200);
        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'public, max-age=31536000');
    }

    /** @return array{0: ?array, 1: ?array, 2: ?ResponseInterface} */
    private function requireActiveParticipant(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        bool $allowClosedRead = false,
    ): array {
        $projectId = $request->getAttribute('publicProjectId');
        $user = $request->getAttribute('participant');
        if (!$projectId) {
            return [null, null, JsonResponse::error('NOT_FOUND', 'No project', 404)];
        }
        $project = $projects->find($projectId);
        if (!$project) {
            return [null, null, JsonResponse::error('NOT_FOUND', 'Project not found', 404)];
        }
        if (!\FamilyQuiz\Services\StateService::isParticipantVisible($project['state'] ?? null)) {
            return [null, null, JsonResponse::error('LOCKED', 'Quiz is being updated', 423, ['currentState' => $project['state'] ?? 'SETUP'])];
        }
        if (!$user) {
            return [null, null, JsonResponse::error('UNAUTHENTICATED', 'Join first', 401)];
        }
        return [$project, $user, null];
    }

    private static function setUserCookie(ResponseInterface $response, string $token, array $config): ResponseInterface
    {
        return SessionCookie::apply(
            $response,
            SessionCookie::setHeaders('fq_user', $token, $config, 90 * 24 * 3600),
        );
    }
}
