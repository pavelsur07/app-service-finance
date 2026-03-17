<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Source;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarkProcessedQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedReturnsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedSalesQuery;

/**
 * Источник данных: продажи и возвраты.
 *
 * Применим для всех маркетплейсов.
 * Относится к этапу SALES_RETURNS.
 */
final class SalesReturnsDataSource implements MarketplaceDataSourceInterface
{
    public function __construct(
        private readonly UnprocessedSalesQuery   $salesQuery,
        private readonly UnprocessedReturnsQuery $returnsQuery,
        private readonly MarkProcessedQuery      $markProcessedQuery,
    ) {
    }

    public function supports(MarketplaceType $marketplace): bool
    {
        return true; // Применим для всех маркетплейсов
    }

    public function getStage(): CloseStage
    {
        return CloseStage::SALES_RETURNS;
    }

    public function getSourceId(): string
    {
        return 'sales_returns';
    }

    public function getLabel(): string
    {
        return 'Продажи и возвраты';
    }

    public function getUnprocessedEntries(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $entries = [];

        foreach ($this->salesQuery->execute($companyId, $marketplace, $periodFrom, $periodTo) as $row) {
            $entries[] = $row;
        }

        foreach ($this->returnsQuery->execute($companyId, $marketplace, $periodFrom, $periodTo) as $row) {
            $entries[] = $row;
        }

        return $entries;
    }

    public function markProcessed(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        $count = $this->markProcessedQuery->markSales(
            $companyId, $marketplace, $documentId, $periodFrom, $periodTo,
        );

        $count += $this->markProcessedQuery->markReturns(
            $companyId, $marketplace, $documentId, $periodFrom, $periodTo,
        );

        return $count;
    }
}
