<?php

namespace App\Tests\Support;

use App\DTO\Item;

final class RecordingSender
{
    /** @var list<Item> */
    public array $sent = [];

    public function __construct(private string $source)
    {
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function sendItem(Item $item): bool
    {
        $this->sent[] = $item;

        return true;
    }
}
