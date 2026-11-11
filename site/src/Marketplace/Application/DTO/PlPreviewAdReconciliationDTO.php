<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Реклама маркетплейса за период: учёт против рекламной статистики. Суммы — положительный расход.
 * difference = учёт − статистика: в предпросмотре лежит в «Общих» вместе со статистикой без товара.
 * outsideStatistics — прочие категории той же статьи ОПиУ вне статистики: учёт + они = строка статьи
 * (у второй категории той же статьи — 0, чтобы не задвоить). kind — вид статистики строки.
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
        public string $outsideStatistics,
        public ?AdSpendKind $kind = null,
    ) {
    }

    public function title(): string
    {
        return null === $this->kind
            ? $this->marketplace->getDisplayName()
            : $this->marketplace->getDisplayName().' — '.$this->kind->label();
    }
}
