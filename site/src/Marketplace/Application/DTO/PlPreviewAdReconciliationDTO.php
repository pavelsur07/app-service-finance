<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

/**
 * Реклама маркетплейса за период: учёт против рекламной статистики. Суммы — положительный расход.
 * difference = учёт − статистика: в предпросмотре лежит в «Общих» вместе со статистикой без товара.
 */
final readonly class PlPreviewAdReconciliationDTO
{
    public function __construct(
        public MarketplaceType $marketplace,
        public string $ledger,
        public string $statistics,
        public string $onListings,
        public string $unallocated,
        public string $difference,
    ) {
    }
}
