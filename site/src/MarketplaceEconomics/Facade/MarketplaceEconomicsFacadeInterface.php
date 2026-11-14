<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Facade;

use App\MarketplaceEconomics\Application\DTO\EconomicsDaySum;

/** Витрина экономики SKU для других модулей: только чтение. */
interface MarketplaceEconomicsFacadeInterface
{
    /** @return list<EconomicsDaySum> дни витрины компании в окне дат */
    public function daySums(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
