<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewCostStatus;

/**
 * Затраты периода одной категории: судьба при проведении и нетто-сумма (начисления − сторно,
 * модуль по группе, как у проведения; положительная — расход). costCategoryId = null — затраты
 * без категории.
 */
final readonly class PlPreviewCostCoverageDTO
{
    public function __construct(
        public MarketplaceType $marketplace,
        public ?string $costCategoryId,
        public ?string $costCategoryCode,
        public ?string $costCategoryName,
        public PlPreviewCostStatus $status,
        public int $rowsCount,
        public string $netAmount,
    ) {
    }
}
