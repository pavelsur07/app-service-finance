<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Application\DTO\PlPreviewUnplacedAmount;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewCostStatus;

/**
 * Предпросмотр ОПиУ по данным маркетплейсов: что покажет ОПиУ, если провести данные периода по
 * текущему маппингу. Значения строк — по ключам колонок.
 */
final readonly class MarketplacePlPreviewReport
{
    /**
     * @param list<MarketplacePlPreviewColumn> $columns
     * @param list<PlPreviewRow> $rows дерево ОПиУ компании
     * @param list<PlPreviewUnplacedAmount> $unplaced суммы на статьях, которые не попадут в отчёт
     * @param list<PlPreviewCostCoverageDTO> $costCoverage затраты периода по категориям и статусам
     * @param list<PlPreviewSalesCoverageDTO> $salesCoverage
     * @param list<string> $warnings
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?MarketplaceType $marketplace,
        public array $columns,
        public array $rows,
        public bool $tagsOverlap,
        public array $unplaced,
        public array $costCoverage,
        public string $costTotal,
        public string $costReconciliationDelta,
        public array $salesCoverage,
        public array $warnings,
    ) {
    }

    /**
     * @return list<PlPreviewCostCoverageDTO>
     */
    public function costsWithStatus(PlPreviewCostStatus $status): array
    {
        return array_values(array_filter(
            $this->costCoverage,
            static fn (PlPreviewCostCoverageDTO $row): bool => $row->status === $status,
        ));
    }

    /**
     * @return list<PlPreviewSalesCoverageDTO>
     */
    public function salesWithoutMapping(): array
    {
        return array_values(array_filter(
            $this->salesCoverage,
            static fn (PlPreviewSalesCoverageDTO $row): bool => !$row->hasActiveMapping,
        ));
    }
}
