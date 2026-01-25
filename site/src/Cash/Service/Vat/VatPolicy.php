<?php

namespace App\Cash\Service\Vat;

use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
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
            CompanyTaxSystem::USN_VAT_5 => CashDirection::OUTFLOW === $direction ? 5 : null,
            CompanyTaxSystem::USN_VAT_7 => CashDirection::OUTFLOW === $direction ? 7 : null,
            CompanyTaxSystem::OSNO => CashDirection::OUTFLOW === $direction ? 20 : null,
        };
    }
}
