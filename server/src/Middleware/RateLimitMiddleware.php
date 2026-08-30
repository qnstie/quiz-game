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
    /**
     * @param string|null $bodyKey If set (e.g. "email"), bucket by that JSON/body field
     *                            instead of client IP. Empty values fall back to IP.
     * @param bool $countFailuresOnly When true, only failed responses (4xx except 429)
     *                                increment the counter; 2xx clears the bucket.
     */
    public function __construct(
        private string $bucketPrefix,
        private int $max,
        private int $windowSeconds,
        private ?string $bodyKey = null,
        private bool $countFailuresOnly = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->bucketKey($request);
        $windowStart = $this->windowStart();

        if ($this->currentCount($key, $windowStart) >= $this->max) {
            return JsonResponse::error('CONFLICT', 'Rate limit exceeded', 429);
        }

        if (!$this->countFailuresOnly) {
            $this->increment($key, $windowStart);
            if ($this->currentCount($key, $windowStart) > $this->max) {
                return JsonResponse::error('CONFLICT', 'Rate limit exceeded', 429);
            }
            return $handler->handle($request);
        }

        $response = $handler->handle($request);
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 400) {
            self::clearBucket($this->bucketPrefix, $this->identityFromRequest($request));
        } elseif ($status >= 400 && $status !== 429) {
            $this->increment($key, $windowStart);
        }
        return $response;
    }

    /** Clear all windows for a bucket identity (e.g. after successful login). */
    public static function clearBucket(string $bucketPrefix, string $identity): void
    {
        $identity = self::normalizeIdentity($identity);
        if ($identity === '') {
            return;
        }
        Connections::appDb()
            ->prepare('DELETE FROM rate_limits WHERE bucket_key = :k')
            ->execute(['k' => $bucketPrefix . ':' . $identity]);
    }

    private function bucketKey(ServerRequestInterface $request): string
    {
        return $this->bucketPrefix . ':' . $this->identityFromRequest($request);
    }

    private function identityFromRequest(ServerRequestInterface $request): string
    {
        if ($this->bodyKey !== null) {
            $body = (array) ($request->getParsedBody() ?? []);
            $value = strtolower(trim((string) ($body[$this->bodyKey] ?? '')));
            if ($value !== '') {
                return self::normalizeIdentity($value);
            }
        }
        return self::normalizeIdentity((string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    private static function normalizeIdentity(string $identity): string
    {
        $identity = strtolower(trim($identity));
        // Keep keys short/safe for SQLite
        return substr(preg_replace('/[^a-z0-9@._\-:]/', '_', $identity) ?? $identity, 0, 180);
    }

    private function windowStart(): int
    {
        return (int) (floor(time() / $this->windowSeconds) * $this->windowSeconds);
    }

    private function currentCount(string $key, int $windowStart): int
    {
        $stmt = Connections::appDb()->prepare(
            'SELECT count FROM rate_limits WHERE bucket_key = :k AND window_start = :w'
        );
        $stmt->execute(['k' => $key, 'w' => $windowStart]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function increment(string $key, int $windowStart): void
    {
        Connections::appDb()->prepare(
            'INSERT INTO rate_limits (bucket_key, window_start, count) VALUES (:k, :w, 1)
             ON CONFLICT(bucket_key, window_start) DO UPDATE SET count = count + 1'
        )->execute(['k' => $key, 'w' => $windowStart]);
    }
}
