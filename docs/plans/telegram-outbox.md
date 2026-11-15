# План: очередь повторной отправки Telegram (`telegram_outbox`)

Статус: реализовано. Дата: 2026-11-15.

## Решения

- Очередь — отдельная таблица, без изменения `items` и без бэкфилла существующих записей.
- В очереди храним **снимок объявления**, а не только `id/source`: `TelegramSender` использует
  поля av.by (`generation`, `engine*`), которых нет в `items`.
- Доставка at-least-once: при потере ответа возможны дубли. Это осознанная плата за надёжность.
- Постоянные 4xx не ретраим, временные (429/5xx/сеть) — ретраим с backoff.

## 1. Схема (`src/Service/Database.php`)

Добавить в ленивую инициализацию:

```sql
CREATE TABLE IF NOT EXISTS telegram_outbox (
    id VARCHAR(64) NOT NULL,
    source VARCHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    last_error TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id, source)
);
CREATE INDEX IF NOT EXISTS idx_telegram_outbox_due
    ON telegram_outbox (source, next_attempt_at);
```

Существующая БД получит таблицу при следующем запуске, миграции не нужны.

## 2. Новые классы

- `src/Telegram/SendResult.php`
  - `success()`, `retryable(string $error, ?int $retryAfter = null)`, `permanent(string $error)`.
- `src/Telegram/OutboxEntry.php`
  - `Item $item`, `int $attempts`, `string $lastError`.
- `src/Telegram/TelegramOutbox.php`
  - `recordFailure(Item, string $error, ?int $retryAfter): void` — upsert, `attempts+1`,
    `next_attempt_at` по backoff.
  - `due(string $source, int $limit = 10): list<OutboxEntry>` — только `next_attempt_at <= now`
    и `attempts < MAX`.
  - `markFailed(OutboxEntry, string $error, ?int $retryAfter): void`.
  - `remove(string $id, string $source): void`.
  - `count(string $source): int` для логов.
  - Payload: явный JSON только нужных полей (`id`, `source`, `title`, `description`, `price`,
    `currency`, `url`, `imageUrl`, `generation`, `generationWithYears`, `engineCapacity`,
    `engineType`, `transmissionType`); `raw` не сохраняем.
  - Backoff: `[60, 300, 900, 3600, 21600, 86400]` секунд; если `retry_after` больше — берём его.
    После 10 попыток — `next_attempt_at` в далёкое будущее + ERROR в лог, строка остаётся для разбора.

## 3. `TelegramSender` (`src/Telegram/TelegramSender.php`)

- `sendItem(): SendResult` вместо `bool`.
- Экранировать `title`, `description`, `url` через
  `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- Обрезать: `caption` ≤ 1024, `text` ≤ 4096. Обрезать компоненты до сборки HTML, чтобы не рвать
  теги; многоточие `…`.
- Запросы выполнять с `http_errors => false`, затем классифицировать:
  - 200 + `ok=true` → success;
  - 429 → retryable, взять `parameters.retry_after` из JSON;
  - 5xx → retryable;
  - сеть/timeout → retryable;
  - остальные 4xx → permanent.
- Тесты: длинное описание, `<b>`/`&` в тексте, 429, 500, 400.

## 4. `ParseCommand` (`src/Command/ParseCommand.php`)

- Найти sender **один раз** до цикла страниц; при `--no-tg` sender не обязателен.
- Перед загрузкой страниц: если не `--no-tg`, обработать `due($source)`:
  - success → `remove()`, счётчик `retried++`;
  - failure retryable → `markFailed()` с backoff;
  - permanent → лог ERROR, без бесконечного ретрая.
- В основном цикле:
  - success → `remove()` (на случай, если запись уже была в очереди);
  - failure retryable → `recordFailure()`;
  - failure permanent → ERROR в лог, не ретраить.
- `--no-tg`: не отправлять, не добавлять в очередь и не разбирать её; очередь ждёт следующего
  нормального запуска.
- `--force`: успешная отправка удаляет запись из очереди; неуспешная — upsert, без дублей.
- Итоговый вывод дополнить: `из очереди отправлено: N, в очереди осталось: M`.
- Sleep между отправками применять и к элементам очереди; лимит очереди за запуск — 10.

## 5. Защита от параллельных cron-запусков

При опросе раз в минуту запуски могут пересекаться. Добавить `src/Service/RunLock.php`:

- `flock` на `var/parse-<source>.lock`;
- если лок занят — лог INFO и `SUCCESS` без работы;
- освобождение через `finally`.

Без этого два процесса могут отправить одну и ту же запись.

## 6. Мелкие фиксы

- `--no-tg` больше не требует `telegram.sender.<source>`.
- `HhClient`: убрать `?area=1002&area=16` из path, оставить только `query` из `HH_AREAS`;
  поправить тест.
- `services.yaml`: `TelegramOutbox` и `RunLock` под autowire, `ParseCommand` получает новый аргумент.

## 7. Тесты

- `tests/Telegram/TelegramOutboxTest.php`:
  - запись нового сбоя, attempts/next_attempt_at;
  - повторный сбой увеличивает attempts и задержку;
  - `due()` фильтрует по source/времени/лимиту;
  - успех удаляет запись;
  - payload round-trip сохраняет avby-поля;
  - после MAX попыток запись не попадает в `due()`.
- `tests/Telegram/TelegramSenderTest.php`: escaping, truncation, 429/500/400.
- `tests/Command/ParseCommandTest.php`:
  - сбой отправки → строка в outbox, item сохранён;
  - следующий запуск доставляет и удаляет;
  - `--no-tg` не создаёт и не разбирает очередь;
  - `--force` не создаёт дубль;
  - permanent-ошибка не ретраится.
- `RecordingSender` обновить под `SendResult`, добавить сценарии успех/сбой.

## 8. Документация

- `README.md`: секция «Очередь Telegram» — таблица, backoff, лимит попыток, at-least-once,
  SQL для просмотра/очистки.
- `AGENTS.md`: обновить flow (`... → ItemSaver → TelegramSender → outbox`), добавить gotcha
  «опрос раз в минуту сам по себе не ретраит», где менять retry-политику.

## 9. Безопасность зависимостей

- `composer update "symfony/*" guzzlehttp/guzzle --with-dependencies` (Symfony ≥7.4.23, Guzzle 8.5.0).
- `composer check` + `composer audit`.
- В CI добавить `composer audit` (сначала не блокирующий).

## 10. Проверка и rollout

- `composer check`.
- Ручной сценарий с MockHandler: сбой → запись в outbox → следующий запуск → доставка.
- Метрики после деплоя: `SELECT COUNT(*), MIN(next_attempt_at) FROM telegram_outbox;`
- Мониторить дубли и рост очереди при недоступном Telegram.

## Риски

- Дубли при потере ответа Telegram (at-least-once).
- Снимок в очереди может отправить устаревшую цену — зато сообщение соответствует событию.
- SQLite + параллельные запуски — закрываем `flock`.
- Постоянные 4xx не ретраятся; если понадобится ручной повтор — добавим отдельную команду позже.

## Оценка

~1–1.5 дня, 5 новых файлов, ~10 изменённых.
