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

    public function testAvbyPriceIncreaseAtThresholdDoesNotNotifyButUpdatesPrice(): void
    {
        $this->insertItem('4', 'avby', 5000.0);

        $result = $this->saver->save($this->item('4', 'avby', 5100.0));

        self::assertFalse($result['isPriceChanged']);
        self::assertSame(5100.0, $this->storedPrice('4', 'avby'));
    }

    public function testAvbyPriceIncreaseAboveThresholdNotifies(): void
    {
        $this->insertItem('5', 'avby', 5000.0);

        $result = $this->saver->save($this->item('5', 'avby', 5100.01));

        self::assertTrue($result['isPriceChanged']);
    }

    public function testAvbyPriceDecreaseAtThresholdDoesNotNotify(): void
    {
        $this->insertItem('6', 'avby', 5000.0);

        $result = $this->saver->save($this->item('6', 'avby', 4900.0));

        self::assertFalse($result['isPriceChanged']);
    }

    public function testAvbyPriceDecreaseAboveThresholdNotifies(): void
    {
        $this->insertItem('7', 'avby', 5000.0);

        $result = $this->saver->save($this->item('7', 'avby', 4899.99));

        self::assertTrue($result['isPriceChanged']);
    }

    public function testHhSmallPriceChangeStillNotifies(): void
    {
        $this->insertItem('8', 'hh', 3000.0);

        $result = $this->saver->save($this->item('8', 'hh', 3000.01));

        self::assertTrue($result['isPriceChanged']);
    }

    private function insertItem(string $id, string $source, float $price): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO items
                (id, source, title, description, price, currency, url, image_url, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $source,
            'Old title',
            '',
            $price,
            'USD',
            "https://example.com/$id",
            null,
            '2024-01-01 00:00:00',
        ]);
    }

    private function item(string $id, string $source, float $price): DTOItem
    {
        $item = new DTOItem();
        $item->id = $id;
        $item->source = $source;
        $item->title = 'Updated title';
        $item->price = $price;
        $item->currency = 'USD';
        $item->url = "https://example.com/$id";

        return $item;
    }

    private function storedPrice(string $id, string $source): float
    {
        $stmt = $this->pdo->prepare('SELECT price FROM items WHERE id = ? AND source = ?');
        $stmt->execute([$id, $source]);

        return (float) $stmt->fetchColumn();
    }
}