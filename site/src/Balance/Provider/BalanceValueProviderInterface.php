<?php

namespace App\Balance\Provider;

use App\Balance\Enum\BalanceLinkSourceType;
use App\Company\Entity\Company;

interface BalanceValueProviderInterface
{
    public function supports(BalanceLinkSourceType $type): bool;

    /** @return array<string,float> */
    public function getTotalsForCompanyUpToDate(Company $company, \DateTimeImmutable $date): array;
}
