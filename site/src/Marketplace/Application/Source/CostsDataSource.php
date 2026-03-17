<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Source;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarkProcessedQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;

/**
 * Источник данных: затраты маркетплейса.
 *
 * Применим для всех маркетплейсов.
 * Относится к этапу COSTS.
 *
 * Агрегирует затраты через новый маппинг MarketplaceCostPLMapping
 * с учётом флага include_in_pl.
 */
final class CostsDataSource implements MarketplaceDataSourceInterface
{
    public function __construct(
        private readonly UnprocessedCostsQuery $costsQuery,
        private readonly MarkProcessedQuery    $markProcessedQuery,
    ) {
    }

    public function supports(MarketplaceType $marketplace): bool
    {
        return true; // Применим для всех маркетплейсов
    }

    public function getStage(): CloseStage
    {
        return CloseStage::COSTS;
    }

    public function getSourceId(): string
    {
        return 'costs';
    }

    public function getLabel(): string
    {
        return 'Затраты маркетплейса';
    }

    public function getUnprocessedEntries(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->costsQuery->execute($companyId, $marketplace, $periodFrom, $periodTo);
    }

    public function markProcessed(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->markProcessedQuery->markCosts(
            $companyId, $marketplace, $documentId, $periodFrom, $periodTo,
        );
    }
}
