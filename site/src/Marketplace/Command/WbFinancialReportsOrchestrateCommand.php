<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:marketplace:wb-financial-reports:orchestrate',
    description: 'Safe WB finance recovery orchestration with cooldown-aware one-task-per-connection scheduling.',
)]
final class WbFinancialReportsOrchestrateCommand extends Command
{
    use LockableTrait;

    private const REPORT_TYPE = 'sales_report';
    private const GLOBAL_BUCKET = 'global';

    public function __construct(
        private readonly ActiveWbConnectionsQuery $activeWbConnectionsQuery,
        private readonly WbFinanceRateLimiter $rateLimiter,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly WbFinancialReportSyncPlannerInterface $planner,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('connection-id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('refresh-days-back', null, InputOption::VALUE_OPTIONAL, 'Refresh only the last N business days before today.', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $rows = [];
        $totalDispatched = 0;

        try {
            $companyId = $this->normalizeOptional((string) $input->getOption('company-id'));
            $connectionId = $this->normalizeOptional((string) $input->getOption('connection-id'));
            $refreshDaysBack = max(1, (int) $input->getOption('refresh-days-back'));
            $globalCooldownUntil = $this->rateLimiter->getActiveSalesReportsCooldownUntil(self::GLOBAL_BUCKET);
            if (null !== $globalCooldownUntil) {
                $message = 'WB finance global cooldown is active but orchestrator continues connection-scoped planning.';
                $this->logger->warning($message, ['cooldown_until' => $globalCooldownUntil->format(\DateTimeInterface::ATOM)]);
                $io->warning($message.' cooldown_until='.$globalCooldownUntil->format(\DateTimeInterface::ATOM));
            }
            $yesterday = $this->periodResolver->yesterday();

            foreach ($this->activeWbConnectionsQuery->execute($companyId, $connectionId) as $activeConnection) {
                $connectionIdValue = (string) $activeConnection['connection_id'];
                $companyIdValue = (string) $activeConnection['company_id'];
                $action = 'skipped';
                $reason = 'no candidate';
                $dispatched = 0;

                $connectionCooldownUntil = $this->rateLimiter->getActiveSalesReportsCooldownUntil('connection:'.$connectionIdValue);

                $dueRetryCount = $this->countDueRetry($companyIdValue, $connectionIdValue);
                $futureQueuedCount = $this->countFutureQueued($companyIdValue, $connectionIdValue);

                if (null !== $connectionCooldownUntil) {
                    $reason = 'connection cooldown until '.$connectionCooldownUntil->format(\DateTimeInterface::ATOM);
                } elseif ($futureQueuedCount > 0) {
                    $reason = 'queued future retry exists';
                } else {
                    $dailyStatus = $this->findDailyStatus($companyIdValue, $connectionIdValue, $yesterday);
                    if (!\in_array($dailyStatus, ['success', 'empty'], true)) {
                        $dispatched = $this->planner->planDaily($companyIdValue, $connectionIdValue, false);
                        $action = $dispatched > 0 ? 'daily yesterday' : 'daily skipped by claim';
                        $reason = $dispatched > 0 ? 'planned' : 'status not claimable';
                    }

                    if (0 === $dispatched && $dueRetryCount > 0) {
                        $dispatched = $this->planner->planDueRetry($companyIdValue, $connectionIdValue, 1);
                        $action = $dispatched > 0 ? 'due retry' : 'due retry skipped by claim';
                        $reason = $dispatched > 0 ? 'planned' : 'status not claimable';
                    }

                    if (0 === $dispatched && 0 === $dueRetryCount) {
                        $dispatched = $this->planner->planRefreshRecentDays($companyIdValue, $connectionIdValue, $refreshDaysBack, 1);
                        if ($dispatched > 0) {
                            $action = 'refresh last '.$refreshDaysBack.' days';
                            $reason = 'planned';
                        }
                    }

                    if (0 === $dispatched && 0 === $dueRetryCount) {
                        $dispatched = $this->planner->planMissing($companyIdValue, $connectionIdValue, 1);
                        if ($dispatched > 0) {
                            $action = 'historical missing';
                            $reason = 'planned';
                        }
                    }
                }

                $totalDispatched += $dispatched;
                $rows[] = [$companyIdValue, $connectionIdValue, $action, (string) $dispatched, $reason];
            }

            $io->table(['company_id', 'connection_id', 'action', 'dispatched', 'reason'], $rows);
            $io->success(sprintf('WB finance orchestration completed. Dispatched %d task(s).', $totalDispatched));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }

    private function countDueRetry(string $companyId, string $connectionId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM marketplace_financial_report_sync_statuses
             WHERE company_id = :companyId
               AND connection_id = :connectionId
               AND marketplace = 'wildberries'
               AND report_type = :reportType
               AND business_date BETWEEN :fromDate AND :toDate
               AND (
                    (status IN ('queued', 'failed') AND next_retry_at IS NOT NULL AND next_retry_at <= NOW())
                    OR (status = 'failed' AND next_retry_at IS NULL AND last_error_status_code = 429 AND last_error_class = :rateLimitErrorClass)
               )",
            [
                'companyId' => $companyId,
                'connectionId' => $connectionId,
                'reportType' => self::REPORT_TYPE,
                'fromDate' => $this->periodResolver->currentYearStart()->format('Y-m-d'),
                'toDate' => $this->periodResolver->yesterday()->format('Y-m-d'),
                'rateLimitErrorClass' => MarketplaceRateLimitException::class,
            ],
        );
    }

    private function countFutureQueued(string $companyId, string $connectionId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM marketplace_financial_report_sync_statuses
             WHERE company_id = :companyId
               AND connection_id = :connectionId
               AND marketplace = 'wildberries'
               AND report_type = :reportType
               AND status = 'queued'
               AND next_retry_at IS NOT NULL
               AND next_retry_at > NOW()",
            ['companyId' => $companyId, 'connectionId' => $connectionId, 'reportType' => self::REPORT_TYPE],
        );
    }

    private function findDailyStatus(string $companyId, string $connectionId, \DateTimeImmutable $businessDate): ?string
    {
        $status = $this->connection->fetchOne(
            "SELECT status
             FROM marketplace_financial_report_sync_statuses
             WHERE company_id = :companyId
               AND connection_id = :connectionId
               AND marketplace = 'wildberries'
               AND report_type = :reportType
               AND business_date = :businessDate
               AND mode = 'daily'
             LIMIT 1",
            [
                'companyId' => $companyId,
                'connectionId' => $connectionId,
                'reportType' => self::REPORT_TYPE,
                'businessDate' => $businessDate->format('Y-m-d'),
            ],
        );

        return false === $status || null === $status ? null : (string) $status;
    }

    private function normalizeOptional(string $value): ?string
    {
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
