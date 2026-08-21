<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use RuntimeException;

final class LockedException extends RuntimeException
{
    public function __construct(public readonly string $currentState)
    {
        parent::__construct('LOCKED');
    }
}
