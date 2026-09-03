<?php

declare(strict_types=1);

namespace FamilyQuiz\Routes;

use FamilyQuiz\Middleware\AdminAuthMiddleware;
use FamilyQuiz\Middleware\RateLimitMiddleware;
use FamilyQuiz\Repo\AnswersRepo;
use FamilyQuiz\Repo\MediaRepo;
use FamilyQuiz\Repo\OptionsRepo;
use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Repo\QuizzesRepo;
use FamilyQuiz\Repo\ResultsRepo;
use FamilyQuiz\Repo\SuperusersRepo;
use FamilyQuiz\Repo\UsersRepo;
use FamilyQuiz\Services\AuthService;
use FamilyQuiz\Services\ContentPackService;
use FamilyQuiz\Services\ExportService;
use FamilyQuiz\Services\LockedException;
use FamilyQuiz\Services\MediaService;
use FamilyQuiz\Services\SanitizerService;
use FamilyQuiz\Services\ScoringService;
use FamilyQuiz\Services\SeedService;
use FamilyQuiz\Services\StateService;
use FamilyQuiz\Support\ConfigBag;
use FamilyQuiz\Support\SessionCookie;
use FamilyQuiz\Support\JsonResponse;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class AdminRoutes
{
    public static function register(App $app, AdminAuthMiddleware $authMw): void
    {
        $app->post('/api/admin/login', [self::class, 'login'])
            ->add(new RateLimitMiddleware('login', 5, 300, 'email', true));
        // Hidden automation entry: GET /api/admin/magic-login?t=<admin_magic_token>
        $app->get('/api/admin/magic-login', [self::class, 'magicLogin'])
            ->add(new RateLimitMiddleware('magic-login', 10, 900));
        $app->post('/api/admin/logout', [self::class, 'logout']);

        $app->group('/api/admin', function (RouteCollectorProxy $group) {
            $group->get('/me', [self::class, 'me']);
            $group->get('/superusers', [self::class, 'listSuperusers']);
            $group->post('/superusers', [self::class, 'createSuperuser']);
            $group->patch('/superusers/{id}', [self::class, 'patchSuperuser']);
            $group->delete('/superusers/{id}', [self::class, 'deleteSuperuser']);

            $group->get('/projects', [self::class, 'listProjects']);
            $group->post('/projects', [self::class, 'createProject']);
            $group->patch('/projects/{id}', [self::class, 'patchProject']);
            $group->delete('/projects/{id}', [self::class, 'deleteProject']);
            $group->post('/projects/{id}/state', [self::class, 'setState']);
            $group->post('/projects/{id}/select', [self::class, 'selectProject']);

            $group->get('/projects/{id}/quizzes', [self::class, 'listQuizzes']);
            $group->post('/projects/{id}/quizzes', [self::class, 'createQuiz']);
            $group->post('/projects/{id}/quizzes/reorder', [self::class, 'reorderQuizzes']);
            $group->post('/projects/{id}/quizzes/clone', [self::class, 'cloneQuizzes']);
            $group->post('/projects/{id}/quizzes/copy', [self::class, 'copyQuizzes']);
            $group->post('/projects/{id}/quizzes/export-pack', [self::class, 'exportQuizPack']);
            $group->post('/projects/{id}/quizzes/import-pack', [self::class, 'importQuizPack'])
                ->add(new RateLimitMiddleware('uploads', 30, 3600));
            $group->post('/projects/{id}/quizzes/batch-delete', [self::class, 'batchDeleteQuizzes']);
            $group->get('/quizzes/{quizId}', [self::class, 'getQuiz']);
            $group->patch('/quizzes/{quizId}', [self::class, 'patchQuiz']);
            $group->delete('/quizzes/{quizId}', [self::class, 'deleteQuiz']);
            $group->put('/quizzes/{quizId}/options', [self::class, 'putOptions']);

            $group->post('/projects/{id}/media', [self::class, 'uploadProjectMedia'])
                ->add(new RateLimitMiddleware('uploads', 30, 3600));
            $group->get('/projects/{id}/media', [self::class, 'listProjectMedia']);

            $group->post('/media', [self::class, 'uploadMedia'])
                ->add(new RateLimitMiddleware('uploads', 30, 3600));
            $group->get('/media', [self::class, 'listMedia']);
            $group->delete('/media/{mediaId}', [self::class, 'deleteMedia']);

            $group->get('/participants', [self::class, 'listParticipants']);
            $group->delete('/participants/{userId}', [self::class, 'deleteParticipant']);
            $group->post('/participants/{userId}/reset-answers', [self::class, 'resetParticipantAnswers']);

            $group->get('/results', [self::class, 'results']);
            $group->get('/results/users/{userId}', [self::class, 'userResults']);
            $group->post('/results/recompute', [self::class, 'recompute']);
            $group->get('/export', [self::class, 'export']);
            $group->get('/qrcode', [self::class, 'qrcode']);
        })->add($authMw);
    }

    public function login(
        ServerRequestInterface $request,
        AuthService $auth,
        SeedService $seed,
        ConfigBag $config,
    ): ResponseInterface {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $user = $auth->attemptLogin($email, $password);
        if (!$user) {
            return JsonResponse::error('UNAUTHENTICATED', 'Invalid credentials', 401);
        }
        RateLimitMiddleware::clearBucket('login', $email);
        $token = $auth->issueAdminToken($user);
        $cfg = $config->all();
        $response = JsonResponse::ok([
            'admin' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
            ],
            'seedPasswordWarning' => $seed->seedPasswordStillInUse($cfg),
        ]);
        return self::setAdminCookie($response, $token, $cfg);
    }

    public function logout(ConfigBag $config): ResponseInterface
    {
        $response = JsonResponse::ok(['ok' => true]);
        return SessionCookie::apply(
            $response,
            SessionCookie::clearHeaders('fq_admin', $config->all()),
        );
    }

    /**
     * Passwordless admin session via a long secret from config (`admin_magic_token`).
     * Intended for automation / smoke checks — not linked from the UI.
     * Query: ?t=TOKEN  (also accepts ?token=)
     * Optional: ?format=json to skip the redirect (SPA same-origin flow).
     */
    public function magicLogin(
        ServerRequestInterface $request,
        AuthService $auth,
        SeedService $seed,
        ConfigBag $config,
    ): ResponseInterface {
        $cfg = $config->all();
        $expected = (string) ($cfg['admin_magic_token'] ?? '');
        if ($expected === '' || strlen($expected) < 32 || str_contains($expected, 'GENERATE')) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }

        $q = $request->getQueryParams();
        $provided = (string) ($q['t'] ?? $q['token'] ?? '');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            // Same 404 as missing route — don't confirm the feature exists
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }

        $email = (string) ($cfg['seed_admin_email'] ?? '');
        $user = $email !== '' ? $auth->findActiveAdminByEmail($email) : null;
        if (!$user) {
            $user = $auth->findFirstActiveAdmin();
        }
        if (!$user) {
            return JsonResponse::error('NOT_FOUND', 'No admin account', 404);
        }

        $auth->touchAdminLogin($user['id']);
        $jwt = $auth->issueAdminToken($user);
        error_log('admin magic-login used for ' . $user['email']);

        $wantJson = (($q['format'] ?? '') === 'json')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($wantJson) {
            $response = JsonResponse::ok([
                'ok' => true,
                'admin' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'display_name' => $user['display_name'],
                ],
                'seedPasswordWarning' => $seed->seedPasswordStillInUse($cfg),
            ]);
            return self::setAdminCookie($response, $jwt, $cfg);
        }

        $dest = rtrim((string) ($cfg['public_base_url'] ?? '/'), '/') . '/admin/projects';
        $response = new \Slim\Psr7\Response(302);
        $response = $response->withHeader('Location', $dest);
        return self::setAdminCookie($response, $jwt, $cfg);
    }

    public function me(
        ServerRequestInterface $request,
        SeedService $seed,
        ConfigBag $config,
        ProjectsRepo $projects,
    ): ResponseInterface {
        $admin = $request->getAttribute('admin');
        return JsonResponse::ok([
            'admin' => [
                'id' => $admin['id'],
                'email' => $admin['email'],
                'display_name' => $admin['display_name'],
            ],
            'seedPasswordWarning' => $seed->seedPasswordStillInUse($config->all()),
            'activeProjectId' => $projects->getSetting('active_project_id'),
            'publicProjectId' => $projects->getSetting('public_project_id'),
        ]);
    }

    public function listSuperusers(SuperusersRepo $repo): ResponseInterface
    {
        return JsonResponse::ok(['superusers' => $repo->listAll()]);
    }

    public function createSuperuser(
        ServerRequestInterface $request,
        SuperusersRepo $repo,
        SeedService $seed,
    ): ResponseInterface {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $displayName = isset($body['display_name']) ? (string) $body['display_name'] : null;
        if ($email === '' || strlen($password) < 8) {
            return JsonResponse::error('VALIDATION', 'Email and password (8+) required', 400);
        }
        $algo = $seed->preferredAlgo();
        $hash = $seed->hashPassword($password, $algo);
        try {
            $user = $repo->create($email, $hash, $algo, $displayName);
        } catch (\PDOException $e) {
            return JsonResponse::error('CONFLICT', 'Email already exists', 409);
        }
        return JsonResponse::ok(['superuser' => $user], 201);
    }

    public function patchSuperuser(
        ServerRequestInterface $request,
        SuperusersRepo $repo,
        SeedService $seed,
        string $id,
    ): ResponseInterface {
        $body = (array) ($request->getParsedBody() ?? []);
        $fields = [];
        if (isset($body['display_name'])) {
            $fields['display_name'] = $body['display_name'];
        }
        if (isset($body['email'])) {
            $fields['email'] = trim((string) $body['email']);
        }
        if (array_key_exists('is_active', $body)) {
            $active = (int) (bool) $body['is_active'];
            if ($active === 0 && $repo->countActive() <= 1) {
                $current = $repo->find($id);
                if ($current && (int) $current['is_active'] === 1) {
                    return JsonResponse::error('CONFLICT', 'Cannot disable the last active admin', 409);
                }
            }
            $fields['is_active'] = $active;
        }
        if (!empty($body['password'])) {
            $password = (string) $body['password'];
            if (strlen($password) < 8) {
                return JsonResponse::error('VALIDATION', 'Password must be at least 8 characters', 400);
            }
            $algo = $seed->preferredAlgo();
            $fields['password_hash'] = $seed->hashPassword($password, $algo);
            $fields['password_algo'] = $algo;
        }
        if ($fields === []) {
            return JsonResponse::error('VALIDATION', 'No changes provided', 400);
        }
        return JsonResponse::ok(['superuser' => $repo->update($id, $fields)]);
    }

    public function deleteSuperuser(SuperusersRepo $repo, string $id): ResponseInterface
    {
        $current = $repo->find($id);
        if (!$current) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        if ((int) $current['is_active'] === 1 && $repo->countActive() <= 1) {
            return JsonResponse::error('CONFLICT', 'Cannot delete the last active admin', 409);
        }
        $repo->delete($id);
        return JsonResponse::ok(['ok' => true]);
    }

    public function listProjects(ProjectsRepo $projects): ResponseInterface
    {
        $list = $projects->listAll();
        foreach ($list as &$p) {
            $p['description_html'] = $projects->getMeta($p['id'], 'description_html');
        }
        return JsonResponse::ok([
            'projects' => $list,
            'activeProjectId' => $projects->getSetting('active_project_id'),
            'publicProjectId' => $projects->getSetting('public_project_id'),
        ]);
    }

    public function createProject(ServerRequestInterface $request, ProjectsRepo $projects): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $title = trim((string) ($body['title'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        if ($title === '' || $slug === '') {
            return JsonResponse::error('VALIDATION', 'Title and slug required', 400);
        }
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return JsonResponse::error('VALIDATION', 'Slug must be lowercase alphanumeric/hyphen', 400);
        }
        if ($projects->findBySlug($slug)) {
            return JsonResponse::error('CONFLICT', 'Slug taken', 409);
        }
        $project = $projects->create($title, $slug);
        if (!$projects->getSetting('active_project_id')) {
            $projects->setSetting('active_project_id', $project['id']);
            $projects->setSetting('public_project_id', $project['id']);
        }
        return JsonResponse::ok(['project' => $project], 201);
    }

    public function patchProject(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        SanitizerService $sanitizer,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $fields = [];
        $needsMutable = array_key_exists('description_html', $body)
            || array_key_exists('title', $body)
            || array_key_exists('slug', $body)
            || array_key_exists('shuffle_quizzes', $body)
            || array_key_exists('require_pin', $body);
        if ($needsMutable) {
            try {
                $state->assertContentMutable($project);
            } catch (LockedException $e) {
                return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
            }
        }
        foreach (['title', 'slug', 'shuffle_quizzes', 'require_pin'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[$k] = $body[$k];
            }
        }
        if (isset($fields['title'])) {
            $fields['title'] = trim((string) $fields['title']);
            if ($fields['title'] === '') {
                return JsonResponse::error('VALIDATION', 'Title required', 400);
            }
        }
        if (isset($fields['slug'])) {
            $fields['slug'] = trim((string) $fields['slug']);
            if (!preg_match('/^[a-z0-9\-]+$/', $fields['slug'])) {
                return JsonResponse::error('VALIDATION', 'Slug must be lowercase alphanumeric/hyphen', 400);
            }
            $existing = $projects->findBySlug($fields['slug']);
            if ($existing && $existing['id'] !== $id) {
                return JsonResponse::error('CONFLICT', 'Slug taken', 409);
            }
        }
        if (array_key_exists('description_html', $body)) {
            $fields['description_html'] = $sanitizer->clean((string) $body['description_html']);
        }
        return JsonResponse::ok(['project' => $projects->update($id, $fields)]);
    }

    public function deleteProject(ServerRequestInterface $request, ProjectsRepo $projects, string $id): ResponseInterface
    {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        if ($projects->getSetting('active_project_id') === $id) {
            $projects->setSetting('active_project_id', null);
        }
        if ($projects->getSetting('public_project_id') === $id) {
            $projects->setSetting('public_project_id', null);
        }
        $projects->softDelete($id);
        return JsonResponse::ok(['ok' => true]);
    }

    public function setState(ServerRequestInterface $request, StateService $state, string $id): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $newState = (string) ($body['state'] ?? '');
        try {
            $project = $state->transition($id, $newState);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'SCORING_IN_PROGRESS') {
                return JsonResponse::error('CONFLICT', 'Scoring already in progress', 409);
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
        }
        return JsonResponse::ok(['project' => $project]);
    }

    public function selectProject(ServerRequestInterface $request, ProjectsRepo $projects, string $id): ResponseInterface
    {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $asPublic = array_key_exists('public', $body) ? (bool) $body['public'] : true;
        $projects->setSetting('active_project_id', $id);
        if ($asPublic) {
            $projects->setSetting('public_project_id', $id);
        }
        return JsonResponse::ok([
            'activeProjectId' => $id,
            'publicProjectId' => $projects->getSetting('public_project_id'),
        ]);
    }

    public function listQuizzes(ProjectsRepo $projects, QuizzesRepo $quizzes, OptionsRepo $options, string $id): ResponseInterface
    {
        if (!$projects->find($id)) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $list = $quizzes->listForProject($id);
        foreach ($list as &$q) {
            $opts = $options->listForQuiz($id, $q['id']);
            $q['options'] = $opts;
            $q['completeness'] = self::completeness($opts);
        }
        return JsonResponse::ok(['quizzes' => $list]);
    }

    public function createQuiz(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $title = trim((string) ($body['title'] ?? 'Untitled quiz'));
        $quiz = $quizzes->create($id, $title);
        $quiz['options'] = $options->listForQuiz($id, $quiz['id']);
        return JsonResponse::ok(['quiz' => $quiz], 201);
    }

    public function reorderQuizzes(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $orderedIds = $body['orderedIds'] ?? [];
        if (!is_array($orderedIds)) {
            return JsonResponse::error('VALIDATION', 'orderedIds required', 400);
        }
        $quizzes->reorder($id, $orderedIds);
        return JsonResponse::ok(['ok' => true]);
    }

    public function cloneQuizzes(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $quizIds = $body['quizIds'] ?? [];
        if (!is_array($quizIds) || $quizIds === []) {
            return JsonResponse::error('VALIDATION', 'quizIds required', 400);
        }
        $created = [];
        foreach ($quizIds as $quizId) {
            try {
                $created[] = $quizzes->duplicate($id, (string) $quizId, $id, true);
            } catch (\InvalidArgumentException) {
                return JsonResponse::error('NOT_FOUND', 'Quiz not found: ' . $quizId, 404);
            }
        }
        return JsonResponse::ok(['quizzes' => $created], 201);
    }

    public function copyQuizzes(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $targetId = (string) ($body['targetProjectId'] ?? '');
        $target = $projects->find($targetId);
        if (!$target) {
            return JsonResponse::error('NOT_FOUND', 'Target project not found', 404);
        }
        try {
            $state->assertContentMutable($target);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Target project must be in SETUP or TEST', 423, ['currentState' => $e->currentState]);
        }
        $quizIds = $body['quizIds'] ?? [];
        if (!is_array($quizIds) || $quizIds === []) {
            return JsonResponse::error('VALIDATION', 'quizIds required', 400);
        }
        $created = [];
        foreach ($quizIds as $quizId) {
            try {
                // Keep original title when copying across projects
                $created[] = $quizzes->duplicate($id, (string) $quizId, $targetId, false);
            } catch (\InvalidArgumentException) {
                return JsonResponse::error('NOT_FOUND', 'Quiz not found: ' . $quizId, 404);
            }
        }
        return JsonResponse::ok(['quizzes' => $created, 'targetProjectId' => $targetId], 201);
    }

    public function exportQuizPack(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        ContentPackService $pack,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $quizIds = $body['quizIds'] ?? [];
        if (!is_array($quizIds) || $quizIds === []) {
            return JsonResponse::error('VALIDATION', 'quizIds required', 400);
        }
        try {
            $built = $pack->exportZip($id, array_map('strval', $quizIds));
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
        }
        $bin = (string) file_get_contents($built['path']);
        @unlink($built['path']);
        $response = new \Slim\Psr7\Response(200);
        $response->getBody()->write($bin);
        $filename = str_replace(['"', "\r", "\n"], '', $built['filename']);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function importQuizPack(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        ContentPackService $pack,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to import', 423, ['currentState' => $e->currentState]);
        }
        $admin = $request->getAttribute('admin');
        $uploadedBy = is_array($admin) ? (string) ($admin['id'] ?? '') : null;

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if ($file) {
            $clientName = strtolower((string) $file->getClientFilename());
            $tempPath = tempnam(sys_get_temp_dir(), 'fqimp');
            $file->moveTo($tempPath);
            try {
                if (str_ends_with($clientName, '.zip') || self::looksLikeZip($tempPath)) {
                    $result = $pack->importZip($id, $tempPath, $uploadedBy);
                } else {
                    $raw = (string) file_get_contents($tempPath);
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) {
                        return JsonResponse::error('VALIDATION', 'File must be a ZIP pack or JSON', 400);
                    }
                    $result = $pack->importJson($id, $decoded, $uploadedBy);
                }
            } catch (\InvalidArgumentException $e) {
                return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
            } finally {
                @unlink($tempPath);
            }
            return JsonResponse::ok($result, 201);
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || $body === []) {
            return JsonResponse::error('VALIDATION', 'Upload a .zip or .json file, or POST JSON', 400);
        }
        try {
            $result = $pack->importJson($id, $body, $uploadedBy);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
        }
        return JsonResponse::ok($result, 201);
    }

    private static function looksLikeZip(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 4);
        fclose($fh);
        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06";
    }

    public function batchDeleteQuizzes(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        StateService $state,
        string $id,
    ): ResponseInterface {
        $project = $projects->find($id);
        if (!$project) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $quizIds = $body['quizIds'] ?? [];
        if (!is_array($quizIds) || $quizIds === []) {
            return JsonResponse::error('VALIDATION', 'quizIds required', 400);
        }
        $deleted = $quizzes->deleteMany($id, array_map('strval', $quizIds));
        return JsonResponse::ok(['deleted' => $deleted]);
    }

    public function getQuiz(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        string $quizId,
    ): ResponseInterface {
        $projectId = $this->resolveProjectForQuiz($projects, $quizId);
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $quiz = $quizzes->find($projectId, $quizId);
        if (!$quiz) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $quiz['options'] = $options->listForQuiz($projectId, $quizId);
        $quiz['projectId'] = $projectId;
        $project = $projects->find($projectId);
        $quiz['projectState'] = $project['state'] ?? 'SETUP';
        return JsonResponse::ok(['quiz' => $quiz]);
    }

    public function patchQuiz(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        SanitizerService $sanitizer,
        StateService $state,
        string $quizId,
    ): ResponseInterface {
        $projectId = $this->resolveProjectForQuiz($projects, $quizId);
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $project = $projects->find($projectId);
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $fields = [];
        foreach (['title', 'points', 'shuffle_options'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[$k] = $body[$k];
            }
        }
        foreach (['description_html', 'explanation_html'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[$k] = $sanitizer->clean((string) $body[$k]);
            }
        }
        return JsonResponse::ok(['quiz' => $quizzes->update($projectId, $quizId, $fields)]);
    }

    public function deleteQuiz(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        QuizzesRepo $quizzes,
        StateService $state,
        string $quizId,
    ): ResponseInterface {
        $projectId = $this->resolveProjectForQuiz($projects, $quizId);
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $project = $projects->find($projectId);
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
        }
        if (!$quizzes->find($projectId, $quizId)) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $quizzes->delete($projectId, $quizId);
        return JsonResponse::ok(['ok' => true]);
    }

    public function putOptions(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        OptionsRepo $options,
        SanitizerService $sanitizer,
        StateService $state,
        string $quizId,
    ): ResponseInterface {
        $projectId = $this->resolveProjectForQuiz($projects, $quizId);
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        $project = $projects->find($projectId);
        try {
            $state->assertContentMutable($project);
        } catch (LockedException $e) {
            return JsonResponse::error('LOCKED', 'Switch to SETUP or TEST to edit', 423, ['currentState' => $e->currentState]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $opts = $body['options'] ?? null;
        if (!is_array($opts)) {
            return JsonResponse::error('VALIDATION', 'options array required', 400);
        }
        $clean = [];
        foreach ($opts as $o) {
            $clean[] = [
                'id' => $o['id'] ?? null,
                'label_html' => $sanitizer->clean((string) ($o['label_html'] ?? '')),
                'feedback_html' => $sanitizer->clean((string) ($o['feedback_html'] ?? '')),
                'is_correct' => !empty($o['is_correct']),
            ];
        }
        try {
            $saved = $options->replaceAll($projectId, $quizId, $clean);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error('VALIDATION', $e->getMessage(), 400);
        }
        return JsonResponse::ok(['options' => $saved]);
    }

    public function uploadProjectMedia(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        MediaService $media,
        ConfigBag $config,
        string $id,
    ): ResponseInterface {
        if (!$projects->find($id)) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        return self::storeUploadedFile($request, $media, $config, $id);
    }

    public function uploadMedia(
        ServerRequestInterface $request,
        ProjectsRepo $projects,
        MediaService $media,
        ConfigBag $config,
    ): ResponseInterface {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('VALIDATION', 'No active project', 400);
        }
        return self::storeUploadedFile($request, $media, $config, $projectId);
    }

    public function listProjectMedia(
        ProjectsRepo $projects,
        MediaRepo $media,
        ConfigBag $config,
        string $id,
    ): ResponseInterface {
        if (!$projects->find($id)) {
            return JsonResponse::error('NOT_FOUND', 'Project not found', 404);
        }
        return self::mediaListResponse($media, $config, $id);
    }

    private static function storeUploadedFile(
        ServerRequestInterface $request,
        MediaService $media,
        ConfigBag $config,
        string $projectId,
    ): ResponseInterface {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file) {
            return JsonResponse::error('VALIDATION', 'file required', 400);
        }
        $tempPath = tempnam(sys_get_temp_dir(), 'fqupload');
        $file->moveTo($tempPath);
        $tmp = [
            'error' => UPLOAD_ERR_OK,
            'size' => $file->getSize(),
            'tmp_name' => $tempPath,
            'name' => $file->getClientFilename(),
        ];

        $admin = $request->getAttribute('admin');
        try {
            $row = $media->upload($projectId, $tmp, $admin['id'] ?? null);
        } catch (\RuntimeException $e) {
            @unlink($tempPath);
            $code = $e->getMessage();
            return match ($code) {
                'TOO_LARGE' => JsonResponse::error('TOO_LARGE', 'File too large', 413),
                'INVALID_MIME', 'INVALID_IMAGE' => JsonResponse::error('VALIDATION', 'Invalid file type', 400),
                default => JsonResponse::error('VALIDATION', 'Upload failed', 400),
            };
        }
        $url = $config->webPath('/media/' . $projectId . '/' . basename($row['stored_path']));
        return JsonResponse::ok([
            'id' => $row['id'],
            'url' => $url,
            'mime' => $row['mime'],
            'width' => $row['width'],
            'height' => $row['height'],
            'filename' => $row['filename'],
        ], 201);
    }

    private static function mediaListResponse(MediaRepo $media, ConfigBag $config, string $projectId): ResponseInterface
    {
        $list = $media->list($projectId);
        foreach ($list as &$m) {
            $m['url'] = $config->webPath('/media/' . $projectId . '/' . basename($m['stored_path']));
            $m['referenced'] = $media->isReferenced($projectId, $m['id']);
        }
        return JsonResponse::ok(['media' => $list]);
    }

    public function listMedia(ProjectsRepo $projects, MediaRepo $media, ConfigBag $config): ResponseInterface
    {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::ok(['media' => []]);
        }
        return self::mediaListResponse($media, $config, $projectId);
    }

    public function deleteMedia(ProjectsRepo $projects, MediaRepo $media, string $mediaId): ResponseInterface
    {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        if ($media->isReferenced($projectId, $mediaId)) {
            return JsonResponse::error('CONFLICT', 'Media is referenced', 409);
        }
        $media->delete($projectId, $mediaId);
        return JsonResponse::ok(['ok' => true]);
    }

    public function listParticipants(
        ProjectsRepo $projects,
        UsersRepo $users,
        QuizzesRepo $quizzes,
        AnswersRepo $answers,
    ): ResponseInterface {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::ok(['participants' => []]);
        }
        $total = count($quizzes->listForProject($projectId));
        $out = [];
        foreach ($users->listAll($projectId) as $u) {
            $out[] = [
                'id' => $u['id'],
                'name' => $u['name_display'],
                'answered' => $answers->answeredCount($projectId, $u['id']),
                'total' => $total,
                'last_seen_at' => $u['last_seen_at'],
                'created_at' => $u['created_at'],
            ];
        }
        return JsonResponse::ok(['participants' => $out]);
    }

    public function deleteParticipant(ProjectsRepo $projects, UsersRepo $users, string $userId): ResponseInterface
    {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        if (!$users->find($projectId, $userId)) {
            return JsonResponse::error('NOT_FOUND', 'Participant not found', 404);
        }
        $users->delete($projectId, $userId);
        return JsonResponse::ok(['ok' => true]);
    }

    public function resetParticipantAnswers(
        ProjectsRepo $projects,
        UsersRepo $users,
        AnswersRepo $answers,
        string $userId,
    ): ResponseInterface {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'Not found', 404);
        }
        if (!$users->find($projectId, $userId)) {
            return JsonResponse::error('NOT_FOUND', 'Participant not found', 404);
        }
        $cleared = $answers->clearAll($projectId, $userId);
        return JsonResponse::ok(['ok' => true, 'cleared' => $cleared]);
    }

    public function results(ProjectsRepo $projects, ResultsRepo $results, QuizzesRepo $quizzes, OptionsRepo $options): ResponseInterface
    {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'No active project', 404);
        }
        $stats = $results->optionStats($projectId);
        $byQuiz = [];
        foreach ($stats as $s) {
            $byQuiz[$s['quiz_id']][] = $s;
        }
        $quizStats = [];
        foreach ($quizzes->listForProject($projectId) as $q) {
            $quizStats[] = [
                'quiz' => $q,
                'options' => $options->listForQuiz($projectId, $q['id']),
                'stats' => $byQuiz[$q['id']] ?? [],
            ];
        }
        return JsonResponse::ok([
            'leaderboard' => $results->leaderboard($projectId),
            'quizStats' => $quizStats,
            'resultsComputedAt' => $projects->getMeta($projectId, 'results_computed_at', ''),
            'resultsStale' => (bool) ($projects->find($projectId)['results_stale'] ?? 0),
        ]);
    }

    public function userResults(
        ProjectsRepo $projects,
        ResultsRepo $results,
        QuizzesRepo $quizzes,
        OptionsRepo $options,
        UsersRepo $users,
        string $userId,
    ): ResponseInterface {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'No active project', 404);
        }
        $user = $users->find($projectId, $userId);
        if (!$user) {
            return JsonResponse::error('NOT_FOUND', 'User not found', 404);
        }
        $summary = $results->userResult($projectId, $userId);
        $answers = $results->userAnswers($projectId, $userId);
        $byQuiz = [];
        foreach ($answers as $a) {
            $byQuiz[$a['quiz_id']] = $a;
        }
        $detail = [];
        foreach ($quizzes->listForProject($projectId) as $q) {
            $detail[] = [
                'quiz' => $q,
                'options' => $options->listForQuiz($projectId, $q['id']),
                'answer' => $byQuiz[$q['id']] ?? null,
            ];
        }
        return JsonResponse::ok([
            'user' => ['id' => $user['id'], 'name' => $user['name_display']],
            'summary' => $summary,
            'detail' => $detail,
        ]);
    }

    public function recompute(ProjectsRepo $projects, ScoringService $scoring): ResponseInterface
    {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'No active project', 404);
        }
        try {
            $scoring->computeResults($projectId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'SCORING_IN_PROGRESS') {
                return JsonResponse::error('CONFLICT', 'Scoring already in progress', 409);
            }
            throw $e;
        }
        return JsonResponse::ok(['ok' => true]);
    }

    public function export(ProjectsRepo $projects, ExportService $export): ResponseInterface
    {
        $projectId = $projects->getSetting('active_project_id');
        if (!$projectId) {
            return JsonResponse::error('NOT_FOUND', 'No active project', 404);
        }
        $path = $export->buildZip($projectId);
        $response = new \Slim\Psr7\Response(200);
        $response->getBody()->write((string) file_get_contents($path));
        @unlink($path);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="family-quiz-export.zip"');
    }

    public function qrcode(ConfigBag $config): ResponseInterface
    {
        $url = rtrim((string) $config->get('public_base_url'), '/');
        $qr = new QrCode($url);
        $writer = new SvgWriter();
        $result = $writer->write($qr);
        $response = new \Slim\Psr7\Response(200);
        $response->getBody()->write($result->getString());
        return $response->withHeader('Content-Type', 'image/svg+xml');
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

    private static function completeness(array $opts): array
    {
        $filled = 0;
        $correct = 0;
        foreach ($opts as $o) {
            if (trim(strip_tags((string) $o['label_html'])) !== '') {
                $filled++;
            }
            if ((int) $o['is_correct'] === 1) {
                $correct++;
            }
        }
        $issues = [];
        if ($filled < 4) {
            $issues[] = 'only_' . $filled . '_options_filled';
        }
        if ($correct !== 1) {
            $issues[] = 'missing_correct_answer';
        }
        return ['filled' => $filled, 'correct' => $correct, 'issues' => $issues, 'complete' => $issues === []];
    }

    private static function setAdminCookie(ResponseInterface $response, string $token, array $config): ResponseInterface
    {
        return SessionCookie::apply(
            $response,
            SessionCookie::setHeaders('fq_admin', $token, $config, 12 * 3600),
        );
    }
}
