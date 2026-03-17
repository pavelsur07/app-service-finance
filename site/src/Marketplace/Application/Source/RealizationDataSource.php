<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Source;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnprocessedRealizationQuery;
use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;

/**
 * Источник данных: реализация Ozon.
 *
 * Применим ТОЛЬКО для Ozon.
 * Относится к этапу SALES_RETURNS.
 *
 * Агрегирует строки из marketplace_ozon_realizations
 * через маппинг amount_source = 'sale_realization'.
 *
 * Маркирует обработанные строки через pl_document_id.
 */
final class RealizationDataSource implements MarketplaceDataSourceInterface
{
    public function __construct(
        private readonly UnprocessedRealizationQuery          $realizationQuery,
        private readonly MarketplaceOzonRealizationRepository $realizationRepository,
    ) {
    }

    public function supports(MarketplaceType $marketplace): bool
    {
        return $marketplace === MarketplaceType::OZON;
    }

    public function getStage(): CloseStage
    {
        return CloseStage::SALES_RETURNS;
    }

    public function getSourceId(): string
    {
        return 'ozon_realization';
    }

    public function getLabel(): string
    {
        return 'Реализация Ozon';
    }

    public function getUnprocessedEntries(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        // marketplace параметр игнорируем — supports() гарантирует что это Ozon
        return $this->realizationQuery->execute($companyId, $periodFrom, $periodTo);
    }

    public function markProcessed(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->realizationRepository->markProcessed(
            $companyId,
            $documentId,
            $periodFrom,
            $periodTo,
        );
    }
}
