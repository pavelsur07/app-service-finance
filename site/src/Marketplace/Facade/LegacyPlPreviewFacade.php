<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\PlPreviewFactsQuery;

final class LegacyPlPreviewFacade implements LegacyPlPreviewFacadeInterface
{
    public function __construct(private readonly PlPreviewFactsQuery $facts)
    {
    }

    public function amountsByCategory(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $marketplace = null): array
    {
        $amounts = [];
        foreach ($this->facts->fetch($companyId, $from, $to, null === $marketplace ? null : MarketplaceType::from($marketplace), false) as $fact) {
            $amounts[$fact->plCategoryId] = bcadd($amounts[$fact->plCategoryId] ?? '0', $fact->amount, 2);
        }

        return $amounts;
    }
}
