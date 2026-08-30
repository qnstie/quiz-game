<?php

declare(strict_types=1);

namespace FamilyQuiz\Middleware;

use FamilyQuiz\Support\ConfigBag;
use FamilyQuiz\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates LLM / automation clients with the shared magic token.
 * Accepts Authorization: Bearer <token>, X-Agent-Token, or ?t= / ?token=.
 * Uses agent_api_token when set, otherwise admin_magic_token.
 */
final class AgentTokenMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigBag $config) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = $this->expectedToken();
        if ($expected === '' || str_contains($expected, 'GENERATE_WITH') || strlen($expected) < 32) {
            return JsonResponse::error('MISCONFIGURED', 'Agent API token is not configured', 503);
        }

        $provided = $this->extractToken($request);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return JsonResponse::error('UNAUTHENTICATED', 'Invalid or missing agent token', 401);
        }

        return $handler->handle($request->withAttribute('agentAuth', true));
    }

    private function expectedToken(): string
    {
        $agent = (string) ($this->config->get('agent_api_token') ?? '');
        if ($agent !== '' && !str_contains($agent, 'GENERATE_WITH')) {
            return $agent;
        }
        return (string) ($this->config->get('admin_magic_token') ?? '');
    }

    private function extractToken(ServerRequestInterface $request): string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            return $m[1];
        }
        $header = $request->getHeaderLine('X-Agent-Token');
        if ($header !== '') {
            return $header;
        }
        $q = $request->getQueryParams();
        return (string) ($q['t'] ?? $q['token'] ?? '');
    }
}
