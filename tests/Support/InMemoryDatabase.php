<?php

namespace App\Tests\Support;

use App\Service\Database;

final class InMemoryDatabase extends Database
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }
}
