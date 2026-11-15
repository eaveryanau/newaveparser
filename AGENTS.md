# AGENTS.md

Console-only Symfony 7.4 app (PHP 8.2+, micro-kernel): parses listings from
av.by / hh.ru into SQLite and pushes new or price-changed items to Telegram.
Entrypoint: `php bin/console parse --source=avby|hh`.

## Commands

- Install: `composer install` (requires `pdo_sqlite`)
- All tests: `vendor/bin/phpunit` (`bin/phpunit` is a thin wrapper)
- Single test: `vendor/bin/phpunit tests/Service/ItemSaverTest.php`
- Single method: `vendor/bin/phpunit --filter testSaveNewItem`
- Static analysis: `vendor/bin/phpstan analyse` (src only, level 5, config `phpstan.neon`)
- Smoke check: `php bin/console list`
- No linter/formatter is configured; only `.editorconfig` (4 spaces, LF).
- CI (`.github/workflows/ci.yml`, master only) order: `composer install` → `console list` →
  `debug:config framework` → `phpstan --level=5 src/` → `phpunit`.

## Architecture

- `ParseCommand` receives tagged iterators and selects services by source string:
  tags `app.api_client`, `app.item_normalizer`, `app.telegram_sender` in `config/services.yaml`.
- New source = `ApiClientInterface` impl + `ItemNormalizerInterface` impl + services.yaml tags.
  Normalizers are matched via `supports()`, not a tag attribute.
- A source also needs its own `telegram.sender.<source>` service; otherwise `parse` fails at
  runtime with "Нет отправителя". `another` has client+normalizer but no sender.
- Flow: `Client::fetchPage()` → raw array → `Normalizer::normalize()` → `Item` DTO
  → `ItemSaver::save()` → `TelegramSender::sendItem()`.

## Data & config gotchas

- SQLite schema is created lazily in `src/Service/Database.php`; there are no migrations.
  PK is `(id, source)`. Relative `DATABASE_URL` is resolved from project root.
- `ItemSaver` depends on `Database`, not on `PDO`. Tests subclass `Database` anonymously
  and override `getPdo()`; `Database` has no PDO constructor.
- Price change threshold is `> 0.001`.
- `.env` (tracked) holds empty placeholders; `.env.local` is gitignored and contains a live
  Telegram token — never read, copy, log, or commit it.
- Required env: `TELEGRAM_TOKEN`, `TELEGRAM_CHAT_ID_AV`, `TELEGRAM_CHAT_ID_HH`, `HH_QUERY`,
  `HH_AREAS`, `DATABASE_URL`, `SLEEP_INTERVAL`.
- README is partly stale: `TELEGRAM_CHAT_ID` fallback and `LOG_LEVEL` are not used; monolog
  levels are hardcoded in `config/packages/monolog.yaml`. Trust config/code over README.
- Sleep between Telegram sends happens only when a send actually occurs (not under `--no-tg`).
  The `--no-tg` branch logs the stale name `--no-telegram`.
- `AvByClient`/`HhClient` call undocumented internal endpoints with hardcoded browser headers;
  they can break without code changes.

## Testing

- Pure PHPUnit unit tests, no KernelTestCase and no HTTP fixtures.
- `ItemSaverTest` uses `sqlite::memory:`; `phpunit.xml.dist` only sets `APP_ENV=test` and a
  `/tmp` DB URL, which those tests do not use.
- No integration test hits av.by/hh.ru/Telegram; verify parser changes manually with `--no-tg`.

## Git

- Default/CI branch is `master`. Do not commit `var/`, `vendor/`, `.env.local`.
- Commit messages in this repo are mixed Russian/English; no strict convention.
