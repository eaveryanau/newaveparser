<?php

namespace App\Command;

use App\Service\ItemSaver;
use App\Service\RunLockInterface;
use App\Telegram\TelegramOutbox;
use App\Telegram\TelegramSenderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

class ParseCommand extends Command
{
    public function __construct(
        private iterable $apiClients,
        private iterable $itemNormalizers,
        private iterable $telegramSenders,
        private ItemSaver $saver,
        private TelegramOutbox $outbox,
        private RunLockInterface $runLock,
        private int $sleepInterval,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
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
        $source = (string) $input->getOption('source');

        if (!$this->runLock->acquire($source)) {
            $io->warning("Парсинг источника $source уже выполняется, запуск пропущен");
            $this->logger->info('Запуск пропущен: уже выполняется', ['source' => $source]);

            return Command::SUCCESS;
        }

        try {
            return $this->parse($input, $io, $source);
        } finally {
            $this->runLock->release();
        }
    }

    private function parse(InputInterface $input, SymfonyStyle $io, string $source): int
    {
        $pages = (int) $input->getOption('pages');
        $limit = (int) $input->getOption('limit');
        $force = (bool) $input->getOption('force');
        $noTg = (bool) $input->getOption('no-tg');

        $client = null;
        foreach ($this->apiClients as $candidate) {
            if ($candidate->getSource() === $source) {
                $client = $candidate;
                break;
            }
        }
        if ($client === null) {
            $io->error("Неизвестный источник: $source. Доступные: avby, hh, another");

            return Command::FAILURE;
        }

        $normalizer = null;
        foreach ($this->itemNormalizers as $candidate) {
            if ($candidate->supports($source)) {
                $normalizer = $candidate;
                break;
            }
        }
        if ($normalizer === null) {
            $io->error("Нет нормализатора для источника: $source");

            return Command::FAILURE;
        }

        $sender = null;
        if (!$noTg) {
            foreach ($this->telegramSenders as $candidate) {
                if ($candidate instanceof TelegramSenderInterface && $candidate->getSource() === $source) {
                    $sender = $candidate;
                    break;
                }
            }
            if ($sender === null) {
                $io->error("Нет отправителя для источника: $source");

                return Command::FAILURE;
            }
        }

        $io->title("Парсинг источника: $source");
        $this->logger->info('Запуск парсинга', [
            'source' => $source,
            'pages' => $pages,
            'limit' => $limit,
        ]);

        if ($noTg) {
            $io->writeln('Отправка в Telegram отключена (--no-tg). Данные будут только сохранены в БД.');
            $this->logger->info('Отправка в Telegram отключена через --no-tg');
        }

        $processed = 0;
        $sent = 0;
        $newCount = 0;
        $changedCount = 0;
        $skippedCount = 0;
        $queuedCount = 0;
        $retriedCount = 0;
        $sentKeys = [];

        if (!$noTg) {
            $retriedCount = $this->drainOutbox($source, $sender, $io, $sentKeys);
            $sent += $retriedCount;
        }

        $page = 1;
        $totalPages = ($pages > 0) ? $pages : null;

        do {
            $io->section("Страница $page");
            try {
                $data = $client->fetchPage($page);
                if ($totalPages === null) {
                    $totalPages = $data['pageCount'];
                }
                $io->text("Найдено {$data['count']} объявлений, всего страниц {$data['pageCount']}");
                $this->logger->debug('Получены данные', ['page' => $page, 'count' => $data['count']]);

                foreach ($data['adverts'] as $raw) {
                    $item = $normalizer->normalize($raw);
                    $processed++;

                    $io->writeln("→ Обработка {$item->title} (ID {$item->id})");
                    $this->logger->debug('Обработка элемента', ['id' => $item->id, 'title' => $item->title]);

                    $result = $this->saver->save($item);
                    if (!$result['saved']) {
                        $io->error("Не удалось сохранить ID {$item->id}");
                        $this->logger->error('Ошибка сохранения', ['id' => $item->id]);
                        continue;
                    }

                    $key = $this->itemKey($item->source, $item->id);
                    $shouldNotify = $force || $result['isNew'] || $result['isPriceChanged'];

                    if (!$shouldNotify) {
                        $io->writeln('  ⏩ Без изменений (пропущено)');
                        $skippedCount++;
                    } elseif (isset($sentKeys[$key])) {
                        $this->outbox->remove($item->id, $item->source);
                        $io->writeln('  ⏭️ Уже отправлено из очереди в этом запуске');
                    } elseif ($noTg) {
                        $io->writeln('  ⏭️ (отправка пропущена из-за --no-tg)');
                    } else {
                        $message = $result['isNew']
                            ? '🆕 Новое объявление'
                            : "💰 Изменение цены: {$result['oldPrice']} -> {$item->price} {$item->currency}";
                        $io->writeln("  → $message");

                        $sendResult = $sender->sendItem($item);
                        sleep($this->sleepInterval);

                        if ($sendResult->success) {
                            $sent++;
                            $this->outbox->remove($item->id, $item->source);
                            if ($result['isNew']) {
                                $newCount++;
                            } else {
                                $changedCount++;
                            }
                        } elseif ($sendResult->retryable) {
                            $this->outbox->recordFailure(
                                $item,
                                $sendResult->error ?? 'Неизвестная ошибка',
                                $sendResult->retryAfter
                            );
                            $queuedCount++;
                            $io->error("Не удалось отправить ID {$item->id}, добавлено в очередь");
                            $this->logger->error('Ошибка отправки в Telegram, добавлено в очередь', [
                                'id' => $item->id,
                                'error' => $sendResult->error,
                            ]);
                        } else {
                            $io->error("Не удалось отправить ID {$item->id}: " . ($sendResult->error ?? ''));
                            $this->logger->error('Постоянная ошибка отправки в Telegram', [
                                'id' => $item->id,
                                'error' => $sendResult->error,
                            ]);
                        }
                    }

                    if ($limit > 0 && $processed >= $limit) {
                        $io->text("Достигнут лимит обработанных объявлений ($limit)");
                        $this->logger->info('Достигнут лимит обработанных', ['limit' => $limit]);
                        break 2;
                    }
                }

                $page++;
            } catch (\Throwable $e) {
                $io->error("Ошибка на странице $page: " . $e->getMessage());
                $this->logger->error('Ошибка на странице', [
                    'page' => $page,
                    'exception' => $e->getMessage(),
                ]);

                return Command::FAILURE;
            }
        } while ($page <= $totalPages);

        $remaining = $this->outbox->count($source);

        $io->success(sprintf(
            'Обработано %d, отправлено %d (новых: %d, изменений: %d), из очереди: %d, в очередь: %d, пропущено: %d, осталось в очереди: %d',
            $processed,
            $sent,
            $newCount,
            $changedCount,
            $retriedCount,
            $queuedCount,
            $skippedCount,
            $remaining
        ));
        $this->logger->info('Парсинг завершён', [
            'processed' => $processed,
            'sent' => $sent,
            'new' => $newCount,
            'changed' => $changedCount,
            'retried' => $retriedCount,
            'queued' => $queuedCount,
            'skipped' => $skippedCount,
            'remaining' => $remaining,
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, true> $sentKeys
     */
    private function drainOutbox(
        string $source,
        TelegramSenderInterface $sender,
        SymfonyStyle $io,
        array &$sentKeys
    ): int {
        $entries = $this->outbox->due($source);
        if ($entries === []) {
            return 0;
        }

        $io->section('Очередь Telegram');
        $sent = 0;

        foreach ($entries as $entry) {
            $item = $entry->item;
            $sendResult = $sender->sendItem($item);

            if ($sendResult->success) {
                $this->outbox->remove($item->id, $item->source);
                $sentKeys[$this->itemKey($item->source, $item->id)] = true;
                $sent++;
                $io->writeln("  ✅ Из очереди отправлено {$item->title}");
                $this->logger->info('Элемент из очереди отправлен', ['id' => $item->id]);
                sleep($this->sleepInterval);
            } else {
                $this->outbox->markFailed(
                    $entry,
                    $sendResult->error ?? 'Неизвестная ошибка',
                    $sendResult->retryAfter
                );
                $io->error("Не удалось отправить из очереди ID {$item->id}");
                $this->logger->error('Не удалось отправить элемент из очереди', [
                    'id' => $item->id,
                    'error' => $sendResult->error,
                ]);
            }
        }

        return $sent;
    }

    private function itemKey(string $source, string $id): string
    {
        return $source . ':' . $id;
    }
}
