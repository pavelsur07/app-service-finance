<?php

declare(strict_types=1);

namespace App\Finance\Report;

use App\Company\Facade\CompanyTaxProfileFacadeInterface;

/**
 * Системные переменные формул статей ОПиУ: действующие налоговые ставки компании, %.
 * Имена зарезервированы — статья с таким кодом переменную не перекрывает.
 */
final readonly class PlFormulaVariables
{
    public const VAT_RATE = 'VAT_RATE';
    public const TAX_RATE = 'TAX_RATE';

    /** @var list<string> */
    public const NAMES = [self::VAT_RATE, self::TAX_RATE];

    public function __construct(
        private CompanyTaxProfileFacadeInterface $taxProfiles,
    ) {
    }

    public static function isVariable(string $code): bool
    {
        return \in_array(strtoupper($code), self::NAMES, true);
    }

    /**
     * Значения на период отчёта; null — ставка не задана (режим не выбран или ставка налога пуста).
     *
     * @return array<string, ?float>
     */
    public function values(string $companyId, PlReportPeriod $period): array
    {
        // ponytail: ставка берётся на последний день периода. Для месяца точно (профиль меняется
        // с 1-го числа); диапазон через смену профиля считается по ставке конца периода —
        // если понадобится точнее, считать помесячно в вызывающем коде.
        $profile = $this->taxProfiles->taxProfileOn($companyId, $period->to);

        return [
            self::VAT_RATE => null === $profile ? null : (float) $profile->vatMode->ratePercentOn($period->to),
            self::TAX_RATE => null === $profile?->taxRatePercent ? null : (float) $profile->taxRatePercent,
        ];
    }
}
