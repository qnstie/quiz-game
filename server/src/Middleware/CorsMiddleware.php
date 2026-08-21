<?php

declare(strict_types=1);

namespace FamilyQuiz\Middleware;

use FamilyQuiz\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @param list<string> $origins */
    public function __construct(private array $origins, private string $cookieDomain = '') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allow = in_array($origin, $this->origins, true) ? $origin : ($this->origins[0] ?? '*');

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = new \Slim\Psr7\Response(204);
            return $this->withCors($response, $allow);
        }

        $response = $handler->handle($request);
        return $this->withCors($response, $allow);
    }

    private function withCors(ResponseInterface $response, string $allow): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allow)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-FQ-Token')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }
}
