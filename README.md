# 🚀 NewaveParser — Парсер объявлений с уведомлениями в Telegram

Консольное приложение на **Symfony 7.4 LTS** для парсинга объявлений с [av.by](https://av.by) (автомобили) и [hh.ru](https://hh.ru) (вакансии). Сохраняет данные в **SQLite**, уведомляет о новых и изменивших цену объявлениях в **Telegram**.

Архитектура спроектирована с нулевым coupling между командами и источниками — новый источник добавляется созданием трёх классов и регистрацией в DI-контейнере, без изменения существующего кода.

---

## 📌 Возможности

| Возможность | Статус |
|---|---|
| Парсинг av.by (автомобили) | ✅ Работает |
| Парсинг hh.ru (вакансии) | ✅ Работает |
| Заглушка для нового источника (`another`) | ✅ Готовый шаблон |
| Сохранение в SQLite | ✅ PDO, автосоздание таблицы |
| Выявление новых объявлений | ✅ По составному ключу `(id, source)` |
| Выявление изменения цены | ✅ С погрешностью 0.001 |
| Уведомления в Telegram с фото | ✅ sendPhoto / sendMessage HTML |
| Разные чаты для разных источников | ✅ Каждый источник — свой chat_id |
| CLI-параметры: страницы, лимит, источник | ✅ |
| Принудительная отправка (`--force`) | ✅ |
| Отключение Telegram (`--no-tg`) | ✅ |
| Логирование в консоль и файл | ✅ Monolog |
| Обработка 429 Too Many Requests | ✅ sleep между отправками, README |

---

## 🏗 Архитектура

```
bin/console parse --source=avby --pages=5
       │
       ▼
┌─────────────────────────────────────────────────────────────┐
│                       ParseCommand                          │
│  (выбирает по --source нужные Client + Normalizer + Sender)  │
└─────────────────────────────────────────────────────────────┘
       │
       ▼
┌──────────────────┐     ┌────────────────────────┐
│  ApiClientInterface │────▶│  ItemNormalizerInterface │
│  (AvByClient,     │     │  (AvByItemNormalizer, │
│   HhClient,       │     │   HhItemNormalizer,   │
│   AnotherClient)  │     │   AnotherNormalizer)  │
└──────────────────┘     └───────────┬────────────┘
       │                             │
       ▼                             ▼
   Сырые данные (JSON) ────────▶  Item DTO
                                     │
                                     ▼
                           ┌──────────────────┐
                           │    ItemSaver      │
                           │  ┌──────────────┐ │
                           │  │   Database    │ │
                           │  │  (SQLite via  │ │
                           │  │   PDO)        │ │
                           │  └──────────────┘ │
                           └────────┬─────────┘
                                    │
                                    ▼
                           ┌──────────────────┐
                           │  TelegramSender   │
                           │  (sendPhoto /     │
                           │   sendMessage)    │
                           └──────────────────┘
```

### Ключевые принципы

- **Интерфейсный контракт**: каждый источник реализует `ApiClientInterface` и `ItemNormalizerInterface`
- **Автоматическое обнаружение**: команда получает tagged iterators из DI-контейнера и выбирает нужный сервис по строке источника
- **Никаких switch/match**: новый источник = новый класс + тег в `services.yaml`, код команды не меняется
- **Независимые конфиги Telegram**: канал, токен, source — всё настраивается отдельно для каждого источника

---

## 📦 Установка

### Требования

- PHP **8.2+**
- Composer
- SQLite (ext-pdo_sqlite)
- Telegram Bot Token

### Шаги

```bash
# 1. Клонировать
git clone <url>
cd newaveparser

# 2. Установить зависимости
composer install

# 3. Создать .env.local
cp .env .env.local

# 4. Убедиться, что var доступна для записи
mkdir -p var
chmod 755 var
```

---

## 🔐 Конфигурация (.env.local)

```env
# ─── Telegram ────────────────────────────────────────────────
TELEGRAM_TOKEN=1234567890:ABCdefGHIjklmNOPqrstUVwxyz

# Общий fallback (используется если не указаны отдельные)
TELEGRAM_CHAT_ID=@channel_or_id

# Отдельные каналы под каждый источник
TELEGRAM_CHAT_ID_AV=@cars_channel
TELEGRAM_CHAT_ID_HH=@jobs_channel

# ─── HH.ru ───────────────────────────────────────────────────
HH_QUERY=Frontend react
HH_AREAS=1002,16    # ID регионов (Беларусь, Минск)

# ─── База данных ─────────────────────────────────────────────
DATABASE_URL=sqlite:var/data.db

# ─── Настройки выполнения ────────────────────────────────────
SLEEP_INTERVAL=2    # секунд между отправками в Telegram
LOG_LEVEL=info      # debug / info / error
```

---

## 🚀 Использование

```bash
php bin/console parse [options]
```

### Опции

| Опция | Сокращение | По умолчанию | Описание |
|---|---|---|---|
| `--pages` | `-p` | `0` | Количество страниц (`0` — все) |
| `--limit` | `-l` | `0` | Лимит объявлений (`0` — без лимита) |
| `--source` | `-s` | `avby` | Источник: `avby`, `hh`, `another` |
| `--force` | `-f` | — | Отправить все объявления, игнорируя проверку на новизну |
| `--no-tg` | — | — | Только сохранение в БД, без отправки в Telegram |

### Примеры

```bash
# Парсинг первых 5 страниц av.by с отправкой в Telegram
php bin/console parse --source=avby --pages=5

# Все страницы hh.ru с лимитом в 50 объявлений
php bin/console parse --source=hh --limit=50

# Только сохранить в базу, без Telegram
php bin/console parse --source=avby --no-tg

# Принудительно отправить все объявления заново
php bin/console parse --source=hh --force

# Полный цикл: 10 страниц, 100 объявлений, av.by
php bin/console parse -s avby -p 10 -l 100
```

### Запуск по расписанию (cron)

```cron
# Каждый час — парсинг автомобилей
0 * * * * cd /path/to/project && php bin/console parse --source=avby --no-tg >> var/log/cron.log 2>&1

# Каждые 30 минут — вакансии
*/30 * * * * cd /path/to/project && php bin/console parse --source=hh --pages=3 >> var/log/cron.log 2>&1
```

---

## 📊 База данных

Файл: `var/data.db` (SQLite).

### Таблица `items`

| Колонка | Тип | Описание |
|---|---|---|
| `id` | VARCHAR(64) | ID объявления (из источника) |
| `source` | VARCHAR(64) | Источник (`avby`, `hh`, ...) |
| `title` | VARCHAR(255) | Название / заголовок |
| `description` | TEXT | Описание |
| `price` | FLOAT | Цена |
| `currency` | VARCHAR(8) | Валюта |
| `url` | VARCHAR(512) | Ссылка на оригинал |
| `image_url` | VARCHAR(512) | URL изображения |
| `created_at` | DATETIME | Дата первого сохранения |

**Первичный ключ**: `(id, source)` — гарантирует уникальность записи по источнику.

---

## 🔌 Добавление нового источника

Система спроектирована так, что добавление нового источника не требует изменения существующего кода. Нужно создать три класса и зарегистрировать их в контейнере.

### Структура

```
src/
├── Client/
│   └── RealtByClient.php            # запросы к API
├── Normalizer/
│   └── RealtByItemNormalizer.php    # преобразование сырых данных в Item DTO
└── (TelegramSender                  # можно переиспользовать существующий)
```

### Шаг 1. Клиент API

```php
namespace App\Client;

use GuzzleHttp\ClientInterface;

class RealtByClient implements ApiClientInterface
{
    public function __construct(private ClientInterface $http) {}

    public function fetchPage(int $page): array
    {
        // Здесь — HTTP-запрос к API realt.by
        // Ответ нужно привести к формату:
        return [
            'count' => 42,
            'pageCount' => 5,
            'adverts' => [ /* массив сырых элементов */ ],
        ];
    }

    public function getSource(): string
    {
        return 'realtby';
    }
}
```

### Шаг 2. Нормализатор

```php
namespace App\Normalizer;

use App\DTO\Item;

class RealtByItemNormalizer implements ItemNormalizerInterface
{
    public function normalize(array $raw): Item
    {
        $item = new Item();
        $item->id = (string) $raw['id'];
        $item->source = 'realtby';
        $item->title = $raw['title'] ?? '';
        // ... остальные поля
        return $item;
    }

    public function supports(string $source): bool
    {
        return $source === 'realtby';
    }
}
```

### Шаг 3. Регистрация в `config/services.yaml`

```yaml
# HTTP-клиент (если ещё нет подходящего)
GuzzleHttp\ClientInterface $realtbyHttp:
  class: GuzzleHttp\Client
  arguments:
    $config:
      base_uri: 'https://api.realt.by'
      timeout: 30

# Клиент API
App\Client\RealtByClient:
  arguments:
    $http: '@GuzzleHttp\ClientInterface $realtbyHttp'
  tags:
    - { name: 'app.api_client', source: 'realtby' }

# Нормализатор
App\Normalizer\RealtByItemNormalizer:
  tags:
    - { name: 'app.item_normalizer' }

# Telegram-отправитель (опционально)
telegram.sender.realtby:
  class: App\Telegram\TelegramSender
  arguments:
    $telegramHttp: '@GuzzleHttp\ClientInterface $telegramHttp'
    $token: '%env(TELEGRAM_TOKEN)%'
    $chatId: '%env(TELEGRAM_CHAT_ID_REALTBY)%'
    $logger: '@logger'
    $source: 'realtby'
  tags:
    - { name: 'app.telegram_sender', source: 'realtby' }
```

### Шаг 4. Запуск

```bash
php bin/console parse --source=realtby --pages=3
```

Всё. Команда `ParseCommand` автоматически подберёт клиент, нормализатор и отправитель по значению `--source`.

---

## 🧩 Структура проекта

```
└── src/
    ├── Client/
    │   ├── ApiClientInterface.php      # Контракт клиента API
    │   ├── AvByClient.php              # av.by (POST, автомобили)
    │   ├── HhClient.php                # hh.ru (GET, вакансии)
    │   └── AnotherClient.php           # Заглушка для нового источника
    ├── Command/
    │   └── ParseCommand.php            # Главная команда (парсинг → сохранение → уведомление)
    ├── DTO/
    │   └── Item.php                    # Универсальный DTO объявления
    ├── Normalizer/
    │   ├── ItemNormalizerInterface.php # Контракт нормализатора
    │   ├── AvByItemNormalizer.php      # Av.by: brand, model, engine, transmission
    │   ├── HhItemNormalizer.php        # HH.ru: salary, experience, work format
    │   └── AnotherItemNormalizer.php   # Заглушка
    ├── Service/
    │   ├── Database.php                # PDO-обёртка над SQLite
    │   └── ItemSaver.php               # Сохранение с детектированием new/price-change
    ├── Telegram/
    │   └── TelegramSender.php          # HTML + фото в Telegram
    └── Kernel.php
```

---

## 🛠 Развитие проекта

Возможные направления:

- **Новые источники**: kufar.by, onliner.by, rabota.by, realt.by
- **Тесты**: PHPUnit (неймспейс `App\Tests\` уже зарегистрирован в composer.json)
- **Веб-интерфейс**: Symfony/Twig для просмотра сохранённых объявлений
- **История цен**: хранить не только последнюю цену, а всю историю изменений
- **Экспорт**: CSV / JSON / XLSX из собранных данных
- **Фильтры**: CLI-опции для цены, категории, региона
- **Retry с exponential backoff**: вместо `sleep()` при 429
- **Docker**: для воспроизводимого окружения
- **Static analysis**: PHPStan / Psalm

---

## 📄 Лицензия

Проприетарная. Все права защищены.
