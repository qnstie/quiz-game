<?php

declare(strict_types=1);

namespace FamilyQuiz\Tests;

use FamilyQuiz\Support\Shuffle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShuffleTest extends TestCase
{
    public function testDeterministicPermutation(): void
    {
        $items = ['a', 'b', 'c', 'd'];
        $a = Shuffle::seededShuffle($items, 42, 'quiz-1');
        $b = Shuffle::seededShuffle($items, 42, 'quiz-1');
        $this->assertSame($a, $b);
        $sorted = $a;
        sort($sorted);
        $this->assertSame(['a', 'b', 'c', 'd'], $sorted);
    }

    public function testDifferentSeedsDiffer(): void
    {
        $items = range(0, 9);
        $a = Shuffle::seededShuffle($items, 1, 'x');
        $b = Shuffle::seededShuffle($items, 2, 'x');
        $this->assertNotSame($a, $b);
    }

    #[DataProvider('fixtureProvider')]
    public function testKnownFixtures(int $seed, string $salt, array $expected): void
    {
        $items = ['A', 'B', 'C', 'D'];
        $this->assertSame($expected, Shuffle::seededShuffle($items, $seed, $salt));
    }

    public static function fixtureProvider(): array
    {
        // Frozen permutations — do not regenerate via Shuffle here (PHPUnit deprecation).
        return [
            'seed-1' => [1, 'q1', ['A', 'B', 'D', 'C']],
            'seed-99' => [99, 'salt', ['B', 'C', 'D', 'A']],
            'seed-12345' => [12345, 'quizzes', ['C', 'D', 'A', 'B']],
        ];
    }

    public function testSingleAndEmpty(): void
    {
        $this->assertSame([], Shuffle::seededShuffle([], 1, 'x'));
        $this->assertSame(['only'], Shuffle::seededShuffle(['only'], 1, 'x'));
    }
}
