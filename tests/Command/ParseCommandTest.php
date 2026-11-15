<?php

namespace App\Tests\Command;

use App\Client\ApiClientInterface;
use App\Command\ParseCommand;
use App\DTO\Item;
use App\Normalizer\ItemNormalizerInterface;
use App\Service\ItemSaver;
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
        $tester = $this->createTester([], new RecordingSender('test'));

        $status = $tester->execute(['--source' => 'missing']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Неизвестный источник', $tester->getDisplay());
    }

    public function testMissingSenderFailsAtRuntime(): void
    {
        $tester = $this->createTester([], new RecordingSender('another-source'));

        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Нет отправителя', $tester->getDisplay());
    }

    public function testNoTgSavesWithoutSending(): void
    {
        $database = new InMemoryDatabase();
        $saver = new ItemSaver($database, new NullLogger());
        $sender = new RecordingSender('test');

        $tester = $this->createTester($this->pages(1), $sender, $saver);
        $status = $tester->execute(['--source' => 'test', '--no-tg' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(0, $sender->sent);
        self::assertSame(1, $this->countItems($database));
    }

    public function testLimitStopsProcessing(): void
    {
        $database = new InMemoryDatabase();
        $saver = new ItemSaver($database, new NullLogger());
        $pages = $this->pages(3);

        $tester = $this->createTester($pages, new RecordingSender('test'), $saver);
        $status = $tester->execute(['--source' => 'test', '--limit' => 2, '--no-tg' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Достигнут лимит', $tester->getDisplay());
        self::assertSame(2, $this->countItems($database));
    }

    public function testNewItemIsSentToTelegram(): void
    {
        $sender = new RecordingSender('test');

        $tester = $this->createTester($this->pages(1), $sender);
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

        $existing = $this->item('1');
        $saver->save($existing);

        $tester = $this->createTester($this->pages(1), $sender, $saver);
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

        $tester = $this->createTester($this->pages(1), $sender, $saver);
        $status = $tester->execute(['--source' => 'test']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(0, $sender->sent);
    }

    private function createTester(
        array $pages,
        RecordingSender $sender,
        ?ItemSaver $saver = null,
        string $source = 'test'
    ): CommandTester {
        $saver ??= new ItemSaver(new InMemoryDatabase(), new NullLogger());

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

        return new CommandTester(new ParseCommand(
            [$client],
            [$normalizer],
            [$sender],
            $saver,
            0,
            new NullLogger()
        ));
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
