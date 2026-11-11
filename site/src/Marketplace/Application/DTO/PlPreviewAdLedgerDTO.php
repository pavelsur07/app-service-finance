<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Рекламная категория маркетплейса, которую предпросмотр берёт из статистики: её правило маппинга
 * (статья, знак) и учётная сумма за период в знаке документа marketplace_pl. outsideAmount — в том
 * же знаке прочие категории маркетплейса в этой статье, которых нет в рекламной статистике.
 * kind — вид статистики, которым закрывается категория; null — вся статистика маркетплейса.
 */
final readonly class PlPreviewAdLedgerDTO
{
    public function __construct(
        public MarketplaceType $marketplace,
        public ?AdSpendKind $kind,
        public string $costCategoryId,
        public string $plCategoryId,
        public bool $isNegative,
        public string $ledgerAmount,
        public string $outsideAmount,
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
