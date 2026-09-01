<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Facade\MarketplaceFacade;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ставит в очередь загрузку каталога товаров Ozon.
 *
 * Только диспетчеризация: HTTP-обход каталога выполняет
 * {@see \App\Marketplace\MessageHandler\SyncOzonListingCatalogHandler} в воркере.
 *
 * Ночной прогон идёт из cron без аргументов; `--company` даёт ручной запуск
 * по одной компании, не дожидаясь ночи.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-listing-catalog:sync',
    description: 'Queues Ozon product catalog sync for active SELLER connections',
)]
final class OzonListingCatalogSyncCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'company',
            null,
            InputOption::VALUE_REQUIRED,
            'Limit dispatch to a single company UUID',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('start');
            $output->writeln('Another Ozon listing catalog sync is running, skipping.');
            $output->writeln('finish');

            return self::SUCCESS;
        }

        try {
            $output->writeln('start');

            $companyId = $input->getOption('company');
            $companyId = is_string($companyId) && '' !== trim($companyId) ? trim($companyId) : null;

            $connections = $this->marketplaceFacade->getActiveOzonSellerConnections($companyId);

            $queuedCount = 0;
            $errorsCount = 0;

            foreach ($connections as $row) {
                $rowCompanyId = trim($row['companyId']);
                $connectionId = trim($row['connectionId']);

                if ('' === $rowCompanyId || '' === $connectionId) {
                    ++$errorsCount;
                    $this->logger->error('[OzonListingCatalog] Connection row without identifiers skipped.', [
                        'company_id' => $rowCompanyId,
                        'connection_id' => $connectionId,
                    ]);
                    $output->writeln('skipped connection without identifiers');
                    continue;
                }

                try {
                    $this->messageBus->dispatch(new SyncOzonListingCatalogMessage($rowCompanyId, $connectionId));
                    ++$queuedCount;
                } catch (\Throwable $e) {
                    ++$errorsCount;
                    // Только в stdout нельзя: cron зовёт команду с --quiet, и
                    // пропущенная синхронизация исчезла бы бесследно.
                    // Текст исключения транспорта в лог не кладём — только класс.
                    $this->logger->error('[OzonListingCatalog] Dispatch failed.', [
                        'company_id' => $rowCompanyId,
                        'connection_id' => $connectionId,
                        'exception_class' => $e::class,
                    ]);
                    // Класс, а не текст: сообщение исключения транспорта может
                    // содержать DSN с учётными данными.
                    $output->writeln(sprintf('connection %s error: %s', $connectionId, $e::class));
                }
            }

            $output->writeln(sprintf('active connections count: %d / queued count: %d', count($connections), $queuedCount));
            $output->writeln(sprintf('errors count: %d', $errorsCount));
            $output->writeln('finish');

            // Частичный сбой остаётся SUCCESS: безусловный FAILURE при одном битом
            // подключении сделал бы cron вечно красным. Красным становится только
            // прогон, не поставивший ни одной задачи именно из-за ошибок.
            return $errorsCount > 0 && 0 === $queuedCount ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('[OzonListingCatalog] Orchestration failed.', [
                'exception_class' => $e::class,
            ]);
            $output->writeln('errors count: 1');
            $output->writeln(sprintf('orchestration failure: %s', $e::class));
            $output->writeln('finish');

            return self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
