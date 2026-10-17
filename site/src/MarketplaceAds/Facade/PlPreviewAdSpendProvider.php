<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Facade;

use App\Marketplace\Application\DTO\PlPreviewAdSpendDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\PlPreviewAdSpendProviderInterface;

/**
 * Рекламная статистика для предпросмотра ОПиУ Marketplace — те же числа, что в отчёте
 * эффективности рекламы и Unit-экономике.
 */
final readonly class PlPreviewAdSpendProvider implements PlPreviewAdSpendProviderInterface
{
    public function __construct(
        private MarketplaceAdsFacade $adsFacade,
    ) {
    }

    public function spendForPeriod(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): PlPreviewAdSpendDTO {
        return new PlPreviewAdSpendDTO(
            $this->adsFacade->getAdSpendByListingForPeriod($companyId, $from, $to, $marketplace->value),
            $this->adsFacade->getTotalAdCostForPeriod($companyId, $from, $to, $marketplace->value),
        );
    }
}
