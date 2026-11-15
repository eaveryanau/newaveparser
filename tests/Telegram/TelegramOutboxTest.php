<?php

namespace App\Tests\Telegram;

use App\DTO\Item;
use App\Telegram\TelegramOutbox;
use App\Tests\Support\InMemoryDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TelegramOutboxTest extends TestCase
{
    private InMemoryDatabase $database;
    private TelegramOutbox $outbox;

    protected function setUp(): void
    {
        $this->database = new InMemoryDatabase();
        $this->outbox = new TelegramOutbox($this->database, new NullLogger());
    }

    public function testRecordFailureSchedulesRetry(): void
    {
        $this->outbox->recordFailure($this->item('1'), 'network error');

        self::assertSame(1, $this->outbox->count('test'));

        $row = $this->row('1');
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('network error', $row['last_error']);
        self::assertGreaterThan(time() + 30, strtotime((string) $row['next_attempt_at']));
    }

    public function testRetryAfterExtendsDelay(): void
    {
        $this->outbox->recordFailure($this->item('1'), 'rate limit', 7200);

        $row = $this->row('1');
        self::assertGreaterThan(time() + 7000, strtotime((string) $row['next_attempt_at']));
    }

    public function testRepeatedFailureIncrementsAttemptsAndBackoff(): void
    {
        $this->outbox->recordFailure($this->item('1'), 'first');
        $firstDelay = strtotime((string) $this->row('1')['next_attempt_at']);

        $this->outbox->recordFailure($this->item('1'), 'second');
        $row = $this->row('1');

        self::assertSame(2, (int) $row['attempts']);
        self::assertSame('second', $row['last_error']);
        self::assertGreaterThan($firstDelay, strtotime((string) $row['next_attempt_at']));
    }

    public function testDueFiltersBySourceAndLimit(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->outbox->recordFailure($this->item((string) $i), 'error');
        }
        $other = $this->item('other');
        $other->source = 'other';
        $this->outbox->recordFailure($other, 'error');
        $this->forceDue();

        self::assertCount(10, $this->outbox->due('test'));
        self::assertCount(1, $this->outbox->due('other'));
    }

    public function testDueSkipsFutureEntries(): void
    {
        $this->outbox->recordFailure($this->item('1'), 'error');

        self::assertSame([], $this->outbox->due('test'));
    }

    public function testMarkFailedIncrementsAttempts(): void
    {
        $this->outbox->recordFailure($this->item('1'), 'first');
        $this->forceDue();

        $entry = $this->outbox->due('test')[0];
        $this->outbox->markFailed($entry, 'second');

        $row = $this->row('1');
        self::assertSame(2, (int) $row['attempts']);
        self::assertSame('second', $row['last_error']);
    }

    public function testEntryIsNotDueAfterMaxAttempts(): void
    {
        for ($i = 0; $i < TelegramOutbox::maxAttempts(); $i++) {
            $this->outbox->recordFailure($this->item('1'), 'error ' . $i);
        }

        self::assertSame([], $this->outbox->due('test'));
        self::assertSame('9999-12-31 23:59:59', $this->row('1')['next_attempt_at']);
    }

    public function testRemoveDeletesEntry(): void
    {
        $this->outbox->recordFailure($this->item('1'), 'error');

        $this->outbox->remove('1', 'test');

        self::assertSame(0, $this->outbox->count('test'));
    }

    public function testPayloadRoundTripPreservesFields(): void
    {
        $item = $this->item('42');
        $this->outbox->recordFailure($item, 'error');
        $this->forceDue();

        $entry = $this->outbox->due('test')[0];
        $restored = $entry->item;

        self::assertSame($item->id, $restored->id);
        self::assertSame($item->source, $restored->source);
        self::assertSame($item->title, $restored->title);
        self::assertSame($item->description, $restored->description);
        self::assertSame($item->price, $restored->price);
        self::assertSame($item->currency, $restored->currency);
        self::assertSame($item->url, $restored->url);
        self::assertSame($item->imageUrl, $restored->imageUrl);
        self::assertSame($item->generation, $restored->generation);
        self::assertSame($item->generationWithYears, $restored->generationWithYears);
        self::assertSame($item->engineCapacity, $restored->engineCapacity);
        self::assertSame($item->engineType, $restored->engineType);
        self::assertSame($item->transmissionType, $restored->transmissionType);
        self::assertSame($item->mileage, $restored->mileage);
        self::assertSame($item->brand, $restored->brand);
        self::assertSame($item->model, $restored->model);
    }

    public function testPayloadWithoutMileageDecodesAsNull(): void
    {
        $payload = json_encode([
            'id' => '1',
            'source' => 'test',
            'title' => 'Test item',
            'description' => null,
            'price' => 100.0,
            'currency' => 'USD',
            'url' => 'https://example.com/1',
            'imageUrl' => null,
        ], JSON_THROW_ON_ERROR);
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->database->getPdo()->prepare(
            'INSERT INTO telegram_outbox
                (id, source, payload, attempts, next_attempt_at, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['1', 'test', $payload, 0, '2000-01-01 00:00:00', null, $now, $now]);

        $entry = $this->outbox->due('test')[0];

        self::assertNull($entry->item->mileage);
    }

    public function testCorruptPayloadIsQuarantined(): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->database->getPdo()->prepare(
            'INSERT INTO telegram_outbox
                (id, source, payload, attempts, next_attempt_at, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['1', 'test', '{invalid', 0, '2000-01-01 00:00:00', null, $now, $now]);

        self::assertSame([], $this->outbox->due('test'));
        self::assertSame('9999-12-31 23:59:59', $this->row('1')['next_attempt_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id): array
    {
        $stmt = $this->database->getPdo()->prepare(
            'SELECT * FROM telegram_outbox WHERE id = ? AND source = ?'
        );
        $stmt->execute([$id, 'test']);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function forceDue(): void
    {
        $this->database->getPdo()->exec(
            "UPDATE telegram_outbox SET next_attempt_at = '2000-01-01 00:00:00'"
        );
    }

    private function item(string $id): Item
    {
        $item = new Item();
        $item->id = $id;
        $item->source = 'test';
        $item->title = 'Test item ' . $id;
        $item->description = 'Description ' . $id;
        $item->price = 100.0;
        $item->currency = 'USD';
        $item->url = 'https://example.com/' . $id;
        $item->imageUrl = 'https://example.com/' . $id . '.jpg';
        $item->generation = 'Generation';
        $item->generationWithYears = 'Generation (2000-2005)';
        $item->engineCapacity = '2.0';
        $item->engineType = 'Дизель';
        $item->transmissionType = 'Автомат';
        $item->mileage = 580000;
        $item->brand = 'Brand';
        $item->model = 'Model';

        return $item;
    }
}
