<?php

namespace App\Service;

interface RunLockInterface
{
    public function acquire(string $source): bool;

    public function release(): void;
}
