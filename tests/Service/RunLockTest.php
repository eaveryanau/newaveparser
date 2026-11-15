<?php

namespace App\Tests\Service;

use App\Service\RunLock;
use PHPUnit\Framework\TestCase;

class RunLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/newaveparser-lock-' . uniqid('', true);
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAcquireAndRelease(): void
    {
        $lock = new RunLock($this->directory);

        self::assertTrue($lock->acquire('avby'));
        $lock->release();
        self::assertTrue($lock->acquire('avby'));
        $lock->release();
    }

    public function testSecondLockIsRejectedWhileFirstIsHeld(): void
    {
        $first = new RunLock($this->directory);
        $second = new RunLock($this->directory);

        self::assertTrue($first->acquire('avby'));
        self::assertFalse($second->acquire('avby'));

        $first->release();
        self::assertTrue($second->acquire('avby'));
        $second->release();
    }

    public function testDifferentSourcesUseDifferentLocks(): void
    {
        $first = new RunLock($this->directory);
        $second = new RunLock($this->directory);

        self::assertTrue($first->acquire('avby'));
        self::assertTrue($second->acquire('hh'));

        $first->release();
        $second->release();
    }
}
