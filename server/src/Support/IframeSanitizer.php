<?php

declare(strict_types=1);

namespace FamilyQuiz\Support;

final class IframeSanitizer
{
    private const ALLOWED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
        'w.soundcloud.com',
    ];

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public function extract(string $html): array
    {
        $tokens = [];
        $i = 0;
        $out = preg_replace_callback(
            '/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*(?:\/>|>\s*<\/iframe>)/i',
            function (array $m) use (&$tokens, &$i): string {
                $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!$this->isAllowed($src)) {
                    return '';
                }
                $token = '%%IFRAME_' . $i++ . '%%';
                $tokens[$token] = '<iframe src="' . htmlspecialchars($src, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
                    . ' allowfullscreen loading="lazy"></iframe>';
                return $token;
            },
            $html
        );
        return [$out ?? $html, $tokens];
    }

    /**
     * @param array<string, string> $tokens
     */
    public function restore(string $html, array $tokens): string
    {
        return strtr($html, $tokens);
    }

    public function isAllowed(string $src): bool
    {
        $parts = parse_url($src);
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            return false;
        }
        $path = $parts['path'] ?? '';
        if (str_contains($host, 'youtube') && !str_starts_with($path, '/embed/')) {
            return false;
        }
        if ($host === 'player.vimeo.com' && !str_starts_with($path, '/video/')) {
            return false;
        }
        return true;
    }
}
