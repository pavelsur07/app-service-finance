<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Command\StartBackfillCommand as StartBackfillApplicationCommand;
use App\Ingestion\Application\DTO\ShopDescriptor;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Domain\Service\ConnectorRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Facade\SyncFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
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
    name: 'app:ingestion:ozon-accrual:rolling-refresh',
    description: 'Dispatches rolling Ozon accrual by-day backfills for active Ozon seller connections.',
)]
final class OzonAccrualRollingRefreshCommand extends Command
{
    use LockableTrait;

    private const BUSINESS_TIMEZONE = 'Europe/Moscow';
    private const MAX_DAYS_BACK = 365;
    private const MAX_LIMIT = 500;
    private const MAX_DISPATCH_SPACING_SECONDS = 3600;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly ConnectorRegistry $connectorRegistry,
        private readonly SyncFacade $syncFacade,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days-back', null, InputOption::VALUE_REQUIRED, 'Rolling refresh depth in days, 1..365.', 45)
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum shop targets per run, 1..500.', 50)
            ->addOption('dispatch-spacing-seconds', null, InputOption::VALUE_REQUIRED, 'Delay between shop backfill dispatches, 0..3600.', 0)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print planned refresh jobs without dispatching them.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Dispatch refresh jobs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Ozon accrual rolling refresh is already running.');

            return Command::SUCCESS;
        }

        try {
            return $this->runRefresh($input, $io);
        } finally {
            $this->release();
        }
    }

    private function runRefresh(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $dryRun = (bool) $input->getOption('dry-run');
            $execute = (bool) $input->getOption('execute');
            if ($dryRun === $execute) {
                throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
            }

            $daysBack = $this->intOption($input, 'days-back', 1, self::MAX_DAYS_BACK);
            $companyId = $this->companyId($input);
            $shopRef = $this->stringOption($input, 'shop-ref');
            $limit = $this->intOption($input, 'limit', 1, self::MAX_LIMIT);
            $dispatchSpacingSeconds = $this->intOption($input, 'dispatch-spacing-seconds', 0, self::MAX_DISPATCH_SPACING_SECONDS);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        [$from, $to] = $this->window($daysBack);
        $targets = array_slice($this->targets($companyId, $shopRef), 0, $limit);

        $io->title('Ozon accrual rolling refresh');
        $io->table(['setting', 'value'], [
            ['mode', $dryRun ? 'dry-run' : 'execute'],
            ['from', $from->format('Y-m-d')],
            ['to', $to->format('Y-m-d')],
            ['daysBack', (string) $daysBack],
            ['companyId', $companyId ?? 'all'],
            ['shopRef', $shopRef ?? 'all'],
            ['limit', (string) $limit],
            ['targets', (string) count($targets)],
            ['dispatchSpacingSeconds', (string) $dispatchSpacingSeconds],
        ]);

        if ([] === $targets) {
            $io->warning('No active Ozon seller shop targets found.');

            return Command::SUCCESS;
        }

        $rows = [];
        $started = 0;
        $skippedActive = 0;
        $failed = 0;

        foreach ($targets as $index => $target) {
            $initialDelaySeconds = $index * $dispatchSpacingSeconds;

            if ($dryRun) {
                $rows[] = [
                    $target['companyId'],
                    $target['connectionRef'],
                    $target['shopRef'],
                    $this->delayLabel($initialDelaySeconds),
                    'dry-run',
                ];
                continue;
            }

            try {
                $jobId = $this->syncFacade->startBackfill(new StartBackfillApplicationCommand(
                    companyId: $target['companyId'],
                    connectionRef: $target['connectionRef'],
                    source: IngestSource::OZON,
                    resourceType: OzonResourceType::ACCRUAL_BY_DAY,
                    shopRef: $target['shopRef'],
                    windowFrom: $from,
                    windowTo: $to,
                    initialDelaySeconds: $initialDelaySeconds,
                ));

                ++$started;
                $rows[] = [$target['companyId'], $target['connectionRef'], $target['shopRef'], $this->delayLabel($initialDelaySeconds), $jobId];
            } catch (ActiveBackfillExistsException) {
                ++$skippedActive;
                $rows[] = [$target['companyId'], $target['connectionRef'], $target['shopRef'], $this->delayLabel($initialDelaySeconds), 'active'];
            } catch (\Throwable $exception) {
                ++$failed;
                $rows[] = [$target['companyId'], $target['connectionRef'], $target['shopRef'], $this->delayLabel($initialDelaySeconds), 'failed'];
                $this->logger->warning('Failed to dispatch Ozon accrual rolling refresh job.', [
                    'companyId' => $target['companyId'],
                    'connectionRef' => $target['connectionRef'],
                    'shopRef' => $target['shopRef'],
                    'exceptionClass' => $exception::class,
                    'errorMessage' => $exception->getMessage(),
                ]);
            }
        }

        $io->section('Dispatch result');
        $io->table(['companyId', 'connectionRef', 'shopRef', 'delay', 'status'], $rows);
        $io->table(['metric', 'value'], [
            ['targets', (string) count($targets)],
            ['started', (string) $started],
            ['skippedActive', (string) $skippedActive],
            ['failed', (string) $failed],
        ]);

        $this->logger->info('Ozon accrual rolling refresh finished.', [
            'mode' => $dryRun ? 'dry-run' : 'execute',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'daysBack' => $daysBack,
            'companyId' => $companyId,
            'shopRef' => $shopRef,
            'targets' => count($targets),
            'started' => $started,
            'skippedActive' => $skippedActive,
            'failed' => $failed,
        ]);

        if ($dryRun) {
            $io->note('Dry-run only. No jobs were dispatched.');

            return Command::SUCCESS;
        }

        return 0 === $started && $failed > 0 ? Command::FAILURE : Command::SUCCESS;
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
                $this->logger->warning('Failed to discover Ozon accrual rolling refresh shops.', [
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

    private function delayLabel(int $initialDelaySeconds): string
    {
        return 0 === $initialDelaySeconds ? 'none' : sprintf('%ds', $initialDelaySeconds);
    }
}
