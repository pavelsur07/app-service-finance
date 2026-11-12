<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Application\DTO\OzonSuppliesSyncResult;
use App\Inventory\Application\SyncOzonSuppliesAction;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Shared\Service\AppLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

/**
 * Поставки Ozon в пути: по каждому активному seller-подключению — последний снимок `ozon.supplies`
 * из ozon-fetcher в остатки дня (SyncOzonSuppliesAction). Cron 05:50 МСК.
 */
#[AsCommand(
    name: 'app:inventory:ozon-supplies-sync',
    description: 'Writes active Ozon FBO supply orders from ozon-fetcher into the day stock snapshot (status in_supply)',
)]
final class OzonSuppliesSyncCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly SyncOzonSuppliesAction $action,
        private readonly AppLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Only this company (UUID).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Another ozon-supplies-sync is running, skipping.');

            return Command::SUCCESS;
        }

        try {
            $companyId = $input->getOption('company-id');
            if (null !== $companyId) {
                Assert::uuid($companyId, 'Invalid --company-id.');
            }
            $today = new \DateTimeImmutable('today');
            $failed = 0;
            $connections = $this->marketplaceFacade->getActiveOzonSellerConnections(null === $companyId ? null : (string) $companyId);
            foreach ($connections as $connection) {
                try {
                    $result = ($this->action)($connection['companyId'], $connection['connectionId'], $today);
                } catch (\Throwable $e) {
                    ++$failed;
                    $this->logger->error('Ozon supplies sync failed.', $e, ['companyId' => $connection['companyId'], 'connectionId' => $connection['connectionId']]);
                    $output->writeln(sprintf('%s: ошибка %s', $connection['companyId'], $e::class));
                    continue;
                }
                $output->writeln(OzonSuppliesSyncResult::WRITTEN === $result->outcome
                    ? sprintf('%s: снимок %s, позиций %d, строк %d, штук %s', $connection['companyId'], $result->snapshotDate, $result->items, $result->rows, $result->quantity)
                    : sprintf('%s: пропуск (%s)', $connection['companyId'], $result->outcome));
            }

            return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
        } finally {
            $this->release();
        }
    }
}
