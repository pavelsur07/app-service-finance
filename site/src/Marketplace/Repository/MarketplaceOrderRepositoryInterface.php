<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceOrder;
use App\Marketplace\Enum\MarketplaceType;

interface MarketplaceOrderRepositoryInterface
{
    public function save(MarketplaceOrder $order): void;

    /**
     * @return MarketplaceOrder[]
     */
    public function findByListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array;

    /**
     * @return MarketplaceOrder[]
     */
    public function findByCompanyAndDate(
        string $companyId,
        \DateTimeImmutable $date,
    ): array;

    public function findByIdAndCompany(
        string $id,
        string $companyId,
    ): ?MarketplaceOrder;

    public function findByExternalId(
        string $companyId,
        MarketplaceType $marketplace,
        string $externalOrderId,
    ): ?MarketplaceOrder;
}
