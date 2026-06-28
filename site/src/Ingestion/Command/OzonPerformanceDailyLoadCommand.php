<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Command\StartBackfillCommand as StartBackfillApplicationCommand;
use App\Ingestion\Application\Service\OzonPerformanceLoadWindowResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Facade\SyncFacade;
use App\Marketplace\Facade\MarketplaceFacade;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-performance:daily-load',
    description: 'Dispatches daily Ozon Performance ingestion jobs for active connections.',
)]
final class OzonPerformanceDailyLoadCommand extends Command
{
    use LockableTrait;

    /**
     * @var list<string>
     */
    private const RESOURCE_TYPES = [
        OzonResourceType::PERFORMANCE_CAMPAIGNS,
        OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS,
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
        OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS,
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
        OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS,
    ];

    /**
     * @var list<string>
     */
    private const CAMPAIGN_BACKED_RESOURCE_TYPES = [
        OzonResourceType::PERFORMANCE_CAMPAIGNS,
        OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS,
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
        OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS,
        OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
    ];

    private const BACKFILL_CHUNK_SIZE_DAYS = 7;
    private const DEFAULT_DISPATCH_SPACING_SECONDS = 120;
    private const MAX_DISPATCH_SPACING_SECONDS = 3600;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly SyncFacade $syncFacade,
        private readonly OzonPerformanceLoadWindowResolver $windowResolver,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days-back', null, InputOption::VALUE_REQUIRED, 'Rewind depth in days, 1..62.', 14)
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Date window: rolling or month-to-date.', OzonPerformanceLoadWindowResolver::WINDOW_ROLLING)
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('dispatch-spacing-seconds', null, InputOption::VALUE_REQUIRED, 'Delay between campaign-backed job dispatches, 0..3600.', self::DEFAULT_DISPATCH_SPACING_SECONDS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print planned jobs without dispatching them.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Dispatch jobs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Command is already running in another process.');

            return Command::SUCCESS;
        }

