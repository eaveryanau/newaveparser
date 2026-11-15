<?php

namespace App\Telegram;

use App\DTO\Item;

interface TelegramSenderInterface
{
    public function sendItem(Item $item): SendResult;

    public function getSource(): string;
}
