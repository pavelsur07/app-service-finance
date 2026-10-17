<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewSalesKind;

/**
 * Строки продаж одного вида за период и есть ли у вида активное правило ОПиУ. Без правила вид
 * в ОПиУ не попадёт. amount — естественная сумма строк (выручка, сумма возврата, реализация).
 */
final readonly class PlPreviewSalesCoverageDTO
{
    public function __construct(
        public MarketplaceType $marketplace,
        public PlPreviewSalesKind $kind,
        public int $rowsCount,
        public string $amount,
        public bool $hasActiveMapping,
    ) {
    }
}
