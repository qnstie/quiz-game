<?php

declare(strict_types=1);

namespace FamilyQuiz\Middleware;

use FamilyQuiz\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            // Same-origin navigations / curl without Origin — allow in local; production SPA always sends Origin
            return $handler->handle($request);
        }
        if (!in_array($origin, $this->allowedOrigins, true)) {
            return JsonResponse::error('FORBIDDEN', 'Origin not allowed', 403);
        }
        return $handler->handle($request);
    }
}
