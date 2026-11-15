<?php

namespace App\Service;

final class RunLock implements RunLockInterface
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private ?string $directory = null)
    {
    }

    public function acquire(string $source): bool
    {
        $directory = $this->directory ?? dirname(__DIR__, 2) . '/var';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $safeSource = preg_replace('/[^a-zA-Z0-9_-]/', '_', $source) ?: 'default';
        $path = $directory . '/parse-' . $safeSource . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
