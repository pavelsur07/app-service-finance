<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\Service;

use App\Company\Facade\CompanyTaxProfileFacadeInterface;

/**
 * Ставка НДС компании по месяцам периода: профиль на первый день месяца в периоде. Профиль меняется
 * с начала месяца, общая ставка — с 1 января, поэтому месяц — достаточная гранулярность.
 */
final readonly class VatRatesByMonth
{
    public function __construct(private CompanyTaxProfileFacadeInterface $taxProfiles)
    {
    }

    /** @return array<string, int> Y-m => ставка, %; 0 — без НДС или режим не задан */
    public function rates(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rates = [];
        for ($day = $from; $day <= $to; $day = $day->modify('first day of next month')) {
            $rates[$day->format('Y-m')] = $this->taxProfiles->taxProfileOn($companyId, $day)?->vatMode->ratePercentOn($day) ?? 0;
        }

        return $rates;
    }
}
