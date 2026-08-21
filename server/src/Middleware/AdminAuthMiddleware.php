<?php

declare(strict_types=1);

namespace FamilyQuiz\Middleware;

use FamilyQuiz\Services\AuthService;
use FamilyQuiz\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $auth) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $token = $cookies['fq_admin'] ?? null;
        if (!$token || !is_string($token)) {
            return JsonResponse::error('UNAUTHENTICATED', 'Admin login required', 401);
        }
        $payload = $this->auth->parseAdminToken($token);
        if (!$payload) {
            return JsonResponse::error('UNAUTHENTICATED', 'Invalid or expired session', 401);
        }
        $user = $this->auth->findAdminById((string) $payload['sub']);
        if (!$user || !(int) $user['is_active']) {
            return JsonResponse::error('UNAUTHENTICATED', 'Admin not found', 401);
        }
        return $handler->handle($request->withAttribute('admin', $user));
    }
}
