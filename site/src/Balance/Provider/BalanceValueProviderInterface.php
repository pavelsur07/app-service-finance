<?php

declare(strict_types=1);

namespace App\Balance\Provider;

use App\Balance\Enum\BalanceLinkSourceType;

interface BalanceValueProviderInterface
{
    public function supports(BalanceLinkSourceType $type): bool;

    /**
     * @return array<string, string> currency => decimal string
     */
    public function getTotalsForCompanyUpToDate(string $companyId, \DateTimeImmutable $date): array;
}
