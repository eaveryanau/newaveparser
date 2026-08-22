<?php

namespace App\Command;

use App\Service\ItemSaver;
use App\Telegram\TelegramSender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

/**
 * Команда для парсинга объявлений из указанного источника.
 */
class ParseCommand extends Command
{
    private iterable $apiClients;
    private iterable $itemNormalizers;
    private iterable $telegramSenders;
    private ItemSaver $saver;
    private int $sleepInterval;
    private LoggerInterface $logger;

    public function __construct(
        iterable $apiClients,
        iterable $itemNormalizers,
        iterable $telegramSenders,
        ItemSaver $saver,
        int $sleepInterval,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->apiClients = $apiClients;
        $this->itemNormalizers = $itemNormalizers;
        $this->telegramSenders = $telegramSenders;
        $this->saver = $saver;
        $this->sleepInterval = $sleepInterval;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this->setName('parse')
            ->setDescription('Парсит объявления с указанного источника, сохраняет в БД и уведомляет в Telegram о новых или изменивших цену')
            ->addOption('pages', 'p', InputOption::VALUE_OPTIONAL, 'Количество страниц для парсинга (0 – все страницы)', 0)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Лимит обработанных объявлений (0 – без лимита)', 0)
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Источник данных (avby, hh, another)', 'avby')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Принудительно отправлять все объявления (игнорируя проверку на новизну)')
            ->addOption('no-tg', null, InputOption::VALUE_NONE, 'Отключить отправку в Telegram (только сохранение в БД)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pages = (int)$input->getOption('pages');
        $limit = (int)$input->getOption('limit');
        $source = $input->getOption('source');

        // 1. Выбираем клиент
        $client = null;
        foreach ($this->apiClients as $c) {
            if ($c->getSource() === $source) {
                $client = $c;
                break;
            }
        }
        if (!$client) {
            $io->error("Неизвестный источник: $source. Доступные: avby, hh, another");
            return Command::FAILURE;
        }

        // 2. Выбираем нормализатор
        $normalizer = null;
        foreach ($this->itemNormalizers as $n) {
            if ($n->supports($source)) {
                $normalizer = $n;
                break;
            }
        }
        if (!$normalizer) {
            $io->error("Нет нормализатора для источника: $source");
            return Command::FAILURE;
        }

        $io->title("Парсинг источника: $source");
        $this->logger->info("Запуск парсинга", [
            'source' => $source,
            'pages' => $pages,
            'limit' => $limit,
        ]);

        $processed = 0;
        $sent = 0;
        $newCount = 0;
        $changedCount = 0;
        $skippedCount = 0;

        $page = 1;
        // Если pages == 0, то мы не знаем общее количество страниц – получим из первого запроса
        $totalPages = ($pages > 0) ? $pages : null;

        do {
            $io->section("Страница $page");
            try {
                $data = $client->fetchPage($page);
                if ($totalPages === null) {
                    $totalPages = $data['pageCount']; // устанавливаем общее количество страниц
                }
                $io->text("Найдено {$data['count']} объявлений, всего страниц {$data['pageCount']}");
                $this->logger->debug("Получены данные", ['page' => $page, 'count' => $data['count']]);

                $sender = null;
                foreach ($this->telegramSenders as $s) {
                    if ($s->getSource() === $source) {
                        $sender = $s;
                        break;
                    }
                }
                if (!$sender) {
                    $io->error("Нет отправителя для источника: $source");
                    return Command::FAILURE;
                }

                $noTg = $input->getOption('no-tg');
                if ($noTg) {
                    $io->writeln("⚠️ Отправка в Telegram отключена (--no-telegram). Данные будут только сохранены в БД.");
                    $this->logger->info("Отправка в Telegram отключена через --no-telegram");
                }

                foreach ($data['adverts'] as $raw) {
                    $item = $normalizer->normalize($raw);
                    $processed++;

                    $io->writeln("→ Обработка {$item->title} (ID {$item->id})");
                    $this->logger->debug("Обработка элемента", ['id' => $item->id, 'title' => $item->title]);

                    $result = $this->saver->save($item);
                    if (!$result['saved']) {
                        $io->error("Не удалось сохранить ID {$item->id}");
                        $this->logger->error("Ошибка сохранения", ['id' => $item->id]);
                        continue;
                    }

                    $force = $input->getOption('force');

                    if ($force || $result['isNew'] || $result['isPriceChanged']) {
                        $message = $result['isNew']
                            ? "🆕 Новое объявление"
                            : "💰 Изменение цены: {$result['oldPrice']} -> {$item->price} {$item->currency}";
                        $io->writeln("  → $message");

                        if ($noTg) {
                            // Отправка отключена – просто увеличиваем счетчик
                            $io->writeln(
                                "  ⏭️ (отправка пропущена из-за --no-tg)"
                            );
                            // Всё равно считаем как "отправлено" для статистики? Или новый счетчик.
                            // Я создам отдельный счетчик $telegramDisabledCount
                        }
                        else {
                            if ($sender->sendItem($item)) {
                                $sent++;
                                if ($result['isNew']) {
                                    $newCount++;
                                } else {
                                    $changedCount++;
                                }
                            } else {
                                $io->error(
                                    "Не удалось отправить ID {$item->id} в Telegram"
                                );
                                $this->logger->error(
                                    "Ошибка отправки в Telegram",
                                    ['id' => $item->id]
                                );
                            }
                            // Задержка после отправки, чтобы избежать флуда
                            sleep($this->sleepInterval);
                        }

                    } else {
                        $io->writeln("  ⏩ Без изменений (пропущено)");
                        $skippedCount++;
                    }

                    if ($limit > 0 && $processed >= $limit) {
                        $io->text("Достигнут лимит обработанных объявлений ($limit)");
                        $this->logger->info("Достигнут лимит обработанных", ['limit' => $limit]);
                        break 2; // выход из двух циклов (foreach и do-while)
                    }

                    // Небольшая задержка между обработкой элементов, чтобы не перегружать API/Telegram
                    // (закомментировано, т.к. мы уже делаем sleep после отправки)
                }

                $page++;
            } catch (\Throwable $e) {
                $io->error("Ошибка на странице $page: " . $e->getMessage());
                $this->logger->error("Ошибка на странице", ['page' => $page, 'exception' => $e->getMessage()]);
                return Command::FAILURE;
            }
        } while ($page <= $totalPages);

        $io->success(sprintf(
            "Обработано %d, отправлено %d (новых: %d, изменений: %d), пропущено: %d",
            $processed,
            $sent,
            $newCount,
            $changedCount,
            $skippedCount
        ));
        $this->logger->info("Парсинг завершён", [
            'processed' => $processed,
            'sent' => $sent,
            'new' => $newCount,
            'changed' => $changedCount,
            'skipped' => $skippedCount,
        ]);

        return Command::SUCCESS;
    }
}
