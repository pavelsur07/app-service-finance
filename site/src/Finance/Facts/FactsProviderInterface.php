<?php
declare(strict_types=1);

namespace App\Finance\Facts;

use App\Entity\Company;

interface FactsProviderInterface
{
    public function value(Company $company, \DateTimeInterface $period, string $code): float;
}
