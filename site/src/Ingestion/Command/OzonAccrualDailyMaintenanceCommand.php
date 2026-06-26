<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\DiscoverExternalCategoriesAction;
use App\Ingestion\Application\Action\RebuildMarketplaceCategoryIdentitiesAction;
use App\Ingestion\Application\Action\SeedExternalCategoryMappingsAction;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Infrastructure\Query\ExternalCategoryAdminQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:daily-maintenance',
    description: 'Runs daily Ozon accrual category taxonomy maintenance for all stored by-day raw records.',
)]
final class OzonAccrualDailyMaintenanceCommand extends Command
{
    use LockableTrait;
    use OzonAccrualCommandHelperTrait;

    private const BUSINESS_TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly SeedExternalCategoryMappingsAction $seedDefaults,
        private readonly OzonAccrualCategoryMetadataBulkRunnerInterface $bulkRunner,
        private readonly DiscoverExternalCategoriesAction $discoverCategories,
        private readonly RebuildMarketplaceCategoryIdentitiesAction $rebuildIdentities,
        private readonly ExternalCategoryAdminQuery $categoryAdminQuery,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days-back', null, InputOption::VALUE_REQUIRED, 'Rolling window size. Used when --from/--to are omitted.', 45)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional start accrual date YYYY-MM-DD. Must be paired with --to.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional end accrual date YYYY-MM-DD. Must be paired with --from.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('limit-per-shop', null, InputOption::VALUE_REQUIRED, 'Raw records to process per company/shop page, 1..500.', 500)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show selected targets and planned metadata updates without writing.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Persist maintenance changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Ozon accrual daily maintenance is already running.</comment>');

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        try {
            return $this->runMaintenance($input, $io);
        } finally {
            $this->release();
        }
    }

    private function runMaintenance(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            [$from, $to, $daysBack] = $this->dateWindow($input);
            $companyId = $this->optionalUuidOption($input, 'company-id');
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $limitPerShop = $this->intOption($input, 'limit-per-shop', 1, 500);
            $mode = $this->mode($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $dryRun = 'dry-run' === $mode;
        $hasFailure = false;

        $io->title('Ozon accrual daily maintenance');
        $io->table(
            ['setting', 'value'],
            [
                ['mode', $dryRun ? 'dry-run' : 'execute'],
                ['from', $from->format('Y-m-d')],
                ['to', $to->format('Y-m-d')],
                ['daysBack', null === $daysBack ? 'custom' : (string) $daysBack],
                ['companyId', $companyId ?? 'all'],
                ['shopRef', $shopRef ?? 'all'],
                ['limitPerShop', (string) $limitPerShop],
            ],
        );

        if ($dryRun) {
            $seedStats = ['skippedDryRun' => 1];
            $io->note('Dry-run mode: seed defaults and taxonomy follow-up are skipped.');
        } else {
            try {
                $seedStats = ($this->seedDefaults)(IngestSource::OZON);
            } catch (\Throwable $exception) {
                $this->logger->error('Ozon accrual daily maintenance seed defaults failed.', [
                    'exception' => $exception,
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'company_id' => $companyId,
                    'shop_ref' => $shopRef,
                ]);
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }
        }

        $io->section('Seed defaults');
        $this->printMetrics($io, $seedStats);

        try {
            $targets = $this->bulkRunner->targets($from, $to, $companyId, $shopRef);
        } catch (\Throwable $exception) {
            $this->logger->error('Ozon accrual daily maintenance target selection failed.', [
                'exception' => $exception,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'company_id' => $companyId,
                'shop_ref' => $shopRef,
            ]);
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->section('Selected company/shop targets');
        $this->printTargets($io, $targets);

        $refreshResult = $this->bulkRunner->refreshTargets($targets, $from, $to, $limitPerShop, $dryRun);
        $refreshTotals = $refreshResult['totals'];
        if ($refreshTotals['failedRawRecords'] > 0 || $refreshTotals['failedTargets'] > 0) {
            $hasFailure = true;
        }

        $io->section('Bulk metadata refresh result');
        $this->printMetrics($io, $refreshTotals);

        if ($dryRun) {
            $io->note('Dry-run only. No canonical transactions or taxonomy rows were changed.');
            $this->printHealth($io, scoped: null !== $companyId || null !== $shopRef);

            return $hasFailure ? Command::FAILURE : Command::SUCCESS;
        }

        try {
            $discover = ($this->discoverCategories)(IngestSource::OZON, 5000);
            $rebuild = $this->rebuildIdentities->rebuild(IngestSource::OZON, execute: true);
        } catch (\Throwable $exception) {
            $hasFailure = true;
            $this->logger->error('Ozon accrual daily maintenance taxonomy follow-up failed.', [
                'exception' => $exception,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'company_id' => $companyId,
                'shop_ref' => $shopRef,
            ]);
            $io->error($exception->getMessage());
            $discover = [];
            $rebuild = [];
        }

        $io->section('Taxonomy follow-up');
        $io->table(
            ['action', 'metric', 'value'],
            array_merge(
                $this->metricRows('discover', $discover),
                $this->metricRows('rebuild-identities', $rebuild),
            ),
        );

        $scopedRun = null !== $companyId || null !== $shopRef;
        $health = $this->health();
        $io->section('Health check');
        $this->printMetrics($io, $health);

        if ($scopedRun) {
            $this->logger->warning('Ozon accrual daily maintenance scoped run skipped global health gate.', [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'company_id' => $companyId,
                'shop_ref' => $shopRef,
                'health' => $health,
            ]);
            $io->note('Scoped run: global taxonomy health is informational and does not affect exit code.');
        } elseif ($health['unclassifiedTransactions'] > 0 || $health['unclassifiedGroups'] > 0) {
            $hasFailure = true;
            $this->logger->error('Ozon accrual daily maintenance health check failed.', [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'company_id' => $companyId,
                'shop_ref' => $shopRef,
                'health' => $health,
            ]);
        } elseif ($health['unmappedCategories'] > 0) {
            $this->logger->warning('Ozon accrual daily maintenance has categories awaiting mapping.', [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'health' => $health,
            ]);
            $io->warning('Ozon accrual categories are awaiting mapping, but canonical transactions are classified.');
        }

        if ($hasFailure) {
            $io->warning('Ozon accrual daily maintenance finished with failures. See logs/Sentry for details.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Ozon accrual daily maintenance finished. Updated %d canonical transactions.', $refreshTotals['updated']));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int|null}
     */
    private function dateWindow(InputInterface $input): array
    {
        $from = $this->optionalDateOption($input, 'from');
        $to = $this->optionalDateOption($input, 'to');
        if ((null === $from) !== (null === $to)) {
            throw new \InvalidArgumentException('Options --from and --to must be provided together.');
        }

        if (null !== $from && null !== $to) {
            if ($from > $to) {
                throw new \InvalidArgumentException('--from cannot be later than --to.');
            }

            return [$from, $to, null];
        }

        $daysBack = $this->intOption($input, 'days-back', 1, 365);
        $today = \DateTimeImmutable::createFromInterface(
            $this->clock->now()->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE)),
        )->setTime(0, 0);

        return [
            $today->modify(sprintf('-%d days', $daysBack)),
            $today->modify('-1 day'),
            $daysBack,
        ];
    }

    private function mode(InputInterface $input): string
    {
        $modes = array_values(array_filter([
            (bool) $input->getOption('dry-run') ? 'dry-run' : null,
            (bool) $input->getOption('execute') ? 'execute' : null,
        ]));

        if (1 !== count($modes)) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
        }

        return $modes[0];
    }

    private function printHealth(SymfonyStyle $io, bool $scoped): void
    {
        $io->section('Health check');
        $this->printMetrics($io, $this->health());
        if ($scoped) {
            $io->note('Scoped run: global taxonomy health is informational and does not affect exit code.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function health(): array
    {
        $unclassified = $this->categoryAdminQuery->unclassifiedOzonAccrualTransactions();

        return [
            'unclassifiedTransactions' => $unclassified['transactions'],
            'unclassifiedGroups' => $unclassified['groups'],
            'unmappedCategories' => $this->unmappedOzonAccrualCategories(),
        ];
    }

    private function unmappedOzonAccrualCategories(): int
    {
        $total = 0;
        foreach ($this->categoryAdminQuery->statusSummary() as $row) {
            if (IngestSource::OZON->value !== $row['source']) {
                continue;
            }

            if (OzonResourceType::ACCRUAL_BY_DAY !== $row['resource_type']) {
                continue;
            }

            if (!\in_array($row['status'], [ExternalCategoryStatus::NEW->value, ExternalCategoryStatus::NEEDS_IDENTIFICATION->value], true)) {
                continue;
            }

            $total += $row['categories'];
        }

        return $total;
    }
}
