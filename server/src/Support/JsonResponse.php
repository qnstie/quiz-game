<?php

declare(strict_types=1);

namespace FamilyQuiz\Support;

final class JsonResponse
{
    public static function ok(mixed $data, int $status = 200): \Slim\Psr7\Response
    {
        return self::json($data, $status);
    }

    public static function error(string $code, string $message, int $status, array $extra = []): \Slim\Psr7\Response
    {
        return self::json([
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status);
    }

    public static function json(mixed $data, int $status = 200): \Slim\Psr7\Response
    {
        $response = new \Slim\Psr7\Response($status);
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{"error":{"code":"INTERNAL","message":"JSON encode failed"}}';
            $response = $response->withStatus(500);
        }
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
