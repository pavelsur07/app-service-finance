<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\DTO\ShopDescriptor;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualPreviewTransaction;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Domain\Service\ConnectorRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Query\OzonAccrualRawRecordQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:verify-rolling-refresh',
    description: 'Checks latest Ozon accrual by-day raw coverage against normalized financial transactions.',
)]
final class OzonAccrualVerifyRollingRefreshCommand extends Command
{
    use LockableTrait;

    private const BUSINESS_TIMEZONE = 'Europe/Moscow';
    private const MAX_DAYS_BACK = 365;
    private const MAX_TARGET_LIMIT = 500;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly ConnectorRegistry $connectorRegistry,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly OzonAccrualByDayPreviewMapper $previewMapper,
        private readonly OzonAccrualRawRecordQuery $rawRecordQuery,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days-back', null, InputOption::VALUE_REQUIRED, 'Rolling verification depth in days, 1..365.', 45)
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum shop targets per run, 1..500.', 100)
            ->addOption('raw-limit', null, InputOption::VALUE_REQUIRED, 'Latest raw records to scan per shop, 1..500.', 100)
            ->addOption('raw-row-limit', null, InputOption::VALUE_REQUIRED, 'Rows to scan per raw record, 1..100000.', 50000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Ozon accrual rolling refresh verification is already running.');

            return Command::SUCCESS;
        }

