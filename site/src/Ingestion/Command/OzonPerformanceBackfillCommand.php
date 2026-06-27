<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Command\StartBackfillCommand as StartBackfillApplicationCommand;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Facade\SyncFacade;
use App\Marketplace\Facade\MarketplaceFacade;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-performance:backfill',
    description: 'Dispatches Ozon Performance ingestion backfill jobs for one company.',
)]
final class OzonPerformanceBackfillCommand extends Command
{
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
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date, YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date, YYYY-MM-DD.')
            ->addOption('dispatch-spacing-seconds', null, InputOption::VALUE_REQUIRED, 'Delay between campaign-backed job dispatches, 0..3600.', self::DEFAULT_DISPATCH_SPACING_SECONDS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print planned jobs without dispatching them.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Dispatch jobs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $dryRun = (bool) $input->getOption('dry-run');
            $execute = (bool) $input->getOption('execute');
            if ($dryRun === $execute) {
                throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
            }

            $companyId = $this->companyId($input);
            $from = $this->dateOption($input, 'from');
            $to = $this->dateOption($input, 'to');
            Assert::lessThanEq($from, $to, 'The --from date must be less than or equal to --to.');
            $dispatchSpacingSeconds = $this->dispatchSpacingSeconds($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $connections = $this->marketplaceFacade->getActiveOzonPerformanceConnections($companyId);
        if ([] === $connections) {
            $io->warning('No active Ozon Performance connections found for company.');

            return Command::SUCCESS;
        }

        return $this->dispatch($io, $connections, $from, $to, $dryRun, $dispatchSpacingSeconds);
    }

    /**
     * @param list<array{connectionId: string, companyId: string, marketplace: string, connectionType: string, clientId: ?string}> $connections
     */
    private function dispatch(SymfonyStyle $io, array $connections, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $dryRun, int $dispatchSpacingSeconds): int
    {
        $rows = [];
        $started = 0;
        $skippedActive = 0;
        $failed = 0;
        $nextCampaignDelaySeconds = 0;
        $chunkCount = $this->chunkCount($from, $to);

        foreach ($connections as $connection) {
            $companyId = $connection['companyId'];
            $connectionRef = $connection['connectionId'];

            foreach (self::RESOURCE_TYPES as $resourceType) {
                $campaignBacked = $this->isCampaignBackedResource($resourceType);
                $initialDelaySeconds = $campaignBacked ? $nextCampaignDelaySeconds : 0;
                $chunkDelayStepSeconds = $campaignBacked ? $dispatchSpacingSeconds : 0;
                if ($campaignBacked) {
                    $nextCampaignDelaySeconds += $dispatchSpacingSeconds * $chunkCount;
                }

                if ($dryRun) {
                    $rows[] = [$companyId, $connectionRef, $resourceType, $from->format('Y-m-d'), $to->format('Y-m-d'), $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), 'dry-run'];
                    continue;
                }

                try {
                    $jobId = $this->syncFacade->startBackfill(new StartBackfillApplicationCommand(
                        companyId: $companyId,
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
                    $rows[] = [$companyId, $connectionRef, $resourceType, $from->format('Y-m-d'), $to->format('Y-m-d'), $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), $jobId];
                } catch (ActiveBackfillExistsException) {
                    ++$skippedActive;
                    $rows[] = [$companyId, $connectionRef, $resourceType, $from->format('Y-m-d'), $to->format('Y-m-d'), $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), 'active'];
                } catch (\Throwable $exception) {
                    ++$failed;
                    $rows[] = [$companyId, $connectionRef, $resourceType, $from->format('Y-m-d'), $to->format('Y-m-d'), $this->delayLabel($initialDelaySeconds, $chunkDelayStepSeconds), 'failed'];
                    $this->logger->warning('Failed to dispatch Ozon Performance backfill.', [
                        'companyId' => $companyId,
                        'connectionRef' => $connectionRef,
                        'resourceType' => $resourceType,
                        'exceptionClass' => $exception::class,
                        'errorMessage' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $io->title('Ozon Performance backfill');
        $io->table(['companyId', 'connectionRef', 'resourceType', 'from', 'to', 'delay', 'status'], $rows);
        $io->table([
            'metric',
            'value',
        ], [
            ['connections', (string) count($connections)],
            ['resources', (string) (count($connections) * count(self::RESOURCE_TYPES))],
            ['started', (string) $started],
            ['skippedActive', (string) $skippedActive],
            ['failed', (string) $failed],
            ['dispatchSpacingSeconds', (string) $dispatchSpacingSeconds],
        ]);

        if ($dryRun) {
            $io->note('Dry-run only. No jobs were dispatched.');

            return Command::SUCCESS;
        }

        return 0 === $started && $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function companyId(InputInterface $input): string
    {
        $companyId = trim((string) $input->getOption('company-id'));
        Assert::uuid($companyId, 'Invalid --company-id UUID.');

        return $companyId;
    }

    private function dateOption(InputInterface $input, string $name): \DateTimeImmutable
    {
        $value = trim((string) $input->getOption($name));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('Invalid --%s date. Expected YYYY-MM-DD.', $name));
        }

        return $date;
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
