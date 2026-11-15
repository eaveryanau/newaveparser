<?php

namespace App\Telegram;

use App\DTO\Item;

final class OutboxEntry
{
    public function __construct(
        public readonly Item $item,
        public readonly int $attempts,
        public readonly ?string $lastError = null,
    ) {
    }
}
