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
- CI (`.github/workflows/ci.yml`, master only) runs `composer install` then `composer check`.

## Architecture

- `ParseCommand` receives tagged iterators and selects services by source string:
  tags `app.api_client`, `app.item_normalizer`, `app.telegram_sender` in `config/services.yaml`.
- New source = `ApiClientInterface` impl + `ItemNormalizerInterface` impl + services.yaml tags.
  Normalizers are matched via `supports()`, not a tag attribute.
- A source also needs its own `telegram.sender.<source>` service; otherwise `parse` fails at
  runtime with "Нет отправителя". `another` has client+normalizer but no sender.
- Flow: `Client::fetchPage()` → raw array → `Normalizer::normalize()` → `Item` DTO
  → `ItemSaver::save()` → `TelegramSender::sendItem()`.

## Where to change what

- Source selection, page loop, `--limit`/`--force`/`--no-tg` → `src/Command/ParseCommand.php`
- Request path/payload/headers → `src/Client/*`
- Raw fields → `Item` fields → `src/Normalizer/*`
- New-item/price-change detection → `src/Service/ItemSaver.php`
- SQLite schema/path resolution → `src/Service/Database.php`
- Telegram message/photo formatting → `src/Telegram/TelegramSender.php`
- Service wiring and tags → `config/services.yaml`

## Data & config gotchas

- SQLite schema is created lazily in `src/Service/Database.php`; there are no migrations.
  PK is `(id, source)`. Relative `DATABASE_URL` is resolved from project root.
- `ItemSaver` depends on `Database`, not on `PDO`. Tests use `tests/Support/InMemoryDatabase`
  (a `Database` subclass with `sqlite::memory:`); `Database` has no PDO constructor.
- Price change threshold is `> 0.001`.
- `.env` (tracked) holds empty placeholders; `.env.local` is gitignored and contains a live
  Telegram token — never read, copy, log, or commit it.
- Required env: `TELEGRAM_TOKEN`, `TELEGRAM_CHAT_ID_AV`, `TELEGRAM_CHAT_ID_HH`, `HH_QUERY`,
  `HH_AREAS`, `DATABASE_URL`, `SLEEP_INTERVAL`.
- README is prose; trust config/code when they disagree. Monolog levels are hardcoded in
  `config/packages/monolog.yaml` (no `LOG_LEVEL` env).
- Sleep between Telegram sends happens only when a send actually occurs (not under `--no-tg`).
  The `--no-tg` branch logs the stale name `--no-telegram`.
- `AvByClient`/`HhClient` call undocumented internal endpoints with hardcoded browser headers;
  they can break without code changes.

## Testing

- Pure PHPUnit unit tests, no KernelTestCase and no live network calls.
- Clients and `TelegramSender` tests use `GuzzleHttp\Handler\MockHandler`; raw payload
  samples live in `tests/fixtures/avby/` and `tests/fixtures/hh/`.
- `ItemSaverTest` uses `sqlite::memory:`; `phpunit.xml.dist` only sets `APP_ENV=test` and a
  `/tmp` DB URL, which those tests do not use.
- No integration test hits av.by/hh.ru/Telegram; verify parser changes manually with `--no-tg`.
- `parse` writes to SQLite and, without `--no-tg`, sends real Telegram messages. For manual
  checks use `DATABASE_URL=sqlite:/tmp/agent-check.db php bin/console parse ... --no-tg`.

## Git

- Default/CI branch is `master`. Do not commit `var/`, `vendor/`, `.env.local`.
- Commit messages in this repo are mixed Russian/English; no strict convention.
