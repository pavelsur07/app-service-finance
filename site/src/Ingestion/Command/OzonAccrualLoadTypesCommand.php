<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Command\MarkJobFailedCommand;
use App\Ingestion\Application\Command\StartIncrementalCommand as StartIncrementalApplicationCommand;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Facade\SyncFacade;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\MessageHandler\NormalizeRawRecordHandler;
use App\Ingestion\MessageHandler\RunSyncChunkHandler;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:load-types',
    description: 'Loads the Ozon accrual types dictionary into raw storage.',
)]
final class OzonAccrualLoadTypesCommand extends Command
{
    public function __construct(
        private readonly SyncFacade $syncFacade,
        private readonly SyncJobRepository $syncJobRepository,
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly RunSyncChunkHandler $runSyncChunkHandler,
        private readonly NormalizeRawRecordHandler $normalizeRawRecordHandler,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('connection-ref', null, InputOption::VALUE_REQUIRED, 'Marketplace connection UUID.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference. Defaults to connection-ref.')
            ->addOption('execute-inline', null, InputOption::VALUE_NONE, 'Fetch and store the dictionary synchronously in this process.')
            ->addOption('sample-limit', null, InputOption::VALUE_REQUIRED, 'Dictionary rows to print after inline execution, 1..500.', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            $connectionRef = $this->requiredUuidOption($input, 'connection-ref');
            $shopRef = $this->shopRef($input, $connectionRef);
            $sampleLimit = $this->intOption($input, 'sample-limit', 1, 500);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Ozon accrual types load');

        try {
            if ((bool) $input->getOption('execute-inline')) {
                return $this->executeInline($io, $companyId, $connectionRef, $shopRef, $sampleLimit);
            }

            $jobId = $this->syncFacade->startIncremental(new StartIncrementalApplicationCommand(
                companyId: $companyId,
                connectionRef: $connectionRef,
                source: IngestSource::OZON,
                resourceType: OzonResourceType::ACCRUAL_TYPES,
                shopRef: $shopRef,
            ));
        } catch (ActiveBackfillExistsException) {
            $io->warning(sprintf('Ozon accrual types load already running for companyId=%s shopRef=%s.', $companyId, $shopRef));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['jobId', 'resourceType', 'shopRef', 'mode'],
            [[$jobId, OzonResourceType::ACCRUAL_TYPES, $shopRef, 'dispatched']],
        );
        $io->success('Ozon accrual types load job dispatched.');

        return Command::SUCCESS;
    }

    private function executeInline(
        SymfonyStyle $io,
        string $companyId,
        string $connectionRef,
        string $shopRef,
        int $sampleLimit,
    ): int {
        $jobId = $this->startInlineJob($companyId, $connectionRef, $shopRef);
        $io->writeln(sprintf('Started inline load: jobId=%s, resourceType=%s', $jobId, OzonResourceType::ACCRUAL_TYPES));

        try {
            ($this->runSyncChunkHandler)(new RunSyncChunkMessage($companyId, $jobId));
            $this->entityManager->clear();
            $this->normalizeLatestRawRecord($companyId);
            $this->entityManager->clear();

            $this->printLatestRawRecord($io, $companyId, $sampleLimit);

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->entityManager->clear();
            $this->failInlineJobIfActive($companyId, $jobId, $exception);

            throw $exception;
        }
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    private function shopRef(InputInterface $input, string $connectionRef): string
    {
        $value = trim((string) $input->getOption('shop-ref'));

        return '' !== $value ? $value : $connectionRef;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (string) $input->getOption($name);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        return $number;
    }

    private function startInlineJob(string $companyId, string $connectionRef, string $shopRef): string
    {
        $activeJob = $this->syncJobRepository->findLatestForResource(
            $companyId,
            $connectionRef,
            OzonResourceType::ACCRUAL_TYPES,
            $shopRef,
        );
        if (null !== $activeJob) {
            throw new ActiveBackfillExistsException('Sync job for requested resource is already active.');
        }

        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_TYPES,
            kind: SyncJobKind::INCREMENTAL,
            shopRef: $shopRef,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job->getId();
    }

    private function failInlineJobIfActive(string $companyId, string $jobId, \Throwable $exception): void
    {
        $job = $this->syncJobRepository->findByIdAndCompany($jobId, $companyId);
        if (null === $job || $job->getStatus()->isTerminal()) {
            return;
        }

        $reason = sprintf('inline load failed: %s', $exception->getMessage());
        $this->syncFacade->markJobFailed(new MarkJobFailedCommand($jobId, $companyId, mb_substr($reason, 0, 2000)));
    }

    private function printLatestRawRecord(SymfonyStyle $io, string $companyId, int $sampleLimit): void
    {
        $rawRecord = $this->latestRawRecord($companyId);

        if (!$rawRecord instanceof IngestRawRecord) {
            $io->warning('No accrual types raw record was stored.');

            return;
        }

        $rows = $this->dictionaryRows($rawRecord, $sampleLimit);

        $io->section('Stored raw record');
        $io->table(
            ['rawId', 'externalId', 'status', 'bytes', 'fetchedAt', 'printedRows'],
            [[
                $rawRecord->getId(),
                $rawRecord->getExternalId(),
                $rawRecord->getNormalizationStatus()->value,
                (string) $rawRecord->getByteSize(),
                $rawRecord->getFetchedAt()->format('Y-m-d H:i:s'),
                (string) count($rows),
            ]],
        );

        if ([] === $rows) {
            $io->note('Stored dictionary contains no printable type rows.');

            return;
        }

        $io->section('Accrual type dictionary sample');
        $io->table(['typeId', 'name'], $rows);
    }

    private function normalizeLatestRawRecord(string $companyId): void
    {
        $rawRecord = $this->latestRawRecord($companyId);
        if (!$rawRecord instanceof IngestRawRecord) {
            return;
        }

        ($this->normalizeRawRecordHandler)(new NormalizeRawRecordMessage($rawRecord->getId(), $companyId));
    }

    private function latestRawRecord(string $companyId): ?IngestRawRecord
    {
        return $this->rawRecordRepository->findLatestByCompanySourceExternalId(
            $companyId,
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_TYPES,
            'accrual-types',
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function dictionaryRows(IngestRawRecord $rawRecord, int $sampleLimit): array
    {
        $rows = [];
        foreach ($this->rawStorageFacade->read($rawRecord->getId(), $rawRecord->getCompanyId()) as $row) {
            if (!is_array($row) || ($row['_ingestion_empty'] ?? false) === true) {
                continue;
            }

            $typeId = $this->stringValue($row['type_id'] ?? $row['typeId'] ?? $row['id'] ?? null);
            $name = $this->stringValue($row['name'] ?? $row['title'] ?? $row['type_name'] ?? $row['typeName'] ?? null);
            if (null === $typeId || null === $name) {
                continue;
            }

            $rows[] = [$typeId, $name];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftId = ctype_digit($left[0]) ? (int) $left[0] : \PHP_INT_MAX;
            $rightId = ctype_digit($right[0]) ? (int) $right[0] : \PHP_INT_MAX;

            return $leftId <=> $rightId ?: $left[0] <=> $right[0];
        });

        return array_slice($rows, 0, $sampleLimit);
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value) && null !== $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }
}
