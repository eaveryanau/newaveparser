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
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->initTable();
        $this->initOutbox();
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

    private function initOutbox(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS telegram_outbox (
                id VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NOT NULL,
                last_error TEXT,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id, source)
            )
        ');
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_telegram_outbox_due
            ON telegram_outbox (source, next_attempt_at)
        ');
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }
}
