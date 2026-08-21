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

    public function offsetGet(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