        try {
            return $this->runVerification($input, $io);
        } finally {
            $this->release();
        }
    }

    private function runVerification(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $daysBack = $this->intOption($input, 'days-back', 1, self::MAX_DAYS_BACK);
            $companyId = $this->companyId($input);
            $shopRef = $this->stringOption($input, 'shop-ref');
            $limit = $this->intOption($input, 'limit', 1, self::MAX_TARGET_LIMIT);
            $rawLimit = $this->intOption($input, 'raw-limit', 1, 500);
            $rawRowLimit = $this->intOption($input, 'raw-row-limit', 1, 100000);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        [$from, $to] = $this->window($daysBack);
        $targets = array_slice($this->targets($companyId, $shopRef), 0, $limit);

        $io->title('Ozon accrual rolling refresh verification');
        $io->table(['setting', 'value'], [
            ['from', $from->format('Y-m-d')],
            ['to', $to->format('Y-m-d')],
            ['daysBack', (string) $daysBack],
            ['companyId', $companyId ?? 'all'],
            ['shopRef', $shopRef ?? 'all'],
            ['limit', (string) $limit],
            ['rawLimit', (string) $rawLimit],
            ['rawRowLimit', (string) $rawRowLimit],
            ['targets', (string) count($targets)],
        ]);

        if ([] === $targets) {
            $io->warning('No active Ozon seller shop targets found.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failedTargets = 0;
        $totals = [
            'targets' => count($targets),
            'rawRecords' => 0,
            'previewRows' => 0,
            'nonDoneRaw' => 0,
            'unknownCategoryRows' => 0,
            'amountMismatches' => 0,
            'countMismatches' => 0,
        ];

        foreach ($targets as $target) {
            $result = $this->verifyTarget($target, $from, $to, $rawLimit, $rawRowLimit);

            foreach ($totals as $metric => $value) {
                if ('targets' === $metric) {
                    continue;
                }

                $totals[$metric] += $result[$metric];
            }

            if (!$result['ok']) {
                ++$failedTargets;
            }

            $rows[] = [
                $target['companyId'],
                $target['shopRef'],
                $result['ok'] ? 'ok' : 'fail',
                (string) $result['rawRecords'],
                (string) $result['previewRows'],
                (string) $result['nonDoneRaw'],
                (string) $result['unknownCategoryRows'],
                (string) $result['amountMismatches'],
                (string) $result['countMismatches'],
            ];
        }

        $io->section('Verification result');
        $io->table(
            ['companyId', 'shopRef', 'status', 'rawRecords', 'previewRows', 'nonDoneRaw', 'unknownCategories', 'amountMismatches', 'countMismatches'],
            $rows,
        );
        $summaryRows = [];
        foreach ($totals + ['failedTargets' => $failedTargets] as $metric => $value) {
            $summaryRows[] = [$metric, (string) $value];
        }

        $io->table(['metric', 'value'], $summaryRows);

        $this->logger->info('Ozon accrual rolling refresh verification finished.', $totals + [
            'failedTargets' => $failedTargets,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'companyId' => $companyId,
            'shopRef' => $shopRef,
        ]);

        if ($failedTargets > 0) {
            $this->logger->warning('Ozon accrual rolling refresh verification found mismatches.', [
                'failedTargets' => $failedTargets,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{companyId: string, connectionRef: string, shopRef: string} $target
     *
     * @return array{ok: bool, rawRecords: int, previewRows: int, nonDoneRaw: int, unknownCategoryRows: int, amountMismatches: int, countMismatches: int}
     */
    private function verifyTarget(array $target, \DateTimeImmutable $from, \DateTimeImmutable $to, int $rawLimit, int $rawRowLimit): array
    {
        $rawRecords = $this->rawRecordQuery->latestCoverageRows($target['companyId'], $target['shopRef'], $from, $to, $rawLimit);
        $doneRawRecords = array_values(array_filter(
            $rawRecords,
            static fn (array $row): bool => RawNormalizationStatus::DONE->value === (string) $row['normalization_status'],
        ));
        $nonDoneRaw = count($rawRecords) - count($doneRawRecords);

        $previewRows = $this->previewMapper->preview(
            $target['companyId'],
            $this->rawRows($target['companyId'], $doneRawRecords, $rawRowLimit, $from, $to),
            $from,
            $to,
            includeSaleRefund: true,
        );

        $parityRows = $this->parityRows($previewRows, $this->canonicalGroups($target['companyId'], $target['shopRef'], $from, $to));
        $unknownCategoryRows = 0;
        foreach ($previewRows as $row) {
            if (false === $row->ozonCategoryKnown) {
                ++$unknownCategoryRows;
            }
        }

        $amountMismatches = 0;
        $countMismatches = 0;
        foreach ($parityRows as $row) {
            if (0 !== $row['totalDeltaMinor']) {
                ++$amountMismatches;
            }

            if (0 !== $row['countDelta']) {
                ++$countMismatches;
            }
        }

        $ok = [] !== $rawRecords
            && 0 === $nonDoneRaw
            && 0 === $unknownCategoryRows
            && 0 === $amountMismatches
            && 0 === $countMismatches;

        return [
            'ok' => $ok,
            'rawRecords' => count($rawRecords),
            'previewRows' => count($previewRows),
            'nonDoneRaw' => $nonDoneRaw,
            'unknownCategoryRows' => $unknownCategoryRows,
            'amountMismatches' => $amountMismatches,
            'countMismatches' => $countMismatches,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawRecords
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function rawRows(
        string $companyId,
        array $rawRecords,
        int $rowLimit,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): \Generator {
        foreach ($rawRecords as $rawRecord) {
            $recordRows = 0;
            $selectedDates = $this->selectedDateSet($rawRecord);
            foreach ($this->rawStorageFacade->read((string) $rawRecord['id'], $companyId) as $row) {
                if ($recordRows >= $rowLimit) {
                    break;
                }

                ++$recordRows;
                if (!$this->rowDateInWindow($row, $from, $to, $selectedDates)) {
                    continue;
                }

                yield $row;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, true> $selectedDates
     */
    private function rowDateInWindow(array $row, \DateTimeImmutable $from, \DateTimeImmutable $to, array $selectedDates): bool
    {
        $date = trim((string) ($row['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        if ([] !== $selectedDates) {
            return isset($selectedDates[$date]);
        }

        return $date >= $from->format('Y-m-d') && $date <= $to->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $rawRecord
     *
     * @return array<string, true>
     */
    private function selectedDateSet(array $rawRecord): array
    {
        $dates = $rawRecord['selected_dates'] ?? [];
        if (!is_array($dates)) {
            return [];
        }

        $set = [];
        foreach ($dates as $date) {
            $set[(string) $date] = true;
        }

        return $set;
    }

    /**
     * @return array<string, array{count: int, totalMinor: int}>
     */
    private function canonicalGroups(string $companyId, string $shopRef, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DATE(occurred_at) AS date,
                    type,
                    direction,
                    COUNT(*) AS count,
                    COALESCE(SUM(amount_minor), 0) AS total_minor
             FROM ingest_financial_transactions
             WHERE company_id = :companyId
               AND source = :source
               AND shop_ref = :shopRef
               AND occurred_at >= :fromAt
               AND occurred_at < :toExclusive
             GROUP BY DATE(occurred_at), type, direction',
            [
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
                'shopRef' => $shopRef,
                'fromAt' => $from->format('Y-m-d 00:00:00'),
                'toExclusive' => $to->modify('+1 day')->format('Y-m-d 00:00:00'),
            ],
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[$this->groupKey((string) $row['date'], (string) $row['type'], (string) $row['direction'])] = [
                'count' => (int) $row['count'],
                'totalMinor' => (int) $row['total_minor'],
            ];
        }

        return $groups;
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $previewRows
     * @param array<string, array{count: int, totalMinor: int}> $canonicalGroups
     *
     * @return list<array{countDelta: int, totalDeltaMinor: int}>
     */
    private function parityRows(array $previewRows, array $canonicalGroups): array
    {
        $previewGroups = $this->previewGroups($previewRows);
        $keys = array_values(array_unique(array_merge(array_keys($previewGroups), array_keys($canonicalGroups))));
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            $previewCount = (int) ($previewGroups[$key]['count'] ?? 0);
            $previewTotal = (int) ($previewGroups[$key]['totalMinor'] ?? 0);
            $canonicalCount = (int) ($canonicalGroups[$key]['count'] ?? 0);
            $canonicalTotal = (int) ($canonicalGroups[$key]['totalMinor'] ?? 0);

            $rows[] = [
                'countDelta' => $previewCount - $canonicalCount,
                'totalDeltaMinor' => $previewTotal - $canonicalTotal,
            ];
        }

        return $rows;
    }

    /**
     * @param list<OzonAccrualPreviewTransaction> $previewRows
     *
     * @return array<string, array{count: int, totalMinor: int}>
     */
    private function previewGroups(array $previewRows): array
    {
        $groups = [];
        foreach ($previewRows as $row) {
            $key = $this->groupKey($row->date, $row->type->value, $row->direction->value);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'count' => 0,
                    'totalMinor' => 0,
                ];
            }

            ++$groups[$key]['count'];
            $groups[$key]['totalMinor'] += $row->amountMinor;
        }

        return $groups;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function window(int $daysBack): array
    {
        $today = \DateTimeImmutable::createFromInterface(
            $this->clock->now()->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE)),
        )->setTime(0, 0);

        return [$today->modify(sprintf('-%d days', $daysBack)), $today->modify('-1 day')];
    }

    /**
     * @return list<array{companyId: string, connectionRef: string, shopRef: string}>
     */
    private function targets(?string $companyId, ?string $shopRef): array
    {
        $targets = [];
        $connector = $this->connectorRegistry->get(IngestSource::OZON, OzonResourceType::ACCRUAL_BY_DAY);

        foreach ($this->connectionsQuery->execute() as $connection) {
            if (MarketplaceType::OZON->value !== (string) $connection['marketplace']) {
                continue;
            }

            $connectionCompanyId = (string) $connection['company_id'];
            if (null !== $companyId && $connectionCompanyId !== $companyId) {
                continue;
            }

            $connectionRef = (string) $connection['id'];

            try {
                $shops = $connector->discoverShops($connectionCompanyId, $connectionRef);
            } catch (\Throwable $exception) {
                $this->logger->warning('Failed to discover Ozon accrual verification shops.', [
                    'companyId' => $connectionCompanyId,
                    'connectionRef' => $connectionRef,
                    'exceptionClass' => $exception::class,
                    'errorMessage' => $exception->getMessage(),
                ]);
                continue;
            }

            foreach ($shops as $shop) {
                if (!$shop instanceof ShopDescriptor) {
                    continue;
                }

                if (null !== $shopRef && $shop->externalId !== $shopRef) {
                    continue;
                }

                $targets[] = [
                    'companyId' => $connectionCompanyId,
                    'connectionRef' => $connectionRef,
                    'shopRef' => $shop->externalId,
                ];
            }
        }

        return $targets;
    }

    private function groupKey(string $date, string $type, string $direction): string
    {
        return implode('|', [$date, $type, $direction]);
    }

    private function companyId(InputInterface $input): ?string
    {
        $companyId = $this->stringOption($input, 'company-id');
        if (null === $companyId) {
            return null;
        }

        Assert::uuid($companyId, 'Invalid --company-id UUID.');

        return $companyId;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) $input->getOption($name));

        return '' === $value ? null : $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (string) $input->getOption($name);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        $intValue = (int) $value;
        if ($intValue < $min || $intValue > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        return $intValue;
    }
}
