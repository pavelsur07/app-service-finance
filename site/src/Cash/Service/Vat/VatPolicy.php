<?php

declare(strict_types=1);

namespace App\Cash\Service\Vat;

use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Enum\CompanyTaxSystem;

final readonly class VatPolicy
{
    public function __construct(
        private VatRates $vatRates,
    ) {
    }

    /**
     * Ставка НДС операции ДДС, %; null — НДС не выделяется.
     * Общая ставка (ОСНО) берётся на дату операции, пониженные ставки УСН от даты не зависят.
     */
    public function decideForCash(Company $company, CashDirection $direction, \DateTimeImmutable $occurredAt): ?int
    {
        $taxSystem = $company->getTaxSystem();

        if (null === $taxSystem || CashDirection::OUTFLOW !== $direction) {
            return null;
        }

        return match ($taxSystem) {
            CompanyTaxSystem::USN_NO_VAT => null,
            CompanyTaxSystem::USN_VAT_5 => 5,
            CompanyTaxSystem::USN_VAT_7 => 7,
            CompanyTaxSystem::OSNO => $this->vatRates->generalRateOn($occurredAt),
        };
    }
}
