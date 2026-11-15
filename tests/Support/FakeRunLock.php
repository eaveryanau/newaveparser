<?php

namespace App\Tests\Support;

use App\Service\RunLockInterface;

final class FakeRunLock implements RunLockInterface
{
    public function __construct(private bool $acquired = true)
    {
    }

    public function acquire(string $source): bool
    {
        return $this->acquired;
    }

    public function release(): void
    {
    }
}
