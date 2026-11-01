<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Health;

use App\Support\Health\Application\HealthProbe;

final readonly class StubHealthProbe implements HealthProbe
{
    private function __construct(
        private string $name,
        private ?\Throwable $failure,
    ) {
    }

    public static function ok(string $name): self
    {
        return new self($name, null);
    }

    public static function failing(string $name, \Throwable $failure): self
    {
        return new self($name, $failure);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function check(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
