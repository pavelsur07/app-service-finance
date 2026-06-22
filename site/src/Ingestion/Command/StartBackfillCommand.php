<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Command\StartBackfillCommand as StartBackfillApplicationCommand;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Domain\Service\ConnectorRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Facade\SyncFacade;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:start-backfill',
    description: 'Starts ingestion backfill jobs for one company connection.',
)]
final class StartBackfillCommand extends Command
{
    /**
     * @var array<string, list<string>>
     */
    private const DEFAULT_RESOURCE_TYPES_BY_SOURCE = [
        'ozon' => [
            OzonResourceType::ACCRUAL_BY_DAY,
        ],
        'wildberries' => [
            WbResourceType::FINANCE_SALES_REPORT_DETAILED,
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_RESOURCE_TYPES_BY_SOURCE = [
        'ozon' => [
            OzonResourceType::ACCRUAL_BY_DAY,
        ],
        'wildberries' => [
            WbResourceType::FINANCE_SALES_REPORT_DETAILED,
        ],
    ];

    public function __construct(
        private readonly SyncFacade $syncFacade,
        private readonly ConnectorRegistry $connectorRegistry,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('connection-ref', null, InputOption::VALUE_REQUIRED, 'Marketplace connection UUID.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Ingestion source, for example "ozon" or "wildberries".')
            ->addOption('days-back', null, InputOption::VALUE_REQUIRED, 'Backfill depth in days, 1..365.', 30)
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional concrete shop reference.')
            ->addOption('resource-type', null, InputOption::VALUE_REQUIRED, 'Optional concrete resource type.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print planned jobs without starting them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            $connectionRef = $this->requiredUuidOption($input, 'connection-ref');
            $source = $this->source($input);
            $daysBack = $this->daysBack($input);
            $resourceTypes = $this->resourceTypes($source, $input);
            $shopRef = $this->shopRef($source, $companyId, $connectionRef, $input);
        } catch (ConnectorAuthException) {
            $io->error('Auth failed: check credentials for connection.');

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $windowTo = (new \DateTimeImmutable('today'))->modify('-1 day');
        $windowFrom = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $daysBack));

        if ((bool) $input->getOption('dry-run')) {
            $this->printDryRun($io, $companyId, $connectionRef, $source, $shopRef, $resourceTypes, $windowFrom, $windowTo);

            return Command::SUCCESS;
        }

        $started = 0;
        $failed = 0;

        foreach ($resourceTypes as $resourceType) {
            try {
                $jobId = $this->syncFacade->startBackfill(new StartBackfillApplicationCommand(
                    companyId: $companyId,
                    connectionRef: $connectionRef,
                    source: $source,
                    resourceType: $resourceType,
                    shopRef: $shopRef,
                    windowFrom: $windowFrom,
                    windowTo: $windowTo,
                ));

                ++$started;

                $io->writeln(sprintf(
                    'Started backfill: jobId=%s, resourceType=%s, window=%s->%s',
                    $jobId,
                    $resourceType,
                    $windowFrom->format('Y-m-d'),
                    $windowTo->format('Y-m-d'),
                ));
                $this->logger->info('Ingestion backfill started.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'source' => $source->value,
                    'resourceType' => $resourceType,
                    'shopRef' => $shopRef,
                    'windowFrom' => $windowFrom->format('Y-m-d'),
                    'windowTo' => $windowTo->format('Y-m-d'),
                    'jobId' => $jobId,
                ]);
            } catch (ActiveBackfillExistsException) {
                $io->warning(sprintf('Backfill already running for %s.', $resourceType));
                $this->logger->info('Ingestion backfill skipped because an active job already exists.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'source' => $source->value,
                    'resourceType' => $resourceType,
                    'shopRef' => $shopRef,
                ]);
            } catch (\Throwable $exception) {
                ++$failed;

                $io->warning(sprintf('Failed to start backfill for %s.', $resourceType));
                $this->logger->warning('Failed to start ingestion backfill.', [
                    'companyId' => $companyId,
                    'connectionRef' => $connectionRef,
                    'source' => $source->value,
                    'resourceType' => $resourceType,
                    'shopRef' => $shopRef,
                    'exceptionClass' => $exception::class,
                    'errorMessage' => $exception->getMessage(),
                ]);
            }
        }

        return 0 === $started && $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    private function source(InputInterface $input): IngestSource
    {
        $value = trim((string) $input->getOption('source'));
        Assert::notEmpty($value, 'The --source option is required.');

        $source = IngestSource::tryFrom($value);
        if (null === $source || !isset(self::ALLOWED_RESOURCE_TYPES_BY_SOURCE[$source->value])) {
            throw new \InvalidArgumentException(sprintf('Unsupported ingestion source "%s".', $value));
        }

        return $source;
    }

    private function daysBack(InputInterface $input): int
    {
        $value = (string) $input->getOption('days-back');
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('The --days-back option must be an integer from 1 to 365.');
        }

        $daysBack = (int) $value;
        if ($daysBack < 1 || $daysBack > 365) {
            throw new \InvalidArgumentException('The --days-back option must be an integer from 1 to 365.');
        }

        return $daysBack;
    }

    /**
     * @return list<string>
     */
    private function resourceTypes(IngestSource $source, InputInterface $input): array
    {
        $defaultResourceTypes = self::DEFAULT_RESOURCE_TYPES_BY_SOURCE[$source->value];
        $allowedResourceTypes = self::ALLOWED_RESOURCE_TYPES_BY_SOURCE[$source->value];
        $requested = trim((string) $input->getOption('resource-type'));

        if ('' === $requested) {
            return $defaultResourceTypes;
        }

        if (!in_array($requested, $allowedResourceTypes, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported resource type "%s" for source "%s".', $requested, $source->value));
        }

        return [$requested];
    }

    private function shopRef(IngestSource $source, string $companyId, string $connectionRef, InputInterface $input): string
    {
        $shopRef = trim((string) $input->getOption('shop-ref'));
        if ('' !== $shopRef) {
            return $shopRef;
        }

        $shops = $this->connectorRegistry->get($source)->discoverShops($companyId, $connectionRef);
        if ([] === $shops) {
            throw new \RuntimeException('No shops found for connection.');
        }

        return $shops[0]->externalId;
    }

    /**
     * @param list<string> $resourceTypes
     */
    private function printDryRun(
        SymfonyStyle $io,
        string $companyId,
        string $connectionRef,
        IngestSource $source,
        string $shopRef,
        array $resourceTypes,
        \DateTimeImmutable $windowFrom,
        \DateTimeImmutable $windowTo,
    ): void {
        $rows = [];
        foreach ($resourceTypes as $resourceType) {
            $rows[] = [
                $companyId,
                $connectionRef,
                $source->value,
                $resourceType,
                $shopRef,
                $windowFrom->format('Y-m-d'),
                $windowTo->format('Y-m-d'),
            ];
        }

        $io->title('DRY-RUN ingestion backfill');
        $io->table(
            ['companyId', 'connectionRef', 'source', 'resourceType', 'shopRef', 'windowFrom', 'windowTo'],
            $rows,
        );
    }
}
