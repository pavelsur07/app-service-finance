<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonListingResolver;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\FinancialTransactionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:relink-listings',
    description: 'Relinks existing Ozon accrual ingestion transactions to Marketplace listings from stored source_data.',
)]
final class OzonAccrualRelinkListingsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly Connection $connection,
        private readonly OzonListingResolver $listingResolver,
        private readonly FinancialTransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional occurred_at start date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional occurred_at end date YYYY-MM-DD.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum transactions to inspect, 1..5000.', 500)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned relinks without writing.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Persist listing links.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Ozon accrual relink listings is already running.</comment>');

            return Command::SUCCESS;
        }

        try {
            return $this->runRelink($input, new SymfonyStyle($input, $output));
        } finally {
            $this->release();
        }
    }

    private function runRelink(InputInterface $input, SymfonyStyle $io): int
    {
        try {
            $companyId = $this->optionalUuidOption($input, 'company-id');
            $from = $this->optionalDateOption($input, 'from');
            $to = $this->optionalDateOption($input, 'to');
            $limit = $this->intOption($input, 'limit', 1, 5000);
            $execute = $this->mode($input);

            if (null !== $from && null !== $to && $from > $to) {
                throw new \InvalidArgumentException('--from must be before or equal to --to.');
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rows = $this->selectRows($companyId, $from, $to, $limit);
        $sourceDataByCompany = [];
        foreach ($rows as $row) {
            $sourceData = $this->decodeSourceData($row['source_data'] ?? null);
            if ([] === $sourceData) {
                continue;
            }

            $sourceDataByCompany[(string) $row['company_id']][(string) $row['id']] = $sourceData;
        }

        $resolutions = [];
        foreach ($sourceDataByCompany as $rowCompanyId => $sourceDataRows) {
            $resolutions += $execute
                ? $this->listingResolver->resolveMany($rowCompanyId, $sourceDataRows)
                : $this->listingResolver->resolveManyReadOnly($rowCompanyId, $sourceDataRows);
        }

        $tableRows = [];
        $resolved = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $transactionId = (string) $row['id'];
            $resolution = $resolutions[$transactionId] ?? null;
            $status = 'unresolved';

            if (null !== $resolution?->listingId && null !== $resolution->listingSku) {
                ++$resolved;
                $status = $execute ? 'updated' : 'would-update';

                if ($execute) {
                    $transaction = $this->transactionRepository->find($transactionId);
                    if ($transaction instanceof FinancialTransaction && null === $transaction->getListingId()) {
                        $transaction->setListing($resolution->listingId, $resolution->listingSku);
                        ++$updated;
                    }
                }
            }

            $tableRows[] = [
                $row['company_id'],
                $row['occurred_at'],
                $row['external_id'],
                $resolution?->listingSku ?? '',
                $resolution?->listingId ?? '',
                $status,
            ];
        }

        if ($execute) {
            $this->entityManager->flush();
        }

        $io->title('Ozon accrual listing relink');
        $io->table(
            ['setting', 'value'],
            [
                ['mode', $execute ? 'execute' : 'dry-run'],
                ['companyId', $companyId ?? 'all'],
                ['from', $from?->format('Y-m-d') ?? 'any'],
                ['to', $to?->format('Y-m-d') ?? 'any'],
                ['limit', (string) $limit],
            ],
        );

        if ([] !== $tableRows) {
            $io->section('Selected transactions');
            $io->table(['companyId', 'occurredAt', 'externalId', 'listingSku', 'listingId', 'status'], $tableRows);
        }

        $io->section('Result');
        $io->table(
            ['metric', 'value'],
            [
                ['selected', (string) count($rows)],
                ['resolved', (string) $resolved],
                ['updated', (string) $updated],
                ['unresolved', (string) (count($rows) - $resolved)],
            ],
        );

        if (!$execute) {
            $io->note('Dry-run only. Use --execute to persist listing links.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectRows(?string $companyId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit): array
    {
        $where = [
            'source = :source',
            'listing_id IS NULL',
            "(jsonb_exists(source_data, 'sku') OR jsonb_exists(source_data, 'offer_id') OR jsonb_exists(source_data, 'item_code') OR jsonb_exists(source_data, 'item') OR jsonb_exists(source_data, 'items'))",
        ];
        $params = ['source' => IngestSource::OZON->value];

        if (null !== $companyId) {
            $where[] = 'company_id = :company_id';
            $params['company_id'] = $companyId;
        }

        if (null !== $from) {
            $where[] = 'occurred_at >= :from';
            $params['from'] = $from->setTime(0, 0)->format('Y-m-d H:i:s');
        }

        if (null !== $to) {
            $where[] = 'occurred_at < :to_exclusive';
            $params['to_exclusive'] = $to->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT id, company_id, external_id, occurred_at, source_data FROM ingest_financial_transactions WHERE %s ORDER BY occurred_at ASC, id ASC LIMIT %d',
                implode(' AND ', $where),
                $limit,
            ),
            $params,
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSourceData(mixed $sourceData): array
    {
        if (is_array($sourceData)) {
            return $sourceData;
        }

        if (!is_string($sourceData) || '' === trim($sourceData)) {
            return [];
        }

        $decoded = json_decode($sourceData, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function optionalUuidOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) ($input->getOption($name) ?? ''));
        if ('' === $value) {
            return null;
        }

        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid UUID.', $name));
        }

        return $value;
    }

    private function optionalDateOption(InputInterface $input, string $name): ?\DateTimeImmutable
    {
        $value = trim((string) ($input->getOption($name) ?? ''));
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must use YYYY-MM-DD format.', $name));
        }

        return $date;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $raw = $input->getOption($name);
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException(sprintf('--%s must be numeric.', $name));
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('--%s must be between %d and %d.', $name, $min, $max));
        }

        return $value;
    }

    private function mode(InputInterface $input): bool
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $execute = (bool) $input->getOption('execute');

        if ($dryRun === $execute) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
        }

        return $execute;
    }
}
