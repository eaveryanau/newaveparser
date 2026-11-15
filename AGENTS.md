# AGENTS.md

Console-only Symfony 7.4 app (PHP 8.2+, micro-kernel): parses listings from
av.by / hh.ru into SQLite and pushes new or price-changed items to Telegram.
Entrypoint: `php bin/console parse --source=avby|hh`.

## Commands

- Install: `composer install` (requires `pdo_sqlite`)
- Full check (use this): `composer check` = validate + console list + debug config + PHPStan + PHPUnit
- All tests: `vendor/bin/phpunit` (`bin/phpunit` is a thin wrapper, `composer test`)
- Single test: `vendor/bin/phpunit tests/Service/ItemSaverTest.php`
- Single method: `vendor/bin/phpunit --filter testSaveNewItem`
- Static analysis: `vendor/bin/phpstan analyse` (src only, level 5, config `phpstan.neon`)
- Smoke check: `php bin/console list`
- No linter/formatter is configured; only `.editorconfig` (4 spaces, LF).
- CI (`.github/workflows/ci.yml`, master only) runs `composer install`, `composer check`, then a non-blocking `composer audit`.

## Architecture

- `ParseCommand` receives tagged iterators and selects services by source string:
  tags `app.api_client`, `app.item_normalizer`, `app.telegram_sender` in `config/services.yaml`.
- New source = `ApiClientInterface` impl + `ItemNormalizerInterface` impl + services.yaml tags.
  Normalizers are matched via `supports()`, not a tag attribute.
- A source also needs its own `telegram.sender.<source>` service unless run with `--no-tg`;
  otherwise `parse` fails at runtime with "Нет отправителя". `another` has client+normalizer
  but no sender.
- Flow: `Client::fetchPage()` → raw array → `Normalizer::normalize()` → `Item` DTO
  → `ItemSaver::save()` → `TelegramSender::sendItem()` → `SendResult`.
  Retryable failures are snapshotted into `telegram_outbox`; the next run drains due rows
  before fetching pages. `--no-tg` neither sends nor drains/enqueues.
- Running the same source concurrently is prevented by `RunLock` (`flock` in `var/`).

## Where to change what

- Source selection, page loop, `--limit`/`--force`/`--no-tg` → `src/Command/ParseCommand.php`
- Request path/payload/headers → `src/Client/*`
- Raw fields → `Item` fields → `src/Normalizer/*`
- New-item/price-change detection → `src/Service/ItemSaver.php`
- SQLite schema/path resolution → `src/Service/Database.php`
- Telegram message/photo formatting, escaping, size limits → `src/Telegram/TelegramSender.php`
- Retry policy, backoff, outbox payload/schema → `src/Telegram/TelegramOutbox.php`
- Overlap protection → `src/Service/RunLock.php`
- Service wiring and tags → `config/services.yaml`

## Data & config gotchas

- SQLite schema is created lazily in `src/Service/Database.php`; there are no migrations.
  `items` PK is `(id, source)`; `telegram_outbox` PK is `(id, source)` too.
  Relative `DATABASE_URL` is resolved from project root. `busy_timeout` is 5 s.
- `ItemSaver` depends on `Database`, not on `PDO`. Tests use `tests/Support/InMemoryDatabase`
  (a `Database` subclass with `sqlite::memory:`); `Database` has no PDO constructor.
- Price change threshold is `> 100.0` for `avby` and `> 0.001` for other sources;
  the stored price is updated regardless of the threshold.
- av.by mileage (`mileage_km`) lives on `Item::$mileage` and in the outbox snapshot;
  it is not stored in the `items` table.
- `.env` (tracked) holds empty placeholders; `.env.local` is gitignored and contains a live
  Telegram token — never read, copy, log, or commit it.
- Required env: `TELEGRAM_TOKEN`, `TELEGRAM_CHAT_ID_AV`, `TELEGRAM_CHAT_ID_HH`, `HH_QUERY`,
  `HH_AREAS`, `DATABASE_URL`, `SLEEP_INTERVAL`.
- README is prose; trust config/code when they disagree. Monolog levels are hardcoded in
  `config/packages/monolog.yaml` (no `LOG_LEVEL` env).
- Sleep happens after each attempted send in the page loop (success or failure) and after
  successful outbox sends; `--no-tg` does no sending, draining, or enqueueing.
- `telegram_outbox` stores an item snapshot (av.by fields are not in `items`), not a reference.
  Delivery is at-least-once: duplicates are possible if Telegram's response is lost.
  Permanent 4xx (e.g. chat not found) are logged and not retried; 429/5xx/network are retried.
- Backoff is `[60, 300, 900, 3600, 21600, 86400]` seconds, max 10 attempts; `retry_after`
  from Telegram only increases the delay. Due rows are drained before page fetching, 10/run.
- `AvByClient`/`HhClient` call undocumented internal endpoints with hardcoded browser headers;
  they can break without code changes.

## Testing

- Pure PHPUnit unit tests, no KernelTestCase and no live network calls.
- Clients and `TelegramSender` tests use `GuzzleHttp\Handler\MockHandler`; raw payload
  samples live in `tests/fixtures/avby/` and `tests/fixtures/hh/`.
- `ItemSaverTest`, `TelegramOutboxTest` and `ParseCommandTest` use `sqlite::memory:`;
  `phpunit.xml.dist` only sets `APP_ENV=test` and a `/tmp` DB URL, which those tests do not use.
- `RecordingSender` returns `SendResult`; queue a non-success result via `queueResult()`.
  `FakeRunLock` controls lock acquisition; `RunLockTest` uses a real temp directory.
- No integration test hits av.by/hh.ru/Telegram; verify parser changes manually with `--no-tg`.
- `parse` writes to SQLite and, without `--no-tg`, sends real Telegram messages. For manual
  checks use `DATABASE_URL=sqlite:/tmp/agent-check.db php bin/console parse ... --no-tg`.
  Remember `--no-tg` skips the outbox, so it does not test retry delivery.

## Git

- Default/CI branch is `master`. Do not commit `var/`, `vendor/`, `.env.local`.
- Commit messages in this repo are mixed Russian/English; no strict convention.
