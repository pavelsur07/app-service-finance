<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Command;

use App\MarketplaceEconomics\Infrastructure\Query\EconomicsStore;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт витрины (cron раз в сутки, docs/workflow/health-gates.md). Только чтение.
 *
 * - error: Σ строк витрины дня (без себестоимости и справочных) ≠ Σ канона дня — разнесение потеряло деньги;
 * - error: день канона заменён больше BACKLOG_MINUTES назад и не пересчитан — пересчёт стоит.
 *
 * exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:marketplace-economics:health-check', description: 'SKU economics mart gate: balance with canon, refresh backlog')]
final class EconomicsHealthCheckCommand extends Command
{
    public const BACKLOG_MINUTES = 60;

    public function __construct(
        private readonly EconomicsStore $store,
        private readonly ClockInterface $clock,
        private readonly MarketplaceOperationsFacadeInterface $operations,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $unbalanced = $this->store->unbalancedDays();
        if ([] !== $unbalanced) {
            $this->logger->error('Marketplace economics health: mart does not balance with canon.', ['count' => \count($unbalanced), 'days' => \array_slice($unbalanced, 0, 10)]);
        }
        $backlog = \count($this->operations->dirtyDays(1000, $this->clock->now()->modify(sprintf('-%d minutes', self::BACKLOG_MINUTES))));
        if ($backlog > 0) {
            $this->logger->error('Marketplace economics health: changed days are not refreshed.', ['count' => $backlog]);
        }

        $output->writeln(sprintf('marketplace-economics health: %d unbalanced days, %d not refreshed', \count($unbalanced), $backlog));

        return [] === $unbalanced && 0 === $backlog ? self::SUCCESS : self::FAILURE;
    }
}
