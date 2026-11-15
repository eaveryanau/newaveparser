<?php

namespace App\Service;

use App\DTO\Item as DTOItem;
use Psr\Log\LoggerInterface;

class ItemSaver
{
    private \PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(Database $database, LoggerInterface $logger)
    {
        $this->pdo = $database->getPdo();
        $this->logger = $logger;
    }

    /**
     * Сохраняет элемент в БД и возвращает информацию о состоянии.
     *
     * @return array{
     *     saved: bool,
     *     isNew: bool,
     *     isPriceChanged: bool,
     *     oldPrice: float|null
     * }
     */
    public function save(DTOItem $item): array
    {
        try {
            // 1. Проверяем существование записи
            $stmt = $this->pdo->prepare('SELECT price FROM items WHERE id = ? AND source = ?');
            $stmt->execute([$item->id, $item->source]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            $isNew = false;
            $isPriceChanged = false;
            $oldPrice = null;

            if ($existing) {
                $oldPrice = (float) $existing['price'];
                $priceDiff = abs($oldPrice - $item->price);
                if ($priceDiff > $this->priceChangeThreshold($item->source)) {
                    $isPriceChanged = true;
                }

                // Обновляем запись
                $sql = 'UPDATE items SET 
                            title = ?, description = ?, price = ?, currency = ?, 
                            url = ?, image_url = ?
                        WHERE id = ? AND source = ?';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $item->title,
                    $item->description,
                    $item->price,
                    $item->currency,
                    $item->url,
                    $item->imageUrl,
                    $item->id,
                    $item->source
                ]);

                if ($isPriceChanged) {
                    $this->logger->info("Цена изменена для {$item->id}: {$oldPrice} -> {$item->price}");
                } elseif ($priceDiff > 0.001) {
                    $this->logger->debug("Обновлены данные ID {$item->id} (изменение цены ниже порога)");
                } else {
                    $this->logger->debug("Обновлены данные ID {$item->id} (цена не изменилась)");
                }
            } else {
                // Вставляем новую запись
                $isNew = true;
                $sql = 'INSERT INTO items 
                            (id, source, title, description, price, currency, url, image_url, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $item->id,
                    $item->source,
                    $item->title,
                    $item->description,
                    $item->price,
                    $item->currency,
                    $item->url,
                    $item->imageUrl,
                    (new \DateTime())->format('Y-m-d H:i:s')
                ]);
                $this->logger->info("Новый элемент сохранён ID {$item->id}");
            }

            return [
                'saved' => true,
                'isNew' => $isNew,
                'isPriceChanged' => $isPriceChanged,
                'oldPrice' => $oldPrice,
            ];
        } catch (\Exception $e) {
            $this->logger->error("Ошибка сохранения {$item->id}: " . $e->getMessage());
            return [
                'saved' => false,
                'isNew' => false,
                'isPriceChanged' => false,
                'oldPrice' => null,
            ];
        }
    }

    private function priceChangeThreshold(string $source): float
    {
        return $source === 'avby' ? 100.0 : 0.001;
    }
}
