<?php

declare(strict_types=1);

namespace FamilyQuiz\Routes;

use FamilyQuiz\Middleware\AgentTokenMiddleware;
use FamilyQuiz\Middleware\RateLimitMiddleware;
use FamilyQuiz\Repo\MediaRepo;
use FamilyQuiz\Repo\OptionsRepo;
use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Repo\QuizzesRepo;
use FamilyQuiz\Services\LockedException;
use FamilyQuiz\Services\MediaService;
use FamilyQuiz\Services\SanitizerService;
use FamilyQuiz\Services\StateService;
use FamilyQuiz\Support\ConfigBag;
use FamilyQuiz\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Machine-friendly content API for LLMs / automation.
 * Auth: Bearer admin_magic_token (or agent_api_token if set).
 * Mutations require the project to be in SETUP or TEST.
 */
final class AgentRoutes
{
    public static function register(App $app, \FamilyQuiz\Support\ConfigBag $config): void
    {
        $auth = new AgentTokenMiddleware($config);
        $app->group('/api/agent', function (RouteCollectorProxy $group) {
            $group->get('', [self::class, 'help']);
            $group->get('/', [self::class, 'help']);

            $group->get('/projects', [self::class, 'listProjects']);
            $group->get('/projects/{id}', [self::class, 'getProject']);
            $group->get('/projects/{id}/quizzes', [self::class, 'listQuizzes']);
            $group->post('/projects/{id}/quizzes', [self::class, 'createQuiz']);
            $group->get('/projects/{id}/media', [self::class, 'listMedia']);
            $group->post('/projects/{id}/media', [self::class, 'uploadMedia']);

            $group->get('/quizzes/{quizId}', [self::class, 'getQuiz']);
            $group->patch('/quizzes/{quizId}', [self::class, 'patchQuiz']);
            $group->put('/quizzes/{quizId}/options', [self::class, 'putOptions']);
        })
            ->add($auth)
            ->add(new RateLimitMiddleware('agent-api', 180, 3600));
    }

    public function help(): ResponseInterface
    {
        return JsonResponse::ok([
            'name' => 'Family Quiz Agent API',
            'auth' => [
                'Authorization' => 'Bearer <admin_magic_token>',
                'alternatives' => ['X-Agent-Token: <token>', '?t=<token>'],
                'note' => 'Uses config agent_api_token when set, otherwise admin_magic_token (min 32 chars).',
            ],
            'notes' => [
                'Quiz content HTML is sanitized (images, basic formatting, allowlisted embeds).',
                'Each quiz has exactly 4 options; exactly one must be is_correct=true.',
                'feedback_html is optional. Empty option labels mark a quiz incomplete.',
                'Writes require project state SETUP or TEST (423 LOCKED otherwise).',
                'Embed uploaded media with <img src="{url}"> (or audio/video tags) in HTML fields.',
            ],
            'endpoints' => [
                ['GET', '/api/agent', 'This help document'],
                ['GET', '/api/agent/projects', 'List projects (id, title, slug, state)'],
                ['GET', '/api/agent/projects/{id}', 'Project detail'],
                ['GET', '/api/agent/projects/{id}/quizzes', 'List quizzes; ?incomplete=1 filters incomplete'],
                ['POST', '/api/agent/projects/{id}/quizzes', 'Create quiz (optional full body)'],
                ['GET', '/api/agent/quizzes/{quizId}', 'Get quiz + options + completeness'],
                ['PATCH', '/api/agent/quizzes/{quizId}', 'Update fields and/or merge options'],
                ['PUT', '/api/agent/quizzes/{quizId}/options', 'Replace all 4 options'],
                ['GET', '/api/agent/projects/{id}/media', 'List media URLs for embedding'],
                ['POST', '/api/agent/projects/{id}/media', 'Upload multipart file field "file"'],
            ],
            'create_or_patch_body' => [
                'title' => 'string',
                'description_html' => 'string (HTML)',
                'explanation_html' => 'string (HTML)',
                'points' => 'number (default 1)',
                'shuffle_options' => 'bool|0|1',
                'options' => [
                    [
                        'id' => 'optional existing option uuid (for merge)',
                        'label_html' => 'string',
                        'is_correct' => 'bool',
                        'feedback_html' => 'string optional',
                    ],
                    '… exactly 4 when replacing; fewer merges by id or index',
                ],
            ],
            'example_create' => [
                'title' => 'Who invented the telephone?',
                'description_html' => '<p>Pick the best answer.</p>',
                'explanation_html' => '<p>Alexander Graham Bell is credited in 1876.</p>',
                'options' => [
                    ['label_html' => 'Alexander Graham Bell', 'is_correct' => true, 'feedback_html' => 'Correct!'],
                    ['label_html' => 'Thomas Edison', 'is_correct' => false],
                    ['label_html' => 'Nikola Tesla', 'is_correct' => false],
                    ['label_html' => 'Guglielmo Marconi', 'is_correct' => false],
                ],
            ],
        ]);
    }

