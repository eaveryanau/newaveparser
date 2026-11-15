<?php

namespace App\Telegram;

final class SendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly bool $retryable,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
    ) {
    }

    public static function success(): self
    {
        return new self(true, false);
    }

    public static function retryable(string $error, ?int $retryAfter = null): self
    {
        return new self(false, true, $error, $retryAfter);
    }

    public static function permanent(string $error): self
    {
        return new self(false, false, $error);
    }
}
