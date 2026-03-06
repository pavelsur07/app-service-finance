<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Contract;

use App\Marketplace\Enum\MarketplaceType;

interface MarketplaceFetcherInterface
{
    public function supports(MarketplaceType $type): bool;

    /**
     * @return \Generator<int, array>
     */
    public function fetch(string $companyId, \DateTimeImmutable $dateFrom): \Generator;
}
