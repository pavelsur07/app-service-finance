<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\DiscoverExternalCategoriesAction;
use App\Ingestion\Application\Action\RebuildMarketplaceCategoryIdentitiesAction;
use App\Ingestion\Application\Action\RefreshOzonAccrualCategoryMetadataAction;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:refresh-category-metadata-all',
    description: 'Refreshes Ozon accrual category metadata for all stored by-day raw records in one run.',
)]
final class OzonAccrualRefreshCategoryMetadataAllCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RefreshOzonAccrualCategoryMetadataAction $refreshMetadata,
        private readonly DiscoverExternalCategoriesAction $discoverCategories,
        private readonly RebuildMarketplaceCategoryIdentitiesAction $rebuildIdentities,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional start accrual date YYYY-MM-DD.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional end accrual date YYYY-MM-DD.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.')
            ->addOption('shop-ref', null, InputOption::VALUE_REQUIRED, 'Optional shop reference filter.')
            ->addOption('limit-per-shop', null, InputOption::VALUE_REQUIRED, 'Raw records to process per company/shop, 1..500.', 500)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show selected targets and planned metadata updates without writing.')
            ->addOption('execute-inline', null, InputOption::VALUE_NONE, 'Refresh metadata synchronously in this process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $from = $this->optionalDateOption($input, 'from');
            $to = $this->optionalDateOption($input, 'to');
            if (null !== $from && null !== $to && $from > $to) {
                throw new \InvalidArgumentException('--from cannot be later than --to.');
            }

            $companyId = $this->optionalUuidOption($input, 'company-id');
            $shopRef = $this->optionalStringOption($input, 'shop-ref');
            $limitPerShop = $this->intOption($input, 'limit-per-shop', 1, 500);
            $mode = $this->mode($input);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $dryRun = 'dry-run' === $mode;
        $targets = $this->targets($from, $to, $companyId, $shopRef);

        $io->title('Ozon accrual category metadata bulk refresh');
        $this->printTargets($io, $targets);

        if ([] === $targets) {
            return Command::SUCCESS;
        }

        $totals = [
            'targets' => count($targets),
            'rawRecords' => 0,
            'scanned' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing' => 0,
            'failedRawRecords' => 0,
            'rawRecordPages' => 0,
        ];

        foreach ($targets as $target) {
            $windowFrom = new \DateTimeImmutable((string) $target['window_from']);
            $windowTo = new \DateTimeImmutable((string) $target['window_to']);
            $offset = 0;

            do {
                $rawRecords = $this->refreshMetadata->rawRecords(
                    companyId: (string) $target['company_id'],
                    from: $from ?? $windowFrom,
                    to: $to ?? $windowTo,
                    shopRef: (string) $target['shop_ref'],
                    limit: $limitPerShop,
                    offset: $offset,
                );

                if ([] === $rawRecords) {
                    break;
                }

                ++$totals['rawRecordPages'];
                $totals['rawRecords'] += count($rawRecords);

                $resultRows = [];
                foreach ($rawRecords as $rawRecord) {
                    $resultRows = array_merge(
                        $resultRows,
                        $this->refreshRawRecordInSubprocess(
                            companyId: (string) $target['company_id'],
                            rawRecordId: (string) $rawRecord['id'],
                            dryRun: $dryRun,
                        ),
                    );
                    $this->releaseMemory();
                }

                foreach ($resultRows as $row) {
                    $totals['scanned'] += (int) $row['scanned'];
                    $totals['matched'] += (int) $row['matched'];
                    $totals['updated'] += (int) $row['updated'];
                    $totals['unchanged'] += (int) $row['unchanged'];
                    $totals['missing'] += (int) $row['missing'];
                    if ('error' === $row['status']) {
                        ++$totals['failedRawRecords'];
                    }
                }

                $offset += count($rawRecords);
            } while (count($rawRecords) === $limitPerShop);
        }

        $io->section('Bulk metadata refresh result');
        $io->table(
            ['metric', 'value'],
            array_map(
                static fn (string $metric, int $value): array => [$metric, (string) $value],
                array_keys($totals),
                array_values($totals),
            ),
        );

        if ($totals['failedRawRecords'] > 0) {
            $io->warning(sprintf('Metadata refresh finished with %d failed raw records.', $totals['failedRawRecords']));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Dry-run only. No canonical transactions or taxonomy rows were changed.');

            return Command::SUCCESS;
        }

        $discover = ($this->discoverCategories)(IngestSource::OZON, 5000);
        $rebuild = $this->rebuildIdentities->rebuild(IngestSource::OZON, execute: true);

        $io->section('Taxonomy follow-up');
        $io->table(
            ['action', 'metric', 'value'],
            array_merge(
                $this->metricRows('discover', $discover),
                $this->metricRows('rebuild-identities', $rebuild),
            ),
        );

        $io->success(sprintf('Refreshed Ozon category metadata on %d canonical transactions.', $totals['updated']));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function targets(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?string $companyId,
        ?string $shopRef,
    ): array {
        $externalWindowFrom = "substring(r.external_id from '^accrual-by-day:([0-9]{4}-[0-9]{2}-[0-9]{2}):[0-9]{4}-[0-9]{2}-[0-9]{2}$')::date";
        $externalWindowTo = "substring(r.external_id from '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:([0-9]{4}-[0-9]{2}-[0-9]{2})$')::date";
        $windowFrom = sprintf('COALESCE(j.window_from, %s, DATE(r.fetched_at))', $externalWindowFrom);
        $windowTo = sprintf('COALESCE(j.window_to, j.window_from, %s, %s, DATE(r.fetched_at))', $externalWindowTo, $externalWindowFrom);
        $conditions = [
            'r.source = :source',
            'r.resource_type = :resourceType',
            'r.normalization_status = :status',
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'status' => RawNormalizationStatus::DONE->value,
        ];

        if (null !== $from) {
            $conditions[] = sprintf('%s >= :fromDate', $windowTo);
            $params['fromDate'] = $from->format('Y-m-d');
        }

        if (null !== $to) {
            $conditions[] = sprintf('%s <= :toDate', $windowFrom);
            $params['toDate'] = $to->format('Y-m-d');
        }

        if (null !== $companyId) {
            $conditions[] = 'r.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        if (null !== $shopRef) {
            $conditions[] = 'r.shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT r.company_id,
                        r.shop_ref,
                        TO_CHAR(MIN(%s), \'YYYY-MM-DD\') AS window_from,
                        TO_CHAR(MAX(%s), \'YYYY-MM-DD\') AS window_to,
                        COUNT(*) AS raw_count
                 FROM ingest_raw_records r
                 LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                 WHERE %s
                 GROUP BY r.company_id, r.shop_ref
                 ORDER BY r.company_id ASC, r.shop_ref ASC',
                $windowFrom,
                $windowTo,
                implode(' AND ', $conditions),
            ),
            $params,
        );
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    private function printTargets(SymfonyStyle $io, array $targets): void
    {
        $io->section('Selected company/shop targets');
        if ([] === $targets) {
            $io->writeln('No done Ozon accrual by-day raw records found for the selected filters.');

            return;
        }

        $io->table(
            ['companyId', 'shopRef', 'windowFrom', 'windowTo', 'rawRecords'],
            array_map(static fn (array $target): array => [
                (string) $target['company_id'],
                (string) $target['shop_ref'],
                (string) $target['window_from'],
                (string) $target['window_to'],
                (string) $target['raw_count'],
            ], $targets),
        );
    }

    /**
     * @param array<string, int> $metrics
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function metricRows(string $action, array $metrics): array
    {
        return array_map(
            static fn (string $metric, int $value): array => [$action, $metric, (string) $value],
            array_keys($metrics),
            array_values($metrics),
        );
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function refreshRawRecordInSubprocess(string $companyId, string $rawRecordId, bool $dryRun): array
    {
        $process = new Process([
            PHP_BINARY,
            '-d',
            sprintf('memory_limit=%s', $this->memoryLimit()),
            $this->consolePath(),
            'app:ingestion:ozon-accrual:refresh-category-metadata',
            sprintf('--company-id=%s', $companyId),
            sprintf('--raw-id=%s', $rawRecordId),
            $dryRun ? '--dry-run' : '--execute-inline',
            '--json-result',
            '--no-interaction',
        ]);
        $process->setTimeout(null);
        $process->run();

        $rows = $this->decodeResultRows($process->getOutput());
        if ([] !== $rows) {
            return $rows;
        }

        if ($process->isSuccessful()) {
            return [];
        }

        $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: sprintf('Subprocess exited with code %s.', (string) $process->getExitCode());

        return [[
            'rawId' => $rawRecordId,
            'status' => 'error',
            'scanned' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing' => 0,
            'error' => $error,
        ]];
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function decodeResultRows(string $output): array
    {
        $output = trim($output);
        if ('' === $output) {
            return [];
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $row): bool => is_array($row)));
    }

    private function consolePath(): string
    {
        return dirname(__DIR__, 3) . '/bin/console';
    }

    private function memoryLimit(): string
    {
        $memoryLimit = ini_get('memory_limit');

        return false === $memoryLimit || '' === $memoryLimit ? '1G' : $memoryLimit;
    }

    private function releaseMemory(): void
    {
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }

    private function optionalUuidOption(InputInterface $input, string $name): ?string
    {
        $value = $this->optionalStringOption($input, $name);
        if (null === $value) {
            return null;
        }

        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    private function optionalDateOption(InputInterface $input, string $name): ?\DateTimeImmutable
    {
        $value = $this->optionalStringOption($input, $name);
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
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

        $number = (int) $value;

        return max($min, min($max, $number));
    }

    private function mode(InputInterface $input): string
    {
        $modes = array_values(array_filter([
            (bool) $input->getOption('dry-run') ? 'dry-run' : null,
            (bool) $input->getOption('execute-inline') ? 'execute-inline' : null,
        ]));

        if (1 !== count($modes)) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute-inline.');
        }

        return $modes[0];
    }
}
