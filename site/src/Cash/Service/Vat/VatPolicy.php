<?php

namespace App\Cash\Service\Vat;

use App\Entity\Company;
use App\Enum\CashDirection;
use App\Enum\CompanyTaxSystem;

class VatPolicy
{
    public function decideForCash(Company $company, CashDirection $direction): ?int
    {
        $taxSystem = $company->getTaxSystem();

        if (null === $taxSystem) {
            return null;
        }

        return match ($taxSystem) {
            CompanyTaxSystem::USN_NO_VAT => null,
            CompanyTaxSystem::USN_VAT_5 => $direction === CashDirection::OUTFLOW ? 5 : null,
            CompanyTaxSystem::USN_VAT_7 => $direction === CashDirection::OUTFLOW ? 7 : null,
            CompanyTaxSystem::OSNO => $direction === CashDirection::OUTFLOW ? 20 : null,
        };
    }
}
