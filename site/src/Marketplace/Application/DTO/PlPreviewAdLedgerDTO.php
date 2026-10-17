<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

/**
 * Рекламная категория маркетплейса, которую предпросмотр берёт из статистики: её правило маппинга
 * (статья, знак) и учётная сумма за период в знаке документа marketplace_pl.
 */
final readonly class PlPreviewAdLedgerDTO
{
    public function __construct(
        public MarketplaceType $marketplace,
        public string $costCategoryId,
        public string $plCategoryId,
        public bool $isNegative,
        public string $ledgerAmount,
    ) {
    }

    /**
     * Расход статистики в знаке документа: правило «расход» даёт «−».
     */
    public function signed(string $spend): string
    {
        return $this->isNegative ? bcmul($spend, '-1', 2) : bcadd($spend, '0', 2);
    }
}
