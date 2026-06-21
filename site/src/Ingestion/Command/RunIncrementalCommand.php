<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Command\StartIncrementalCommand as StartIncrementalApplicationCommand;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Facade\SyncFacade;
use App\Ingestion\Repository\IngestCursorRepository;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:run-incremental',
    description: 'Dispatches ingestion incremental jobs for active seller connections with cursors.',
)]
final class RunIncrementalCommand extends Command
{
    use LockableTrait;

    /**
     * @var list<string>
     */
    private const OZON_RESOURCE_TYPES = [
        OzonResourceType::ACCRUAL_BY_DAY,
    ];

    /**
     * @var list<string>
     */
    private const LEGACY_OZON_RESOURCE_TYPES = [
        'ozon_seller_daily_report',
        'ozon_seller_realization',
    ];

    public function __construct(
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly IngestCursorRepository $cursorRepository,
        private readonly SyncFacade $syncFacade,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Optional source filter. Only "ozon" is supported now.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum companies per tick.', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Command is already running in another process.');

            return Command::SUCCESS;
        }

        try {
            return $this->runIncremental($input, $io);
        } finally {
            $this->release();
        }
    }

    private function runIncremental(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $source = $this->source($input);
            $companyId = $this->companyId($input);
            $limit = $this->limit($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (IngestSource::OZON !== $source) {
            $io->warning(sprintf('Incremental ingestion for "%s" is not supported yet.', $source->value));
            $this->logger->info('Ingestion incremental skipped because source is not supported yet.', [
                'source' => $source->value,
                'companyId' => $companyId,
            ]);

            return Command::SUCCESS;
        }

        $connections = $this->connectionsQuery->execute();
        $eligibleWork = [];
        $dispatched = 0;
        $skippedWithoutCursor = 0;
        $skippedNotDue = 0;
        $skippedActive = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            if (IngestSource::OZON->value !== (string) $connection['marketplace']) {
                continue;
            }

            $connectionCompanyId = (string) $connection['company_id'];
            if (null !== $companyId && $connectionCompanyId !== $companyId) {
                continue;
            }

            $connectionRef = (string) $connection['id'];
            $this->ensureAccrualCursor($connectionCompanyId, $connectionRef);

            foreach (self::OZON_RESOURCE_TYPES as $resourceType) {
                $cursors = $this->cursorRepository->findByResource($connectionCompanyId, $connectionRef, $resourceType);
                if ([] === $cursors) {
                    ++$skippedWithoutCursor;
                    continue;
                }

                foreach ($cursors as $cursor) {
                    if ('' === $cursor->getCursorValue()) {
                        ++$skippedWithoutCursor;
                        continue;
                    }

                    if (!$this->cursorHasDueWork($resourceType, $cursor->getCursorValue())) {
                        ++$skippedNotDue;
                        continue;
                    }

                    $sortAt = $cursor->getLastFetchedAt() ?? $cursor->getUpdatedAt();
                    $eligibleWork[$connectionCompanyId] ??= [
                        'companyId' => $connectionCompanyId,
                        'sortAt' => $sortAt,
                        'jobs' => [],
                    ];
                    if ($sortAt < $eligibleWork[$connectionCompanyId]['sortAt']) {
                        $eligibleWork[$connectionCompanyId]['sortAt'] = $sortAt;
                    }
                    $eligibleWork[$connectionCompanyId]['jobs'][] = [
                        'connectionRef' => $connectionRef,
                        'resourceType' => $resourceType,
                        'shopRef' => $cursor->getShopRef(),
                    ];
                }
            }
        }

        $eligibleWork = array_values($eligibleWork);
        usort(
            $eligibleWork,
            static fn (array $left, array $right): int => $left['sortAt'] <=> $right['sortAt']
                ?: $left['companyId'] <=> $right['companyId'],
        );

        foreach (array_slice($eligibleWork, 0, $limit) as $companyWork) {
            $connectionCompanyId = $companyWork['companyId'];

            foreach ($companyWork['jobs'] as $job) {
                try {
                    $this->syncFacade->startIncremental(new StartIncrementalApplicationCommand(
                        companyId: $connectionCompanyId,
                        connectionRef: $job['connectionRef'],
                        source: IngestSource::OZON,
                        resourceType: $job['resourceType'],
                        shopRef: $job['shopRef'],
                    ));

                    ++$dispatched;
                } catch (ActiveBackfillExistsException) {
                    ++$skippedActive;
                    $io->warning(sprintf(
                        'Incremental already running for companyId=%s resourceType=%s shopRef=%s.',
                        $connectionCompanyId,
                        $job['resourceType'],
                        $job['shopRef'],
                    ));
                } catch (\Throwable $exception) {
                    ++$failed;
                    $this->logger->warning('Failed to dispatch ingestion incremental job.', [
                        'companyId' => $connectionCompanyId,
                        'connectionRef' => $job['connectionRef'],
                        'source' => IngestSource::OZON->value,
                        'resourceType' => $job['resourceType'],
                        'shopRef' => $job['shopRef'],
                        'exceptionClass' => $exception::class,
                        'errorMessage' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info('Dispatched ingestion incremental jobs.', [
            'dispatched' => $dispatched,
            'failed' => $failed,
            'skippedWithoutCursor' => $skippedWithoutCursor,
            'skippedNotDue' => $skippedNotDue,
            'skippedActive' => $skippedActive,
            'companyLimit' => $limit,
            'companyId' => $companyId,
            'source' => $source->value,
        ]);

        $io->success(sprintf(
            'Dispatched %d incremental jobs (skipped without cursor: %d, not due: %d, active: %d, failed: %d).',
            $dispatched,
            $skippedWithoutCursor,
            $skippedNotDue,
            $skippedActive,
            $failed,
        ));

        return 0 === $dispatched && $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function source(InputInterface $input): IngestSource
    {
        $value = trim((string) $input->getOption('source'));
        if ('' === $value) {
            return IngestSource::OZON;
        }

        $source = IngestSource::tryFrom($value);
        if (null === $source) {
            throw new \InvalidArgumentException(sprintf('Unsupported ingestion source "%s".', $value));
        }

        return $source;
    }

    private function companyId(InputInterface $input): ?string
    {
        $value = trim((string) $input->getOption('company-id'));
        if ('' === $value) {
            return null;
        }

        Assert::uuid($value, 'Invalid --company-id UUID.');

        return $value;
    }

    private function limit(InputInterface $input): int
    {
        $value = (string) $input->getOption('limit');
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('The --limit option must be an integer from 1 to 500.');
        }

        $limit = (int) $value;
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('The --limit option must be an integer from 1 to 500.');
        }

        return $limit;
    }

    private function ensureAccrualCursor(string $companyId, string $connectionRef): void
    {
        if ([] !== $this->cursorRepository->findByResource($companyId, $connectionRef, OzonResourceType::ACCRUAL_BY_DAY)) {
            return;
        }

        $seedValue = $this->legacySeedCursorValue($companyId, $connectionRef)
            ?? (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');

        $cursor = $this->cursorRepository->getOrCreate(
            $companyId,
            $connectionRef,
            OzonResourceType::ACCRUAL_BY_DAY,
            $connectionRef,
        );
        $cursor->advance($seedValue, Uuid::uuid7()->toString());
        $this->entityManager->flush();
    }

    private function legacySeedCursorValue(string $companyId, string $connectionRef): ?string
    {
        $seed = null;
        foreach (self::LEGACY_OZON_RESOURCE_TYPES as $resourceType) {
            foreach ($this->cursorRepository->findByResource($companyId, $connectionRef, $resourceType) as $cursor) {
                $cursorValue = $this->normalizedCursorDate($cursor->getCursorValue());
                if (null === $cursorValue) {
                    continue;
                }

                if (null === $seed || $cursorValue < $seed) {
                    $seed = $cursorValue;
                }
            }
        }

        return $seed;
    }

    private function cursorHasDueWork(string $resourceType, string $cursorValue): bool
    {
        if (OzonResourceType::ACCRUAL_BY_DAY !== $resourceType) {
            return true;
        }

        $cursorDate = $this->normalizedCursorDate($cursorValue);
        if (null === $cursorDate) {
            return true;
        }

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->setTime(0, 0);

        return new \DateTimeImmutable($cursorDate) <= $yesterday;
    }

    private function normalizedCursorDate(string $cursorValue): ?string
    {
        try {
            return (new \DateTimeImmutable($cursorValue))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