    public function listProjects(ProjectsRepo $projects): ResponseInterface
    {
        $out = [];
        foreach ($projects->listAll() as $p) {
            $out[] = self::projectSummary($p);
        }
        return JsonResponse::ok([
            'projects' => $out,
            'activeProjectId' => $projects->getSetting('active_project_id'),
        ]);
    }

    public function getProject(ProjectsRepo $projects, string $id): ResponseInterface
    {
        $p = $projects->find($id);
        if (!$p) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        return JsonResponse::ok(['project' => self::projectSummary($p)]);
    }

    public function listQuizzes(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        string $id,
    ): ResponseInterface {
        if (!$projects->find($id)) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        $incompleteOnly = in_array(
            strtolower((string) ($request->getQueryParams()['incomplete'] ?? '')),
            ['1', 'true', 'yes'],
            true,
        );
        $list = [];
        foreach ($quizzes->listForProject($id) as $q) {
            $opts = $options->listForQuiz($id, $q['id']);
            $row = self::quizPayload($q, $opts);
            if ($incompleteOnly && $row['completeness']['complete']) {
                continue;
            }
            $list[] = $row;
        }
        return JsonResponse::ok(['projectId' => $id, 'quizzes' => $list]);
    }

    public function createQuiz(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        SanitizerService $sanitizer,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Project must be in SETUP or TEST to edit', 423, [
                'currentState' => $e->currentState,
            ]);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $title = trim((string) ($body['title'] ?? 'Untitled quiz'));
        if ($title === '') {
            $title = 'Untitled quiz';
        }
        $quiz = $quizzes->create($id, $title);
        try {
            $quiz = $this->applyQuizBody($id, $quiz['id'], $body, $quizzes, $options, $sanitizer);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
        }
        return JsonResponse::ok(['quiz' => $quiz], 201);
    }

    public function getQuiz(
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        string $quizId,
    ): ResponseInterface {
        $projectId = $this->resolveProjectForQuiz($projects, $quizId);
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Quiz not found', 404);
        }
        $quiz = $quizzes->find($projectId, $quizId);
        $opts = $options->listForQuiz($projectId, $quizId);
        $payload = self::quizPayload($quiz, $opts);
        $payload['projectId'] = $projectId;
        return JsonResponse::ok(['quiz' => $payload]);
    }

    public function patchQuiz(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        SanitizerService $sanitizer,
        StateService $state,
        string $quizId,
    ): ResponseInterface {
        $projectId = $this->resolveProjectForQuiz($projects, $quizId);
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Quiz not found', 404);
        }
        $project = $projects->find($projectId);
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Project must be in SETUP or TEST to edit', 423, [
                'currentState' => $e->currentState,
            ]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $quiz = $this->applyQuizBody($projectId, $quizId, $body, $quizzes, $options, $sanitizer);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
        }
        $quiz['projectId'] = $projectId;
        return JsonResponse::ok(['quiz' => $quiz]);
    }

    public function putOptions(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        SanitizerService $sanitizer,
        StateService $state,
        string $quizId,
    ): ResponseInterface {
        $projectId = $this->resolveProjectForQuiz($projects, $quizId);
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Quiz not found', 404);
        }
        $project = $projects->find($projectId);
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Project must be in SETUP or TEST to edit', 423, [
                'currentState' => $e->currentState,
            ]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $opts = $body['options'] ?? null;
        if (!is_array($opts)) {
            return JsonResponse::error('VALIDATION', 'options array required', 400);
        }
        try {
            $saved = $options->replaceAll($projectId, $quizId, $this->sanitizeOptions($opts, $sanitizer));
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
        }
        $quiz = self::quizPayload($quizzes->find($projectId, $quizId), $saved);
        $quiz['projectId'] = $projectId;
        return JsonResponse::ok(['quiz' => $quiz]);
    }

    public function listMedia(ProjectsRepo $projects, MediaRepo $media, ConfigBag $config, string $id): ResponseInterface
    {
        if (!$projects->find($id)) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        $list = $media->list($id);
        foreach ($list as &$m) {
            $m['url'] = $config->webPath('/media/' . $id . '/' . basename($m['stored_path']));
        }
        return JsonResponse::ok(['projectId' => $id, 'media' => $list]);
    }

    public function uploadMedia(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        MediaService $media,
        StateService $state,
        ConfigBag $config,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Project must be in SETUP or TEST to edit', 423, [
                'currentState' => $e->currentState,
            ]);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file) {
            return JsonResponse::error('VALIDATION', 'multipart field "file" required', 400);
        }
        $tempPath = tempnam(sys_get_temp_dir(), 'fqagent');
        $file->moveTo($tempPath);
        $tmp = [
            'error' => UPLOAD_ERR_OK,
            'size' => $file->getSize(),
            'tmp_name' => $tempPath,
            'name' => $file->getClientFilename(),
        ];
        try {
            $row = $media->upload($id, $tmp, 'agent');
        } catch (\RuntimeException $e) {
            @unlink($tempPath);
            $code = $e->getMessage();
            return match ($code) {
                'TOO_LARGE' => JsonResponse::error('TOO_LARGE', 'File too large', 413),
                'INVALID_MIME', 'INVALID_IMAGE' => JsonResponse::error('VALIDATION', 'Invalid file type', 400),
                default => JsonResponse::error('VALIDATION', 'Upload failed', 400),
            };
        }
        $url = $config->webPath('/media/' . $id . '/' . basename($row['stored_path']));
        return JsonResponse::ok([
            'id' => $row['id'],
            'url' => $url,
            'mime' => $row['mime'],
            'width' => $row['width'],
            'height' => $row['height'],
            'filename' => $row['filename'],
            'hint' => 'Embed in HTML as <img src="' . $url . '"> (or audio/video as appropriate).',
        ], 201);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function applyQuizBody(
        string $projectId,
        string $quizId,
        array $body,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        SanitizerService $sanitizer,
    ): array {
        $fields = [];
        if (array_key_exists('title', $body)) {
            $fields['title'] = trim((string) $body['title']);
        }
        if (array_key_exists('points', $body)) {
            $fields['points'] = (int) $body['points'];
        }
        if (array_key_exists('shuffle_options', $body)) {
            $fields['shuffle_options'] = !empty($body['shuffle_options']) ? 1 : 0;
        }
        foreach (['description_html', 'explanation_html'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[$k] = $sanitizer->clean((string) $body[$k]);
            }
        }
        if ($fields !== []) {
            $quizzes->update($projectId, $quizId, $fields);
        }

        if (array_key_exists('options', $body)) {
            if (!is_array($body['options'])) {
                throw new \InvalidArgumentException('options must be an array');
            }
            $merged = $this->mergeOptions(
                $options->listForQuiz($projectId, $quizId),
                $body['options'],
                $sanitizer,
            );
            $options->replaceAll($projectId, $quizId, $merged);
        }

        $quiz = $quizzes->find($projectId, $quizId);
        $opts = $options->listForQuiz($projectId, $quizId);
        return self::quizPayload($quiz, $opts);
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $incoming
     * @return list<array{id?: string, label_html: string, is_correct: bool|int, feedback_html?: string}>
     */
    private function mergeOptions(array $existing, array $incoming, SanitizerService $sanitizer): array
    {
        // Full replace when caller sends exactly 4 options without intending sparse merge
        // (always merge onto the existing 4 slots so ids stay stable when possible).
        $byId = [];
        foreach ($existing as $o) {
            $byId[(string) $o['id']] = $o;
        }
        $slots = array_values($existing);
        while (count($slots) < 4) {
            $slots[] = [
                'id' => \FamilyQuiz\Support\Id::uuid(),
                'label_html' => '',
                'is_correct' => 0,
                'feedback_html' => '',
            ];
        }
        $slots = array_slice($slots, 0, 4);

        foreach (array_values($incoming) as $i => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $targetIndex = $i;
            if (isset($raw['id']) && is_string($raw['id']) && isset($byId[$raw['id']])) {
                foreach ($slots as $idx => $slot) {
                    if ((string) $slot['id'] === (string) $raw['id']) {
                        $targetIndex = $idx;
                        break;
                    }
                }
            } elseif (isset($raw['position'])) {
                $targetIndex = (int) $raw['position'];
            }
            if ($targetIndex < 0 || $targetIndex > 3) {
                continue;
            }
            $cur = $slots[$targetIndex];
            if (array_key_exists('label_html', $raw)) {
                $cur['label_html'] = $sanitizer->clean((string) $raw['label_html']);
            }
            if (array_key_exists('feedback_html', $raw)) {
                $cur['feedback_html'] = $sanitizer->clean((string) $raw['feedback_html']);
            }
            if (array_key_exists('is_correct', $raw)) {
                $cur['is_correct'] = !empty($raw['is_correct']) ? 1 : 0;
            }
            $slots[$targetIndex] = $cur;
        }

        // Ensure exactly one correct: if multiple, keep first; if none, mark first.
        $correctIdx = null;
        foreach ($slots as $idx => $slot) {
            if (!empty($slot['is_correct'])) {
                if ($correctIdx === null) {
                    $correctIdx = $idx;
                } else {
                    $slots[$idx]['is_correct'] = 0;
                }
            }
        }
        if ($correctIdx === null) {
            $slots[0]['is_correct'] = 1;
        }

        $out = [];
        foreach ($slots as $slot) {
            $out[] = [
                'id' => $slot['id'],
                'label_html' => (string) ($slot['label_html'] ?? ''),
                'is_correct' => !empty($slot['is_correct']),
                'feedback_html' => (string) ($slot['feedback_html'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $opts
     * @return list<array{id?: string, label_html: string, is_correct: bool, feedback_html: string}>
     */
    private function sanitizeOptions(array $opts, SanitizerService $sanitizer): array
    {
        $clean = [];
        foreach ($opts as $o) {
            if (!is_array($o)) {
                continue;
            }
            $row = [
                'label_html' => $sanitizer->clean((string) ($o['label_html'] ?? '')),
                'feedback_html' => $sanitizer->clean((string) ($o['feedback_html'] ?? '')),
                'is_correct' => !empty($o['is_correct']),
            ];
            if (!empty($o['id']) && is_string($o['id'])) {
                $row['id'] = $o['id'];
            }
            $clean[] = $row;
        }
        return $clean;
    }

    private function resolveProjectForQuiz(ProjectsRepo $projects, string $quizId): ?string
    {
        $active = $projects->getSetting('active_project_id');
        if ($active) {
            $pdo = \FamilyQuiz\Db\Connections::projectDb($active);
            $stmt = $pdo->prepare('SELECT id FROM quizzes WHERE id = :id');
            $stmt->execute(['id' => $quizId]);
            if ($stmt->fetch()) {
                return $active;
            }
        }
        foreach ($projects->listAll() as $p) {
            $pdo = \FamilyQuiz\Db\Connections::projectDb($p['id']);
            $stmt = $pdo->prepare('SELECT id FROM quizzes WHERE id = :id');
            $stmt->execute(['id' => $quizId]);
            if ($stmt->fetch()) {
                return $p['id'];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private static function projectSummary(array $p): array
    {
        return [
            'id' => $p['id'],
            'title' => $p['title'],
            'slug' => $p['slug'],
            'state' => $p['state'],
            'writable' => \FamilyQuiz\Services\StateService::isContentMutable($p['state'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed>|null $quiz
     * @param list<array<string, mixed>> $opts
     * @return array<string, mixed>
     */
    private static function quizPayload(?array $quiz, array $opts): array
    {
        $quiz ??= [];
        $completeness = self::completeness($opts);
        return [
            'id' => $quiz['id'] ?? null,
            'title' => $quiz['title'] ?? '',
            'description_html' => $quiz['description_html'] ?? '',
            'explanation_html' => $quiz['explanation_html'] ?? '',
            'points' => (int) ($quiz['points'] ?? 1),
            'shuffle_options' => (int) ($quiz['shuffle_options'] ?? 1),
            'position' => (int) ($quiz['position'] ?? 0),
            'options' => array_map(static fn ($o) => [
                'id' => $o['id'],
                'label_html' => $o['label_html'],
                'is_correct' => (bool) (int) $o['is_correct'],
                'feedback_html' => $o['feedback_html'] ?? '',
                'position' => (int) ($o['position'] ?? 0),
            ], $opts),
            'completeness' => $completeness,
        ];
    }

    /** @param list<array<string, mixed>> $opts */
    private static function completeness(array $opts): array
    {
        $filled = 0;
        $correct = 0;
        $missingFeedback = 0;
        foreach ($opts as $o) {
            if (trim(strip_tags((string) $o['label_html'])) !== '') {
                $filled++;
            } else {
                // track empty labels via filled count
            }
            if ((int) $o['is_correct'] === 1) {
                $correct++;
            }
            if (trim(strip_tags((string) ($o['feedback_html'] ?? ''))) === '') {
                $missingFeedback++;
            }
        }
        $issues = [];
        if ($filled < 4) {
            $issues[] = 'only_' . $filled . '_options_filled';
        }
        if ($correct !== 1) {
            $issues[] = 'missing_correct_answer';
        }
        return [
            'filled' => $filled,
            'correct' => $correct,
            'missing_feedback' => $missingFeedback,
            'issues' => $issues,
            'complete' => $issues === [],
        ];
    }
}
