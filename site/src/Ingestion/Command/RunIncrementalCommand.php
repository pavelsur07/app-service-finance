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
        OzonResourceType::DAILY_REPORT,
        OzonResourceType::REALIZATION,
    ];

    public function __construct(
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly IngestCursorRepository $cursorRepository,
        private readonly SyncFacade $syncFacade,
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
        $processedCompanies = [];
        $dispatched = 0;
        $skippedWithoutCursor = 0;
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

            if (!isset($processedCompanies[$connectionCompanyId])) {
                if (count($processedCompanies) >= $limit) {
                    break;
                }

                $processedCompanies[$connectionCompanyId] = true;
            }

            $connectionRef = (string) $connection['id'];

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

                    try {
                        $this->syncFacade->startIncremental(new StartIncrementalApplicationCommand(
                            companyId: $connectionCompanyId,
                            connectionRef: $connectionRef,
                            source: IngestSource::OZON,
                            resourceType: $resourceType,
                            shopRef: $cursor->getShopRef(),
                        ));

                        ++$dispatched;
                    } catch (ActiveBackfillExistsException) {
                        ++$skippedActive;
                        $io->warning(sprintf(
                            'Incremental already running for companyId=%s resourceType=%s shopRef=%s.',
                            $connectionCompanyId,
                            $resourceType,
                            $cursor->getShopRef(),
                        ));
                    } catch (\Throwable $exception) {
                        ++$failed;
                        $this->logger->warning('Failed to dispatch ingestion incremental job.', [
                            'companyId' => $connectionCompanyId,
                            'connectionRef' => $connectionRef,
                            'source' => IngestSource::OZON->value,
                            'resourceType' => $resourceType,
                            'shopRef' => $cursor->getShopRef(),
                            'exceptionClass' => $exception::class,
                            'errorMessage' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        }

        $this->logger->info('Dispatched ingestion incremental jobs.', [
            'dispatched' => $dispatched,
            'failed' => $failed,
            'skippedWithoutCursor' => $skippedWithoutCursor,
            'skippedActive' => $skippedActive,
            'companyLimit' => $limit,
            'companyId' => $companyId,
            'source' => $source->value,
        ]);

        $io->success(sprintf(
            'Dispatched %d incremental jobs (skipped without cursor: %d, active: %d, failed: %d).',
            $dispatched,
            $skippedWithoutCursor,
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
}
