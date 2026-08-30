<?php

declare(strict_types=1);

namespace FamilyQuiz\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * HttpOnly session cookies scoped to the app path prefix.
 *
 * Staging and production share www.kunstman.net. Cookies with Path=/ collide:
 * a production Secure cookie cannot be overwritten by a non-Secure staging
 * Set-Cookie, so staging login appears to succeed then /me fails.
 */
final class SessionCookie
{
    public static function path(array $config): string
    {
        $explicit = (string) ($config['url_path_prefix'] ?? '');
        if ($explicit !== '') {
            return rtrim($explicit, '/') ?: '/';
        }
        $base = (string) ($config['public_base_url'] ?? '');
        $path = parse_url($base, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return '/';
        }
        return rtrim($path, '/');
    }

    public static function secure(array $config): bool
    {
        $base = (string) ($config['public_base_url'] ?? '');
        if (str_starts_with($base, 'https://')) {
            return true;
        }
        return ($config['app_env'] ?? '') === 'production';
    }

    /** @return list<string> */
    public static function setHeaders(string $name, string $value, array $config, int $maxAge): array
    {
        $path = self::path($config);
        $flags = self::flags($config);
        $headers = [];
        // Drop legacy Path=/ cookies that shadowed subdirectory deploys.
        if ($path !== '/') {
            $headers[] = "{$name}=; Path=/; Max-Age=0; {$flags}";
        }
        $headers[] = "{$name}={$value}; Path={$path}; Max-Age={$maxAge}; {$flags}";
        return $headers;
    }

    /** @return list<string> */
    public static function clearHeaders(string $name, array $config): array
    {
        $path = self::path($config);
        $flags = self::flags($config);
        $headers = ["{$name}=; Path={$path}; Max-Age=0; {$flags}"];
        if ($path !== '/') {
            $headers[] = "{$name}=; Path=/; Max-Age=0; {$flags}";
        }
        return $headers;
    }

    public static function apply(ResponseInterface $response, array $setCookieHeaders): ResponseInterface
    {
        foreach ($setCookieHeaders as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }
        return $response;
    }

    private static function flags(array $config): string
    {
        $parts = ['HttpOnly', 'SameSite=Lax'];
        if (!empty($config['cookie_domain'])) {
            $parts[] = 'Domain=' . $config['cookie_domain'];
        }
        if (self::secure($config)) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }
}
