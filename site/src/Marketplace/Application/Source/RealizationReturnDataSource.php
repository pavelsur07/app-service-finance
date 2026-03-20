<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Source;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnprocessedRealizationReturnQuery;
use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;

/**
 * Источник данных: возвраты из реализации Ozon.
 *
 * Применим ТОЛЬКО для Ozon.
 * Относится к этапу SALES_RETURNS.
 *
 * Агрегирует суммы возвратов из marketplace_ozon_realizations.return_amount
 * (return_commission.price_per_instance × return_commission.quantity).
 *
 * Маркирует обработанные строки через pl_document_id — тот же механизм
 * что и RealizationDataSource, поэтому одна строка может нести и продажу
 * и возврат, но оба источника используют общий pl_document_id.
 * Это означает что SALE_REALIZATION и RETURN_REALIZATION должны быть
 * в одном PLDocument (что и происходит — оба Source в одном этапе SALES_RETURNS).
 */
final class RealizationReturnDataSource implements MarketplaceDataSourceInterface
{
    public function __construct(
        private readonly UnprocessedRealizationReturnQuery     $returnQuery,
        private readonly MarketplaceOzonRealizationRepository  $realizationRepository,
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
        return 'ozon_realization_return';
    }

    public function getLabel(): string
    {
        return 'Возврат с СПП Ozon';
    }

    public function getUnprocessedEntries(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->returnQuery->execute($companyId, $periodFrom, $periodTo);
    }

    public function markProcessed(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        // Используем тот же markProcessed что и RealizationDataSource.
        // Фильтр WHERE pl_document_id IS NULL гарантирует что строки
        // не будут повторно помечены если SALE_REALIZATION отработал первым.
        return $this->realizationRepository->markProcessed(
            $companyId,
            $documentId,
            $periodFrom,
            $periodTo,
        );
    }
}
