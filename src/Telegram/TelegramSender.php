<?php

namespace App\Telegram;

use App\DTO\Item;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class TelegramSender implements TelegramSenderInterface
{
    private const CAPTION_LIMIT = 1024;
    private const TEXT_LIMIT = 4096;

    public function __construct(
        private ClientInterface $telegramHttp,
        private string $token,
        private string $chatId,
        private LoggerInterface $logger,
        private string $source,
    ) {
    }

    public function sendItem(Item $item): SendResult
    {
        $hasPhoto = $item->imageUrl !== null
            && filter_var($item->imageUrl, FILTER_VALIDATE_URL) !== false;
        $text = $this->buildText($item, $hasPhoto ? self::CAPTION_LIMIT : self::TEXT_LIMIT);

        $this->logger->debug("Отправка Item ID {$item->id}");

        try {
            if ($hasPhoto) {
                $response = $this->telegramHttp->request('POST', "/bot{$this->token}/sendPhoto", [
                    'form_params' => [
                        'chat_id' => $this->chatId,
                        'photo' => $item->imageUrl,
                        'caption' => $text,
                        'parse_mode' => 'HTML',
                        'disable_notification' => true,
                    ],
                    'http_errors' => false,
                ]);
            } else {
                $response = $this->telegramHttp->request('POST', "/bot{$this->token}/sendMessage", [
                    'form_params' => [
                        'chat_id' => $this->chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                        'disable_notification' => true,
                    ],
                    'http_errors' => false,
                ]);
            }
        } catch (\Throwable $e) {
            $error = $this->redact($e->getMessage());
            $this->logger->error("Исключение при отправке ID {$item->id}: {$error}");

            return SendResult::retryable($error);
        }

        return $this->classifyResponse($item, $response);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    private function classifyResponse(Item $item, ResponseInterface $response): SendResult
    {
        $status = $response->getStatusCode();
        $body = $this->jsonBody($response);
        $description = isset($body['description']) ? (string) $body['description'] : '';

        if ($status === 200 && ($body['ok'] ?? false) === true) {
            $this->logger->info("Item ID {$item->id} отправлен успешно");

            return SendResult::success();
        }

        $retryAfter = null;
        if (isset($body['parameters']['retry_after'])) {
            $retryAfter = (int) $body['parameters']['retry_after'];
        }

        $error = $description !== '' ? $description : "HTTP {$status}";
        if ($status === 429) {
            $this->logger->warning("Telegram rate limit для ID {$item->id}", [
                'retry_after' => $retryAfter,
            ]);

            return SendResult::retryable($error, $retryAfter);
        }

        if ($status >= 500) {
            $this->logger->error("Ошибка отправки ID {$item->id}, HTTP {$status}: {$error}");

            return SendResult::retryable($error);
        }

        $this->logger->error("Постоянная ошибка отправки ID {$item->id}, HTTP {$status}: {$error}");

        return SendResult::permanent($error);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    private function buildText(Item $item, int $limit): string
    {
        $generationWithYears = (string) ($item->generationWithYears ?? '');
        $specs = [];
        if ($item->engineCapacity) {
            $specs[] = "{$item->engineCapacity} л";
        }
        if ($item->engineType) {
            $specs[] = $item->engineType;
        }
        if ($item->transmissionType) {
            $specs[] = $item->transmissionType;
        }
        if ($item->mileage !== null && $item->mileage >= 0) {
            $specs[] = number_format($item->mileage, 0, '.', ' ') . ' км';
        }

        $headerGap = "\n";
        $generationLine = $generationWithYears !== '' ? "\n{$generationWithYears}" : '';
        $specsLine = $specs !== [] ? "\n" . implode(', ', $specs) : '';
        $pricePrefix = "\nЦена: ";
        $priceText = "{$item->price} {$item->currency}";
        $hashtag = $this->generateHashtag($item->title);

        $fixedVisible = $this->length($headerGap)
            + $this->length($generationLine)
            + $this->length($specsLine)
            + $this->length($pricePrefix)
            + $this->length($priceText)
            + $this->length("\n\n{$hashtag}");
        $title = $this->truncate($item->title, max(1, $limit - $fixedVisible));

        $hashtag = $this->generateHashtag($title);
        $tail = "\n\n{$hashtag}";
        $fixedVisible = $this->length($headerGap)
            + $this->length($generationLine)
            + $this->length($specsLine)
            + $this->length($pricePrefix)
            + $this->length($priceText)
            + $this->length($tail);
        $available = $limit - $this->length($title) - $fixedVisible - 2;
        $description = $this->truncate(trim((string) ($item->description ?? '')), max(0, $available));

        $text = '<b>' . $this->escape($title) . '</b>' . $headerGap;
        if ($generationWithYears !== '') {
            $text .= "\n" . $this->escape($generationWithYears);
        }
        if ($specs !== []) {
            $text .= "\n" . $this->escape(implode(', ', $specs));
        }
        $text .= "\nЦена: <b><a href=\"" . $this->escape($item->url) . '">'
            . $this->escape($priceText) . '</a></b>';
        if ($description !== '') {
            $text .= "\n\n" . $this->escape($description);
        }
        $text .= $tail;

        return $text;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function truncate(string $value, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }
        if ($this->length($value) <= $limit) {
            return $value;
        }
        if ($limit === 1) {
            return '…';
        }

        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return mb_substr($value, 0, $limit - 1) . '…';
        }

        $budget = $limit - 1;
        $units = 0;
        $result = '';
        foreach ($chars as $char) {
            $charUnits = $this->length($char);
            if ($units + $charUnits > $budget) {
                break;
            }
            $result .= $char;
            $units += $charUnits;
        }

        return $result === '' ? '…' : $result . '…';
    }

    private function length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function redact(string $message): string
    {
        if ($this->token === '') {
            return $message;
        }

        return str_replace($this->token, '[REDACTED]', $message);
    }

    private function generateHashtag(string $title): string
    {
        $titlePart = explode(',', $title)[0];
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $titlePart);

        return '#' . $clean;
    }
}
