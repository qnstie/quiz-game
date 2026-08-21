<?php

declare(strict_types=1);

namespace FamilyQuiz\Middleware;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $bucketPrefix,
        private int $max,
        private int $windowSeconds,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = $this->bucketPrefix . ':' . $ip;
        $windowStart = (int) (floor(time() / $this->windowSeconds) * $this->windowSeconds);

        $pdo = Connections::appDb();
        $pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, window_start, count) VALUES (:k, :w, 1)
             ON CONFLICT(bucket_key, window_start) DO UPDATE SET count = count + 1'
        )->execute(['k' => $key, 'w' => $windowStart]);

        $stmt = $pdo->prepare('SELECT count FROM rate_limits WHERE bucket_key = :k AND window_start = :w');
        $stmt->execute(['k' => $key, 'w' => $windowStart]);
        $count = (int) $stmt->fetchColumn();

        if ($count > $this->max) {
            return JsonResponse::error('CONFLICT', 'Rate limit exceeded', 429);
        }

        return $handler->handle($request);
    }
}
