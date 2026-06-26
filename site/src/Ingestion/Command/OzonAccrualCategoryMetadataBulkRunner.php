<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\RefreshOzonAccrualCategoryMetadataAction;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final readonly class OzonAccrualCategoryMetadataBulkRunner implements OzonAccrualCategoryMetadataBulkRunnerInterface
{
    public function __construct(
        private Connection $connection,
        private RefreshOzonAccrualCategoryMetadataAction $refreshMetadata,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function targets(
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
     *
     * @return array{
     *     totals: array<string, int>,
     *     failedRawRecords: list<array<string, string>>,
     *     failedTargets: list<array<string, string>>
     * }
     */
    public function refreshTargets(
        array $targets,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limitPerShop,
        bool $dryRun,
    ): array {
        $totals = [
            'targets' => count($targets),
            'rawRecords' => 0,
            'scanned' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing' => 0,
            'failedRawRecords' => 0,
            'failedTargets' => 0,
            'rawRecordPages' => 0,
        ];
        $failedRawRecords = [];
        $failedTargets = [];

        foreach ($targets as $target) {
            $targetCompanyId = (string) $target['company_id'];
            $targetShopRef = (string) $target['shop_ref'];
            $windowFrom = new \DateTimeImmutable((string) $target['window_from']);
            $windowTo = new \DateTimeImmutable((string) $target['window_to']);
            $offset = 0;

            do {
                try {
                    $rawRecords = $this->refreshMetadata->rawRecords(
                        companyId: $targetCompanyId,
                        from: $from ?? $windowFrom,
                        to: $to ?? $windowTo,
                        shopRef: $targetShopRef,
                        limit: $limitPerShop,
                        offset: $offset,
                    );
                } catch (\Throwable $exception) {
                    ++$totals['failedTargets'];
                    $failedTargets[] = [
                        'companyId' => $targetCompanyId,
                        'shopRef' => $targetShopRef,
                        'error' => $exception->getMessage(),
                    ];
                    $this->logger->error('Ozon accrual category metadata target page failed.', [
                        'exception' => $exception,
                        'company_id' => $targetCompanyId,
                        'shop_ref' => $targetShopRef,
                        'offset' => $offset,
                        'limit' => $limitPerShop,
                    ]);

                    break;
                }

                if ([] === $rawRecords) {
                    break;
                }

                ++$totals['rawRecordPages'];
                $totals['rawRecords'] += count($rawRecords);

                foreach ($rawRecords as $rawRecord) {
                    $rawRecordId = (string) $rawRecord['id'];
                    $resultRows = $this->refreshRawRecordInSubprocess($targetCompanyId, $targetShopRef, $rawRecordId, $dryRun);

                    foreach ($resultRows as $row) {
                        $totals['scanned'] += (int) $row['scanned'];
                        $totals['matched'] += (int) $row['matched'];
                        $totals['updated'] += (int) $row['updated'];
                        $totals['unchanged'] += (int) $row['unchanged'];
                        $totals['missing'] += (int) $row['missing'];

                        if ('error' === $row['status']) {
                            ++$totals['failedRawRecords'];
                            $error = (string) ($row['error'] ?? 'Unknown raw record refresh error.');
                            $failedRawRecords[] = [
                                'companyId' => $targetCompanyId,
                                'shopRef' => $targetShopRef,
                                'rawId' => (string) ($row['rawId'] ?? $rawRecordId),
                                'error' => $error,
                            ];
                            $this->logger->error('Ozon accrual category metadata raw record failed.', [
                                'company_id' => $targetCompanyId,
                                'shop_ref' => $targetShopRef,
                                'raw_record_id' => (string) ($row['rawId'] ?? $rawRecordId),
                                'error' => $error,
                            ]);
                        }
                    }

                    $this->releaseMemory();
                }

                $offset += count($rawRecords);
            } while (count($rawRecords) === $limitPerShop);
        }

        return [
            'totals' => $totals,
            'failedRawRecords' => $failedRawRecords,
            'failedTargets' => $failedTargets,
        ];
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function refreshRawRecordInSubprocess(string $companyId, string $shopRef, string $rawRecordId, bool $dryRun): array
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
        ], $this->projectDir(), $this->subprocessEnv());
        $process->setTimeout(null);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            $this->logger->error('Ozon accrual category metadata subprocess crashed.', [
                'exception' => $exception,
                'company_id' => $companyId,
                'shop_ref' => $shopRef,
                'raw_record_id' => $rawRecordId,
            ]);

            return [$this->errorRow($rawRecordId, $exception->getMessage())];
        }

        $rows = $this->decodeResultRows($process->getOutput());
        if ([] !== $rows) {
            return $rows;
        }

        if ($process->isSuccessful()) {
            return [];
        }

        $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: sprintf('Subprocess exited with code %s.', (string) $process->getExitCode());

        return [$this->errorRow($rawRecordId, $error)];
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

    /**
     * @return array<string, string|int>
     */
    private function errorRow(string $rawRecordId, string $error): array
    {
        return [
            'rawId' => $rawRecordId,
            'status' => 'error',
            'scanned' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing' => 0,
            'error' => $error,
        ];
    }

    private function consolePath(): string
    {
        return $this->projectDir().'/bin/console';
    }

    private function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, string>
     */
    private function subprocessEnv(): array
    {
        $env = [];
        foreach (['APP_ENV', 'APP_DEBUG', 'DATABASE_URL', 'KERNEL_CLASS', 'SYMFONY_DEPRECATIONS_HELPER'] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            if (null === $value || false === $value || '' === $value) {
                continue;
            }

            $env[$name] = (string) $value;
        }

        return $env;
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
}
