<?php

declare(strict_types=1);

namespace FamilyQuiz\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $csp = "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; "
            . "frame-src https://www.youtube.com https://youtube.com https://www.youtube-nocookie.com "
            . "https://youtube-nocookie.com https://player.vimeo.com https://w.soundcloud.com; "
            . "script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' https:; "
            . "object-src 'none'; base-uri 'self'";

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Content-Security-Policy', $csp);
    }
}
