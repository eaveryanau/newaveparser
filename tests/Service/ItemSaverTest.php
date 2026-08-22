<?php

namespace App\Tests\Service;

use App\DTO\Item as DTOItem;
use App\Service\ItemSaver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ItemSaverTest extends TestCase
{
    private \PDO $pdo;
    private ItemSaver $saver;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE items (
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

        // Создаем Database-like обертку с in-memory PDO
        $database = new class($this->pdo) extends \App\Service\Database {
            private \PDO $pdo;
            public function __construct(\PDO $pdo) {
                $this->pdo = $pdo;
            }
            public function getPdo(): \PDO {
                return $this->pdo;
            }
        };

        $this->saver = new ItemSaver($database, new NullLogger());
    }

    /**
     * Создаёт Database-like обертку с in-memory PDO
     */
    private function createDatabase(): \App\Service\Database
    {
        return new class($this->pdo) extends \App\Service\Database {
            private \PDO $pdo;
            public function __construct(\PDO $pdo) {
                $this->pdo = $pdo;
            }
            public function getPdo(): \PDO {
                return $this->pdo;
            }
        };
    }

    public function testSaveNewItem(): void
    {
        $database = $this->createDatabase();
        $saver = new ItemSaver($database, new NullLogger());

        $item = new DTOItem();
        $item->id = '1';
        $item->source = 'avby';
        $item->title = 'Test Car';
        $item->price = 10000;
        $item->currency = 'USD';
        $item->url = 'https://example.com/1';

        $result = $saver->save($item);

        self::assertTrue($result['saved']);
        self::assertTrue($result['isNew']);
        self::assertFalse($result['isPriceChanged']);
        self::assertNull($result['oldPrice']);
    }

    public function testSaveExistingItemSamePrice(): void
    {
        // Предварительно вставляем запись
        $this->pdo->exec("INSERT INTO items (id, source, title, description, price, currency, url, image_url, created_at) VALUES ('2', 'avby', 'Old Title', '', 5000, 'USD', 'https://example.com/2', NULL, '2024-01-01 00:00:00')");

        $item = new DTOItem();
        $item->id = '2';
        $item->source = 'avby';
        $item->title = 'Updated Title';
        $item->price = 5000;
        $item->currency = 'USD';
        $item->url = 'https://example.com/2';

        $database = $this->createDatabase();
        $saver = new ItemSaver($database, new NullLogger());
        $result = $saver->save($item);

        self::assertTrue($result['saved']);
        self::assertFalse($result['isNew']);
        self::assertFalse($result['isPriceChanged']);
        self::assertSame(5000.0, $result['oldPrice']);
    }

    public function testSaveExistingItemDifferentPrice(): void
    {
        $this->pdo->exec("INSERT INTO items (id, source, title, description, price, currency, url, image_url, created_at) VALUES ('3', 'hh', 'Old Job', '', 3000, 'USD', 'https://example.com/3', NULL, '2024-01-01 00:00:00')");

        $item = new DTOItem();
        $item->id = '3';
        $item->source = 'hh';
        $item->title = 'Updated Job';
        $item->price = 4000;
        $item->currency = 'USD';
        $item->url = 'https://example.com/3';

        $database = $this->createDatabase();
        $saver = new ItemSaver($database, new NullLogger());
        $result = $saver->save($item);

        self::assertTrue($result['saved']);
        self::assertFalse($result['isNew']);
        self::assertTrue($result['isPriceChanged']);
        self::assertSame(3000.0, $result['oldPrice']);
    }
}