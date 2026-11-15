<?php

namespace App\Tests\Support;

use App\DTO\Item;
use App\Telegram\SendResult;
use App\Telegram\TelegramSenderInterface;

final class RecordingSender implements TelegramSenderInterface
{
    /** @var list<Item> */
    public array $sent = [];

    /** @var list<SendResult> */
    private array $results = [];

    public function __construct(private string $source)
    {
    }

    public function queueResult(SendResult $result): void
    {
        $this->results[] = $result;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function sendItem(Item $item): SendResult
    {
        $this->sent[] = $item;

        return array_shift($this->results) ?? SendResult::success();
    }
}
