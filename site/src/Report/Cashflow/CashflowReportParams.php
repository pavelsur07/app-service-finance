<?php

namespace App\Report\Cashflow;

use App\Company\Entity\Company;

final class CashflowReportParams
{
    public function __construct(
        public readonly Company $company,
        public readonly string $group,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
    ) {
    }
}
