<?php

namespace App\Telegram;

use App\DTO\Item;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

class TelegramSender
{
    private ClientInterface $telegramHttp;
    private string $token;
    private string $chatId;
    private LoggerInterface $logger;
    private string $source;

    public function __construct(
        ClientInterface $telegramHttp,
        string $token,
        string $chatId,
        LoggerInterface $logger,
        string $source
    ) {
        $this->telegramHttp = $telegramHttp;
        $this->token = $token;
        $this->chatId = $chatId;
        $this->logger = $logger;
        $this->source = $source;
    }

    public function sendItem(Item $item): bool
    {
        // 1. Заголовок: brand model generation, год
        $header = '<b>'.$item->title.'</b>';

        // 2. Строка с поколением и годами (если есть)
        $generationLine = $item->generationWithYears ? "\n{$item->generationWithYears}" : '';

        // 3. Характеристики двигателя и КПП
        $specs = [];
        if ($item->engineCapacity) $specs[] = "{$item->engineCapacity} л";
        if ($item->engineType) $specs[] = $item->engineType;
        if ($item->transmissionType) $specs[] = "{$item->transmissionType}";
        $specsLine = !empty($specs) ? "\n" . implode(', ', $specs) : '';

        // 4. Цена – жирная и кликабельная (ссылка на объявление)
        $priceLink = sprintf(
            '<b><a href="%s">%s %s</a></b>',
            $item->url,
            $item->price,
            $item->currency
        );

        // 5. Описание (если есть)
        $description = $item->description ? "\n" . $item->description : '';

        $hashtag = $this->generateHashtag($item->title);

        // Собираем полный текст
        $text = $header . "\n" . $generationLine . $specsLine . "\nЦена: " . $priceLink .
            "\n" . $description .
            "\n\n" . $hashtag;

        $this->logger->debug("Отправка Item ID {$item->id}");

        try {
            if ($item->imageUrl && filter_var($item->imageUrl, FILTER_VALIDATE_URL) !== false) {
                $response = $this->telegramHttp->request('POST', "/bot{$this->token}/sendPhoto", [
                    'form_params' => [
                        'chat_id' => $this->chatId,
                        'photo' => $item->imageUrl,
                        'caption' => $text,
                        'parse_mode' => 'HTML',
                        'disable_notification' => true,
                    ]
                ]);
            } else {
                $response = $this->telegramHttp->request('POST', "/bot{$this->token}/sendMessage", [
                    'form_params' => [
                        'chat_id' => $this->chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                        'disable_notification' => true,
                    ]
                ]);
            }

            if ($response->getStatusCode() === 200) {
                $this->logger->info("Item ID {$item->id} отправлен успешно");
                return true;
            } else {
                $this->logger->error("Ошибка отправки ID {$item->id}, HTTP {$response->getStatusCode()}");
                return false;
            }
        } catch (\Exception $e) {
            $this->logger->error("Исключение при отправке ID {$item->id}: " . $e->getMessage());
            return false;
        }
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Генерирует хештег из названия, убирая год и все не буквенно-цифровые символы.
     * Пример: "Mercedes-Benz C-MAX, 2007" -> "#MercedesBenzCMAX"
     */
    private function generateHashtag(string $title): string
    {
        // Берём часть до запятой (марка и модель)
        $titlePart = explode(',', $title)[0];
        // Удаляем всё, кроме букв и цифр
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $titlePart);
        return '#' . $clean;
    }
}