        try {
            return $this->runDaily($input, $io);
        } finally {
            $this->release();
        }
    }

    private function runDaily(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $dryRun = (bool) $input->getOption('dry-run');
            $execute = (bool) $input->getOption('execute');
            if ($dryRun === $execute) {
                throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
            }

            $daysBack = $this->daysBack($input);
            $window = $this->window($input);
            $companyId = $this->companyId($input);
            $dispatchSpacingSeconds = $this->dispatchSpacingSeconds($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $loadWindow = $this->windowResolver->resolve($window, $daysBack);
        $from = $loadWindow->from;
        $to = $loadWindow->to;
        $connections = $this->marketplaceFacade->getActiveOzonPerformanceConnections($companyId);

        $io->title('Ozon Performance daily load');
        $io->table(['setting', 'value'], [
            ['mode', $dryRun ? 'dry-run' : 'execute'],
            ['window', $loadWindow->label],
            ['from', $from->format('Y-m-d')],
            ['to', $to->format('Y-m-d')],
            ['daysBack', OzonPerformanceLoadWindowResolver::WINDOW_ROLLING === $window ? (string) $daysBack : 'n/a'],
            ['companyId', $companyId ?? 'all'],
            ['connections', (string) count($connections)],
            ['dispatchSpacingSeconds', (string) $dispatchSpacingSeconds],
        ]);

        if ([] === $connections) {
            $io->warning('No active Ozon Performance connections found.');

            return Command::SUCCESS;
        }

        $rows = [];
        $started = 0;
        $skippedActive = 0;
        $failed = 0;
        /** @var array<string, int> $nextCampaignDelaySecondsByConnection */
        $nextCampaignDelaySecondsByConnection = [];
        $chunkCount = $this->chunkCount($from, $to);

        foreach ($connections as $connection) {
            $connectionCompanyId = $connection['companyId'];
            $connectionRef = $connection['connectionId'];

            foreach (self::RESOURCE_TYPES as $resourceType) {
                $campaignBacked = $this->isCampaignBackedResource($resourceType);
                $initialDelaySeconds = $campaignBacked ? ($nextCampaignDelaySecondsByConnection[$connectionRef] ?? 0) : 0;
                $chunkDelayStepSeconds = $campaignBacked ? $dispatchSpacingSeconds : 0;
                if ($campaignBacked) {
                    $nextCampaignDelaySecondsByConnection[$connectionRef] = $initialDelaySeconds + ($dispatchSpacingSeconds * $chunkCount);
                }

                if ($dryRun) {
                    $rows[] = [$connectionCompanyId, $connectionRef, $resourceType, $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), 'dry-run'];
                    continue;
                }

                try {
                    $jobId = $this->syncFacade->startBackfill(new StartBackfillApplicationCommand(
                        companyId: $connectionCompanyId,
                        connectionRef: $connectionRef,
                        source: IngestSource::OZON,
                        resourceType: $resourceType,
                        shopRef: $connectionRef,
                        windowFrom: $from,
                        windowTo: $to,
                        initialDelaySeconds: $initialDelaySeconds,
                        chunkDelayStepSeconds: $chunkDelayStepSeconds,
                    ));
                    ++$started;
                    $rows[] = [$connectionCompanyId, $connectionRef, $resourceType, $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), $jobId];
                } catch (ActiveBackfillExistsException) {
                    ++$skippedActive;
                    $rows[] = [$connectionCompanyId, $connectionRef, $resourceType, $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), 'active'];
                } catch (\Throwable $exception) {
                    ++$failed;
                    $rows[] = [$connectionCompanyId, $connectionRef, $resourceType, $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), 'failed'];
                    $this->logger->warning('Failed to dispatch Ozon Performance daily load job.', [
                        'companyId' => $connectionCompanyId,
                        'connectionRef' => $connectionRef,
                        'resourceType' => $resourceType,
                        'exceptionClass' => $exception::class,
                        'errorMessage' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $io->section('Dispatch result');
        $io->table(['companyId', 'connectionRef', 'resourceType', 'delay', 'status'], $rows);
        $io->table(['metric', 'value'], [
            ['resources', (string) (count($connections) * count(self::RESOURCE_TYPES))],
            ['started', (string) $started],
            ['skippedActive', (string) $skippedActive],
            ['failed', (string) $failed],
        ]);

        if ($dryRun) {
            $io->note('Dry-run only. No jobs were dispatched.');

            return Command::SUCCESS;
        }

        return 0 === $started && $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function daysBack(InputInterface $input): int
    {
        $value = (string) $input->getOption('days-back');
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('The --days-back option must be an integer from 1 to 62.');
        }

        $daysBack = (int) $value;
        if ($daysBack < 1 || $daysBack > 62) {
            throw new \InvalidArgumentException('The --days-back option must be an integer from 1 to 62.');
        }

        return $daysBack;
    }

    private function window(InputInterface $input): string
    {
        $window = trim((string) $input->getOption('window'));
        if (!in_array($window, OzonPerformanceLoadWindowResolver::allowedWindows(), true)) {
            throw new \InvalidArgumentException('Invalid --window. Allowed values: rolling, month-to-date.');
        }

        return $window;
    }

    private function dispatchSpacingSeconds(InputInterface $input): int
    {
        $value = (string) $input->getOption('dispatch-spacing-seconds');
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('The --dispatch-spacing-seconds option must be an integer from 0 to 3600.');
        }

        $seconds = (int) $value;
        if ($seconds < 0 || $seconds > self::MAX_DISPATCH_SPACING_SECONDS) {
            throw new \InvalidArgumentException('The --dispatch-spacing-seconds option must be an integer from 0 to 3600.');
        }

        return $seconds;
    }

    private function companyId(InputInterface $input): ?string
    {
        $companyId = trim((string) $input->getOption('company-id'));
        if ('' === $companyId) {
            return null;
        }

        Assert::uuid($companyId, 'Invalid --company-id UUID.');

        return $companyId;
    }

    private function isCampaignBackedResource(string $resourceType): bool
    {
        return in_array($resourceType, self::CAMPAIGN_BACKED_RESOURCE_TYPES, true);
    }

    private function chunkCount(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = ((int) $from->diff($to)->days) + 1;

        return max(1, (int) ceil($days / self::BACKFILL_CHUNK_SIZE_DAYS));
    }

    private function delayLabel(int $initialDelaySeconds, int $chunkDelayStepSeconds): string
    {
        if (0 === $initialDelaySeconds && 0 === $chunkDelayStepSeconds) {
            return 'none';
        }

        if ($chunkDelayStepSeconds > 0) {
            return sprintf('%ds (+%ds/chunk)', $initialDelaySeconds, $chunkDelayStepSeconds);
        }

        return sprintf('%ds', $initialDelaySeconds);
    }
}
