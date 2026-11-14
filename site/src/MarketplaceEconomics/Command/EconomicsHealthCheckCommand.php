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
 * - error: день канона заменён больше BACKLOG_MINUTES назад и не пересчитан — пересчёт стоит;
 * - error: нераспределённое за UNALLOCATED_DAYS дней > UNALLOCATED_PERCENT % затрат компании —
 *   разнесение сломалось (нет выручки, остатков или долей рекламы).
 *
 * exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:marketplace-economics:health-check', description: 'SKU economics mart gate: balance with canon, refresh backlog')]
final class EconomicsHealthCheckCommand extends Command
{
    public const BACKLOG_MINUTES = 60;
    public const UNALLOCATED_DAYS = 7;
    public const UNALLOCATED_PERCENT = 1.0;

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

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $unallocated = array_values(array_filter(
            $this->store->unallocatedTotals($today->modify(sprintf('-%d days', self::UNALLOCATED_DAYS)), $today),
            static fn (array $r): bool => $r['unallocated_minor'] * 100 > $r['costs_minor'] * self::UNALLOCATED_PERCENT,
        ));
        if ([] !== $unallocated) {
            $this->logger->error('Marketplace economics health: unallocated share is above threshold.', ['count' => \count($unallocated), 'companies' => array_map(
                static fn (array $r): array => ['companyId' => $r['company_id'], 'unallocatedRub' => $r['unallocated_minor'] / 100, 'percent' => $r['costs_minor'] > 0 ? round($r['unallocated_minor'] * 100 / $r['costs_minor'], 1) : null],
                $unallocated,
            )]);
        }

        $output->writeln(sprintf('marketplace-economics health: %d unbalanced days, %d not refreshed, %d companies over unallocated threshold', \count($unbalanced), $backlog, \count($unallocated)));

        return [] === $unbalanced && 0 === $backlog && [] === $unallocated ? self::SUCCESS : self::FAILURE;
    }
}
