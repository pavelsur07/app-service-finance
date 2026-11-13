<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\Transaction\Vat;

use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Enum\VatMode;
use App\Company\Facade\CompanyTaxProfileFacadeInterface;

final readonly class VatPolicy
{
    public function __construct(
        private VatRates $vatRates,
        private CompanyTaxProfileFacadeInterface $taxProfiles,
    ) {
    }

    /**
     * Ставка НДС операции ДДС, %; null — НДС не выделяется.
     * Режим НДС — налоговый профиль компании на дату операции; общая ставка тоже на эту дату,
     * пониженные ставки УСН от даты не зависят.
     */
    public function decideForCash(string $companyId, CashDirection $direction, \DateTimeImmutable $occurredAt): ?int
    {
        if (CashDirection::OUTFLOW !== $direction) {
            return null;
        }

        $profile = $this->taxProfiles->taxProfileOn($companyId, $occurredAt);
        if (null === $profile) {
            return null;
        }

        return match ($profile->vatMode) {
            VatMode::EXEMPT => null,
            VatMode::USN_5 => 5,
            VatMode::USN_7 => 7,
            VatMode::GENERAL => $this->vatRates->generalRateOn($occurredAt),
        };
    }
}
