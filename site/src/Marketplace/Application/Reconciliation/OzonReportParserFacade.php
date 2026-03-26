<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

use App\Shared\Service\Storage\StorageService;

/**
 * Оркестрирует парсинг xlsx отчёта «Детализация начислений» Ozon.
 *
 * Возвращает ReportResult:
 *   period, lines[], totalAccruals, totalExpenses, totalStorno, totalNet
 */
final class OzonReportParserFacade
{
    public function __construct(
        private readonly XlsxReaderService       $reader,
        private readonly BaseSignResolverService  $baseSignResolver,
        private readonly RowClassifierService     $classifier,
        private readonly ReportAggregatorService  $aggregator,
        private readonly StorageService           $storageService,
    ) {
    }

    /**
     * Парсит файл по абсолютному пути.
     *
     * @return array<string, mixed> ReportResult
     */
    public function parseFromPath(string $absolutePath): array
    {
        $data       = $this->reader->read($absolutePath);
        $baseSign   = $this->baseSignResolver->resolve($data['rows']);
        $classified = $this->classifier->classify($data['rows'], $baseSign);

        return $this->aggregator->aggregate($data['period'], $classified, $baseSign);
    }

    /**
     * Парсит файл по relative path из StorageService.
     *
     * @return array<string, mixed> ReportResult
     */
    public function parseFromStoragePath(string $relativePath): array
    {
        return $this->parseFromPath(
            $this->storageService->getAbsolutePath($relativePath),
        );
    }
}
