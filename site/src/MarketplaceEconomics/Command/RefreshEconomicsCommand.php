<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Command;

use App\MarketplaceEconomics\Application\Action\RefreshEconomicsDayAction;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Пересчёт витрины экономики SKU (cron каждые 15 минут после перевода): дни, заменённые в каноне.
 * `--recent=N` — ещё и все дни с операциями за последние N дней (ночью: себестоимость и реклама
 * легаси меняются без отметки в каноне); `--from/--to` — ручное окно. Каждый день — своя транзакция;
 * сбой дня не останавливает остальные, в конце один `error`.
 */
#[AsCommand(name: 'app:marketplace-economics:refresh', description: 'Rebuilds the SKU economics mart from canonical marketplace operations')]
final class RefreshEconomicsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly MarketplaceOperationsFacadeInterface $operations,
        private readonly RefreshEconomicsDayAction $refresh,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('recent', null, InputOption::VALUE_REQUIRED, 'Also rebuild every day with operations in the last N days')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Rebuild days from (Y-m-d)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Rebuild days to (Y-m-d), default today')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max dirty days per run', '2000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('marketplace-economics refresh: another run is in progress, skipping');

            return self::SUCCESS;
        }

        try {
            $days = [];
            foreach ($this->operations->dirtyDays(max(1, (int) $input->getOption('limit'))) as $day) {
                $days[$day->key()] = $day;
            }
            $today = $this->clock->now()->setTime(0, 0);
            $from = match (true) {
                null !== $input->getOption('from') => new \DateTimeImmutable((string) $input->getOption('from')),
                null !== $input->getOption('recent') => $today->modify(sprintf('-%d days', max(1, (int) $input->getOption('recent')))),
                default => null,
            };
            if (null !== $from) {
                $to = null !== $input->getOption('to') ? new \DateTimeImmutable((string) $input->getOption('to')) : $today;
                foreach ($this->operations->days($from, $to) as $day) {
                    $days[$day->key()] ??= $day;
                }
            }

            $this->logger->info('Marketplace economics refresh started.', ['days' => \count($days)]);
            $done = 0;
            $failed = [];
            foreach ($days as $day) {
                try {
                    $this->connection->transactional(fn () => ($this->refresh)($day));
                    ++$done;
                } catch (\Throwable $e) {
                    $failed[] = ['companyId' => $day->companyId, 'date' => $day->date, 'error' => mb_substr($e->getMessage(), 0, 200)];
                }
            }

            if ([] !== $failed) {
                $this->logger->error('Marketplace economics refresh: days failed.', ['count' => \count($failed), 'days' => \array_slice($failed, 0, 10)]);
            }
            $this->logger->info('Marketplace economics refresh finished.', ['refreshed' => $done, 'failed' => \count($failed)]);
            $output->writeln(sprintf('marketplace-economics refresh: %d days, %d refreshed, %d failed', \count($days), $done, \count($failed)));

            return [] === $failed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
