<?php

namespace App\Tests\Command;

use App\Client\ApiClientInterface;
use App\Command\ParseCommand;
use App\DTO\Item;
use App\Normalizer\ItemNormalizerInterface;
use App\Service\ItemSaver;
use App\Service\RunLockInterface;
use App\Telegram\SendResult;
use App\Telegram\TelegramOutbox;
use App\Tests\Support\FakeRunLock;
use App\Tests\Support\InMemoryDatabase;
use App\Tests\Support\RecordingSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ParseCommandTest extends TestCase
{
    public function testUnknownSourceFails(): void
    {
        [$tester] = $this->createTester([], new RecordingSender('test'));

        $status = $tester->execute(['--source' => 'missing']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Неизвестный источник', $tester->getDisplay());
    }

    public function testMissingSenderFailsAtRuntime(): void
    {
        [$tester] = $this->createTester([], new RecordingSender('another-source'));

        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Нет отправителя', $tester->getDisplay());
    }

    public function testNoTgSavesWithoutSendingAndDoesNotRequireSender(): void
    {
        $sender = new RecordingSender('another-source');

        [$tester, $database] = $this->createTester($this->pages(1), $sender);
        $status = $tester->execute(['--source' => 'test', '--no-tg' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(0, $sender->sent);
        self::assertSame(1, $this->countItems($database));
    }

    public function testLimitStopsProcessing(): void
    {
        [$tester, $database] = $this->createTester($this->pages(3), new RecordingSender('test'));

        $status = $tester->execute(['--source' => 'test', '--limit' => 2, '--no-tg' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Достигнут лимит', $tester->getDisplay());
        self::assertSame(2, $this->countItems($database));
    }

    public function testNewItemIsSentToTelegram(): void
    {
        $sender = new RecordingSender('test');

        [$tester] = $this->createTester($this->pages(1), $sender);
        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $sender->sent);
        self::assertSame('1', $sender->sent[0]->id);
    }

    public function testForceSendsExistingItem(): void
    {
        $database = new InMemoryDatabase();
        $saver = new ItemSaver($database, new NullLogger());
        $sender = new RecordingSender('test');

        $saver->save($this->item('1'));

        [$tester] = $this->createTester($this->pages(1), $sender, $database);
        $status = $tester->execute(['--source' => 'test', '--force' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $sender->sent);
    }

    public function testUnchangedItemIsNotSentWithoutForce(): void
    {
        $database = new InMemoryDatabase();
        $saver = new ItemSaver($database, new NullLogger());
        $sender = new RecordingSender('test');

        $saver->save($this->item('1'));

        [$tester] = $this->createTester($this->pages(1), $sender, $database);
        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(0, $sender->sent);
    }

    public function testRetryableSendFailureIsQueued(): void
    {
        $sender = new RecordingSender('test');
        $sender->queueResult(SendResult::retryable('network error'));

        [$tester, $database, $outbox] = $this->createTester($this->pages(1), $sender);
        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $sender->sent);
        self::assertSame(1, $this->countItems($database));
        self::assertSame(1, $outbox->count('test'));
        self::assertStringContainsString('добавлено в очередь', $tester->getDisplay());
    }

    public function testPermanentSendFailureIsNotQueued(): void
    {
        $sender = new RecordingSender('test');
        $sender->queueResult(SendResult::permanent('bad request'));

        [$tester, , $outbox] = $this->createTester($this->pages(1), $sender);
        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $sender->sent);
        self::assertSame(0, $outbox->count('test'));
    }

    public function testDueOutboxItemIsRetriedOnNextRun(): void
    {
        $database = new InMemoryDatabase();
        $outbox = new TelegramOutbox($database, new NullLogger());
        $outbox->recordFailure($this->item('1'), 'network error');
        $database->getPdo()->exec("UPDATE telegram_outbox SET next_attempt_at = '2000-01-01 00:00:00'");

        $sender = new RecordingSender('test');

        [$tester] = $this->createTester([], $sender, $database);
        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $sender->sent);
        self::assertSame('1', $sender->sent[0]->id);
        self::assertSame(0, $outbox->count('test'));
        self::assertStringContainsString('Из очереди отправлено', $tester->getDisplay());
    }

    public function testNoTgDoesNotDrainOutbox(): void
    {
        $database = new InMemoryDatabase();
        $outbox = new TelegramOutbox($database, new NullLogger());
        $outbox->recordFailure($this->item('1'), 'network error');
        $database->getPdo()->exec("UPDATE telegram_outbox SET next_attempt_at = '2000-01-01 00:00:00'");

        $sender = new RecordingSender('test');

        [$tester] = $this->createTester([], $sender, $database);
        $status = $tester->execute(['--source' => 'test', '--no-tg' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(0, $sender->sent);
        self::assertSame(1, $outbox->count('test'));
    }

    public function testForceDoesNotDuplicateItemSentFromOutbox(): void
    {
        $database = new InMemoryDatabase();
        $outbox = new TelegramOutbox($database, new NullLogger());
        $outbox->recordFailure($this->item('1'), 'network error');
        $database->getPdo()->exec("UPDATE telegram_outbox SET next_attempt_at = '2000-01-01 00:00:00'");

        $sender = new RecordingSender('test');

        [$tester] = $this->createTester($this->pages(1), $sender, $database);
        $status = $tester->execute(['--source' => 'test', '--force' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $sender->sent);
        self::assertSame(0, $outbox->count('test'));
        self::assertStringContainsString('Уже отправлено из очереди', $tester->getDisplay());
    }

    public function testBusyLockSkipsRun(): void
    {
        $sender = new RecordingSender('test');

        [$tester] = $this->createTester([], $sender, null, new FakeRunLock(false));
        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(0, $sender->sent);
        self::assertStringContainsString('уже выполняется', $tester->getDisplay());
    }

    /**
     * @return array{0: CommandTester, 1: InMemoryDatabase, 2: TelegramOutbox}
     */
    private function createTester(
        array $pages,
        RecordingSender $sender,
        ?InMemoryDatabase $database = null,
        ?RunLockInterface $runLock = null,
        string $source = 'test'
    ): array {
        $database ??= new InMemoryDatabase();
        $saver = new ItemSaver($database, new NullLogger());
        $outbox = new TelegramOutbox($database, new NullLogger());

        $client = new class($pages, $source) implements ApiClientInterface {
            public function __construct(private array $pages, private string $source)
            {
            }

            public function fetchPage(int $page): array
            {
                return $this->pages[$page] ?? ['count' => 0, 'pageCount' => 0, 'adverts' => []];
            }

            public function getSource(): string
            {
                return $this->source;
            }
        };

        $normalizer = new class($source) implements ItemNormalizerInterface {
            public function __construct(private string $source)
            {
            }

            public function normalize(array $raw): Item
            {
                $item = new Item();
                $item->id = (string) $raw['id'];
                $item->source = $this->source;
                $item->title = 'Test item ' . $item->id;
                $item->price = (float) ($raw['price'] ?? 100);
                $item->currency = 'USD';
                $item->url = 'https://example.com/' . $item->id;

                return $item;
            }

            public function supports(string $source): bool
            {
                return $source === $this->source;
            }
        };

        $tester = new CommandTester(new ParseCommand(
            [$client],
            [$normalizer],
            [$sender],
            $saver,
            $outbox,
            $runLock ?? new FakeRunLock(),
            0,
            new NullLogger()
        ));

        return [$tester, $database, $outbox];
    }

    private function item(string $id): Item
    {
        $item = new Item();
        $item->id = $id;
        $item->source = 'test';
        $item->title = 'Test item ' . $id;
        $item->price = 100.0;
        $item->currency = 'USD';
        $item->url = 'https://example.com/' . $id;

        return $item;
    }

    /**
     * @return array<int, array{count: int, pageCount: int, adverts: list<array{id: string}>}>
     */
    private function pages(int $count): array
    {
        $adverts = [];
        for ($i = 1; $i <= $count; $i++) {
            $adverts[] = ['id' => (string) $i];
        }

        return [
            1 => ['count' => $count, 'pageCount' => 1, 'adverts' => $adverts],
        ];
    }

    private function countItems(InMemoryDatabase $database): int
    {
        $count = $database->getPdo()->query('SELECT COUNT(*) FROM items')->fetchColumn();

        return (int) $count;
    }
}
