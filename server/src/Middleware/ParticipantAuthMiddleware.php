<?php

declare(strict_types=1);

namespace FamilyQuiz\Middleware;

use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Repo\UsersRepo;
use FamilyQuiz\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ParticipantAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $auth,
        private ProjectsRepo $projects,
        private UsersRepo $users,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $projectId = $this->projects->getSetting('public_project_id')
            ?? $this->projects->getSetting('active_project_id');
        $request = $request->withAttribute('publicProjectId', $projectId);

        if (!$projectId) {
            return $handler->handle($request);
        }

        $cookies = $request->getCookieParams();
        $token = $cookies['fq_user'] ?? null;
        if (!$token || !is_string($token)) {
            // Optional Authorization / body mirror is handled by join; allow header X-FQ-Token
            $token = $request->getHeaderLine('X-FQ-Token') ?: null;
        }
        if ($token) {
            $hash = $this->auth->hashToken($token);
            $user = $this->users->findByTokenHash($projectId, $hash);
            if ($user) {
                $this->users->touch($projectId, $user['id']);
                $request = $request
                    ->withAttribute('participant', $user)
                    ->withAttribute('participantToken', $token);
            }
        }

        return $handler->handle($request);
    }
}
