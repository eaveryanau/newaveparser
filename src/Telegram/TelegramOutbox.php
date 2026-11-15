<?php

namespace App\Telegram;

use App\DTO\Item;
use App\Service\Database;
use Psr\Log\LoggerInterface;

class TelegramOutbox
{
    private const RETRY_DELAYS = [60, 300, 900, 3600, 21600, 86400];
    private const MAX_ATTEMPTS = 10;
    private const EXHAUSTED_AT = '9999-12-31 23:59:59';

    private \PDO $pdo;

    public function __construct(
        Database $database,
        private LoggerInterface $logger,
    ) {
        $this->pdo = $database->getPdo();
    }

    public function recordFailure(Item $item, string $error, ?int $retryAfter = null): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempts FROM telegram_outbox WHERE id = ? AND source = ?'
        );
        $stmt->execute([$item->id, $item->source]);
        $current = $stmt->fetchColumn();
        $attempts = ($current === false ? 0 : (int) $current) + 1;

        $now = $this->now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO telegram_outbox
                (id, source, payload, attempts, next_attempt_at, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(id, source) DO UPDATE SET
                payload = excluded.payload,
                attempts = excluded.attempts,
                next_attempt_at = excluded.next_attempt_at,
                last_error = excluded.last_error,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            $item->id,
            $item->source,
            $this->encodeItem($item),
            $attempts,
            $this->nextAttemptAt($attempts, $retryAfter),
            $error,
            $now,
            $now,
        ]);

        $this->logger->warning('Элемент добавлен в очередь Telegram', [
            'id' => $item->id,
            'source' => $item->source,
            'attempts' => $attempts,
            'error' => $error,
        ]);
    }

    /**
     * @return list<OutboxEntry>
     */
    public function due(string $source, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, source, payload, attempts, last_error
             FROM telegram_outbox
             WHERE source = ? AND next_attempt_at <= ? AND attempts < ?
             ORDER BY created_at ASC, id ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, $source);
        $stmt->bindValue(2, $this->now());
        $stmt->bindValue(3, self::MAX_ATTEMPTS, \PDO::PARAM_INT);
        $stmt->bindValue(4, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            try {
                $entries[] = new OutboxEntry(
                    $this->decodeItem((string) $row['payload']),
                    (int) $row['attempts'],
                    $row['last_error'] !== null ? (string) $row['last_error'] : null,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Некорректный payload в очереди Telegram', [
                    'id' => $row['id'],
                    'source' => $row['source'],
                    'error' => $e->getMessage(),
                ]);
                $this->markCorrupt((string) $row['id'], (string) $row['source']);
            }
        }

        return $entries;
    }

    public function markFailed(OutboxEntry $entry, string $error, ?int $retryAfter = null): void
    {
        $attempts = $entry->attempts + 1;
        $nextAttemptAt = $this->nextAttemptAt($attempts, $retryAfter);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->logger->error('Исчерпаны попытки отправки элемента из очереди Telegram', [
                'id' => $entry->item->id,
                'source' => $entry->item->source,
                'attempts' => $attempts,
                'error' => $error,
            ]);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE telegram_outbox
             SET attempts = ?, next_attempt_at = ?, last_error = ?, updated_at = ?
             WHERE id = ? AND source = ?'
        );
        $stmt->execute([
            $attempts,
            $nextAttemptAt,
            $error,
            $this->now(),
            $entry->item->id,
            $entry->item->source,
        ]);
    }

    public function remove(string $id, string $source): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM telegram_outbox WHERE id = ? AND source = ?');
        $stmt->execute([$id, $source]);
    }

    public function count(string $source): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM telegram_outbox WHERE source = ?');
        $stmt->execute([$source]);

        return (int) $stmt->fetchColumn();
    }

    public static function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    private function nextAttemptAt(int $attempts, ?int $retryAfter = null): string
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return self::EXHAUSTED_AT;
        }

        $index = min(max($attempts, 1), count(self::RETRY_DELAYS)) - 1;
        $delay = self::RETRY_DELAYS[$index];
        if ($retryAfter !== null && $retryAfter > $delay) {
            $delay = $retryAfter;
        }

        return (new \DateTime())
            ->modify(sprintf('+%d seconds', $delay))
            ->format('Y-m-d H:i:s');
    }

    private function markCorrupt(string $id, string $source): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE telegram_outbox
             SET next_attempt_at = ?, last_error = ?, updated_at = ?
             WHERE id = ? AND source = ?'
        );
        $stmt->execute([
            self::EXHAUSTED_AT,
            'Некорректный payload',
            $this->now(),
            $id,
            $source,
        ]);
    }

    private function encodeItem(Item $item): string
    {
        return json_encode([
            'id' => $item->id,
            'source' => $item->source,
            'title' => $item->title,
            'description' => $item->description,
            'price' => $item->price,
            'currency' => $item->currency,
            'url' => $item->url,
            'imageUrl' => $item->imageUrl,
            'generation' => $item->generation,
            'generationWithYears' => $item->generationWithYears,
            'engineCapacity' => $item->engineCapacity,
            'engineType' => $item->engineType,
            'transmissionType' => $item->transmissionType,
            'mileage' => $item->mileage,
            'brand' => $item->brand,
            'model' => $item->model,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function decodeItem(string $payload): Item
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Payload не является объектом');
        }

        $item = new Item();
        $item->id = (string) ($data['id'] ?? '');
        $item->source = (string) ($data['source'] ?? '');
        $item->title = (string) ($data['title'] ?? '');
        $item->description = $this->nullableString($data['description'] ?? null);
        $item->price = (float) ($data['price'] ?? 0);
        $item->currency = (string) ($data['currency'] ?? 'USD');
        $item->url = (string) ($data['url'] ?? '');
        $item->imageUrl = $this->nullableString($data['imageUrl'] ?? null);
        $item->generation = $this->nullableString($data['generation'] ?? null);
        $item->generationWithYears = $this->nullableString($data['generationWithYears'] ?? null);
        $item->engineCapacity = $this->nullableString($data['engineCapacity'] ?? null);
        $item->engineType = $this->nullableString($data['engineType'] ?? null);
        $item->transmissionType = $this->nullableString($data['transmissionType'] ?? null);
        $item->mileage = $this->nullableInt($data['mileage'] ?? null);
        $item->brand = $this->nullableString($data['brand'] ?? null);
        $item->model = $this->nullableString($data['model'] ?? null);

        if ($item->id === '' || $item->source === '') {
            throw new \RuntimeException('В payload отсутствуют id или source');
        }

        return $item;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function now(): string
    {
        return (new \DateTime())->format('Y-m-d H:i:s');
    }
}
