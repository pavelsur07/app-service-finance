<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\Command\GeneratePLCommand;
use App\Marketplace\DTO\PLEntryDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarkProcessedQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedReturnsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedSalesQuery;

/**
 * Оркестратор генерации документа ОПиУ из данных маркетплейса.
 *
 * Поток:
 *   1. Агрегирует необработанные записи (DBAL Query)
 *   2. Трансформирует в PLEntryDTO[]
 *   3. Вызывает FinanceFacade::createPLDocument()
 *   4. Помечает записи как обработанные (bulk UPDATE)
 *
 * Не использует ActiveCompanyService — companyId приходит через Command.
 */
final class GeneratePLFromMarketplaceAction
{
    public function __construct(
        private readonly UnprocessedSalesQuery $salesQuery,
        private readonly UnprocessedReturnsQuery $returnsQuery,
        private readonly UnprocessedCostsQuery $costsQuery,
        private readonly MarkProcessedQuery $markProcessedQuery,
        private readonly FinanceFacade $financeFacade,
    ) {
    }

    /**
     * @return string|null documentId или null если нет данных для обработки
     */
    public function __invoke(GeneratePLCommand $cmd): ?string
    {
        $marketplace = MarketplaceType::from($cmd->marketplace);
        $stream = PLDocumentStream::from($cmd->stream);
        $source = $this->resolveSource($marketplace);

        // 1. Агрегировать данные
        $entries = match ($stream) {
            PLDocumentStream::REVENUE => $this->aggregateRevenue(
                $cmd->companyId,
                $cmd->marketplace,
                $cmd->periodFrom,
                $cmd->periodTo,
            ),
            PLDocumentStream::COSTS => $this->aggregateCosts(
                $cmd->companyId,
                $cmd->marketplace,
                $cmd->periodFrom,
                $cmd->periodTo,
            ),
            PLDocumentStream::STORNO => [], // Отложено
        };

        if (empty($entries)) {
            return null;
        }

        // 2. Создать документ ОПиУ через Facade
        $documentId = $this->financeFacade->createPLDocument(
            companyId: $cmd->companyId,
            source: $source,
            stream: $stream,
            periodFrom: $cmd->periodFrom,
            periodTo: $cmd->periodTo,
            entries: $entries,
        );

        // 3. Пометить записи как обработанные
        $this->markRecords($cmd, $stream, $documentId);

        return $documentId;
    }

    /**
     * Агрегация потока REVENUE: продажи + возвраты.
     *
     * @return PLEntryDTO[]
     */
    private function aggregateRevenue(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $entries = [];

        // Продажи
        $salesRows = $this->salesQuery->execute($companyId, $marketplace, $periodFrom, $periodTo);
        foreach ($salesRows as $row) {
            $entries[] = $this->rowToDTO($row, $periodTo);
        }

        // Возвраты
        $returnsRows = $this->returnsQuery->execute($companyId, $marketplace, $periodFrom, $periodTo);
        foreach ($returnsRows as $row) {
            $entries[] = $this->rowToDTO($row, $periodTo);
        }

        return $entries;
    }

    /**
     * Агрегация потока COSTS: комиссии, логистика, хранение.
     *
     * @return PLEntryDTO[]
     */
    private function aggregateCosts(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $entries = [];

        $costsRows = $this->costsQuery->execute($companyId, $marketplace, $periodFrom, $periodTo);
        foreach ($costsRows as $row) {
            $entries[] = new PLEntryDTO(
                plCategoryId: $row['pl_category_id'],
                projectId: null,
                amount: $row['total_amount'],
                periodDate: $periodTo,
                description: $row['description'] ?? 'Расходы МП',
                isNegative: true, // Costs всегда отрицательные
                sortOrder: 0,
            );
        }

        return $entries;
    }

    /**
     * Трансформация строки агрегации (sales/returns) в PLEntryDTO.
     */
    private function rowToDTO(array $row, string $periodDate): PLEntryDTO
    {
        return new PLEntryDTO(
            plCategoryId: $row['pl_category_id'],
            projectId: $row['project_direction_id'] ?? null,
            amount: $row['total_amount'],
            periodDate: $periodDate,
            description: $row['description_template'] ?? '',
            isNegative: (bool) $row['is_negative'],
            sortOrder: (int) ($row['sort_order'] ?? 0),
        );
    }

    /**
     * Пометить обработанные записи (bulk UPDATE).
     */
    private function markRecords(GeneratePLCommand $cmd, PLDocumentStream $stream, string $documentId): void
    {
        match ($stream) {
            PLDocumentStream::REVENUE => $this->markRevenueRecords($cmd, $documentId),
            PLDocumentStream::COSTS => $this->markProcessedQuery->markCosts(
                $cmd->companyId,
                $cmd->marketplace,
                $documentId,
                $cmd->periodFrom,
                $cmd->periodTo,
            ),
            PLDocumentStream::STORNO => null, // Отложено
        };
    }

    private function markRevenueRecords(GeneratePLCommand $cmd, string $documentId): void
    {
        $this->markProcessedQuery->markSales(
            $cmd->companyId,
            $cmd->marketplace,
            $documentId,
            $cmd->periodFrom,
            $cmd->periodTo,
        );

        $this->markProcessedQuery->markReturns(
            $cmd->companyId,
            $cmd->marketplace,
            $documentId,
            $cmd->periodFrom,
            $cmd->periodTo,
        );
    }

    private function resolveSource(MarketplaceType $marketplace): PLDocumentSource
    {
        return match ($marketplace) {
            MarketplaceType::WILDBERRIES => PLDocumentSource::MARKETPLACE_WB,
            MarketplaceType::OZON => PLDocumentSource::MARKETPLACE_OZON,
            MarketplaceType::YANDEX_MARKET => PLDocumentSource::MARKETPLACE_YANDEX,
            MarketplaceType::SBER_MEGAMARKET => PLDocumentSource::MARKETPLACE_SBER,
        };
    }
}
