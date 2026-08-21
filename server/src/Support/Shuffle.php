<?php

declare(strict_types=1);

namespace FamilyQuiz\Support;

/**
 * Deterministic shuffle — mulberry32 + Fisher–Yates.
 * Spec: seed PRNG with crc32("$seed:$salt"); unit tests freeze known permutations.
 */
final class Shuffle
{
    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public static function seededShuffle(array $items, int $seed, string $salt): array
    {
        $arr = array_values($items);
        $n = count($arr);
        if ($n <= 1) {
            return $arr;
        }

        $state = self::hash32($seed . ':' . $salt);

        for ($i = $n - 1; $i > 0; $i--) {
            [$state, $r] = self::nextFloat($state);
            $j = (int) floor($r * ($i + 1));
            $tmp = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $tmp;
        }

        return $arr;
    }

    /** @return array{0: int, 1: float} new state and float in [0, 1) */
    public static function nextFloat(int $state): array
    {
        $a = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = self::imul($a ^ ($a >> 15), 1 | $a);
        $t = ($t + self::imul($t ^ ($t >> 7), 61 | $t)) ^ $t;
        $out = ($t ^ ($t >> 14)) & 0xFFFFFFFF;
        return [$a, $out / 4294967296];
    }

    public static function hash32(string $input): int
    {
        return crc32($input) & 0xFFFFFFFF;
    }

    /** 32-bit imul matching Math.imul */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;
        return (((($al * $bl) + ((($ah * $bl + $al * $bh) & 0xFFFF) << 16)) & 0xFFFFFFFF));
    }
}
