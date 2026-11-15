<?php

namespace App\Service;

class Database
{
    private \PDO $pdo;

    public function __construct(string $databaseUrl)
    {
        $path = str_replace('sqlite:', '', $databaseUrl);
        if ($path !== ':memory:' && $path !== '' && $path[0] !== '/') {
            $path = __DIR__ . '/../../' . $path;
        }
        $this->pdo = new \PDO('sqlite:' . $path);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->initTable();
    }

    private function initTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS items (
                id VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                price FLOAT NOT NULL,
                currency VARCHAR(8) NOT NULL,
                url VARCHAR(512) NOT NULL,
                image_url VARCHAR(512),
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id, source)
            )
        ');
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }
}
