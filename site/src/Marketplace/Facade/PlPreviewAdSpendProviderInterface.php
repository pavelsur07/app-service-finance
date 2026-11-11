<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Application\DTO\PlPreviewAdSpendDTO;
use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Порт: рекламный расход по листингам за период для предпросмотра ОПиУ.
 *
 * Реализует модуль MarketplaceAds. Порт объявлен здесь, а не вызовом MarketplaceAdsFacade, потому
 * что MarketplaceAds уже зависит от Marketplace — прямой вызов дал бы цикл модулей.
 */
/**
 * kind — только этот вид статистики (null — вся статистика маркетплейса).
 */
interface PlPreviewAdSpendProviderInterface
{
    public function spendForPeriod(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?AdSpendKind $kind = null,
    ): PlPreviewAdSpendDTO;
}
