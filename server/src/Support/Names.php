<?php

declare(strict_types=1);

namespace FamilyQuiz\Support;

use Normalizer;

final class Names
{
    public static function normalizeDisplay(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return $name;
    }

    public static function nameKey(string $display): string
    {
        $s = self::normalizeDisplay($display);
        if (class_exists(Normalizer::class)) {
            $n = Normalizer::normalize($s, Normalizer::FORM_KD);
            if (is_string($n)) {
                $s = $n;
            }
        }
        // Strip combining marks
        $s = preg_replace('/\p{Mn}/u', '', $s) ?? $s;
        return mb_strtolower($s, 'UTF-8');
    }

    public static function isValid(string $display): bool
    {
        $name = self::normalizeDisplay($display);
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 2 || $len > 40) {
            return false;
        }
        return (bool) preg_match("/^[\\p{L}\\p{M}\\p{N} \\-'.]+$/u", $name);
    }
}
