<?php
declare(strict_types=1);

namespace App\Finance\Facts;

use App\Entity\Company;

final class NullFactsProvider implements FactsProviderInterface
{
    public function value(Company $company, \DateTimeInterface $period, string $code): float
    {
        return 0.0;
    }
}
