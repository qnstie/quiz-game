<?php

declare(strict_types=1);

namespace FamilyQuiz\Support;

final class ConfigBag
{
    public function __construct(private array $data) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->data;
    }

    /** URL path prefix when hosted in a subdirectory, e.g. "/familyquiz" (no trailing slash). */
    public function pathPrefix(): string
    {
        $explicit = (string) ($this->data['url_path_prefix'] ?? '');
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }
        $base = (string) ($this->data['public_base_url'] ?? '');
        $path = parse_url($base, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return '';
        }
        return rtrim($path, '/');
    }

    /** Prefix a site-absolute path like "/media/…" for subdirectory deploys. */
    public function webPath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $prefix = $this->pathPrefix();
        return $prefix === '' ? $path : $prefix . $path;
    }

    public function offsetGet(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
