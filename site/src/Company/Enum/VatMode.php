<?php

declare(strict_types=1);

namespace App\Company\Enum;

/**
 * Режим НДС компании. Сочетания с режимом налогообложения — TaxRegime::allowedVatModes().
 */
enum VatMode: string
{
    /** Не плательщик НДС или освобождён (УСН до порога, ст. 145, АУСН, ПСН). */
    case EXEMPT = 'EXEMPT';
    /** УСН, пониженная ставка 5% без права на вычет. */
    case USN_5 = 'USN_5';
    /** УСН, пониженная ставка 7% без права на вычет. */
    case USN_7 = 'USN_7';
    /** Общие ставки (22% / 10% / 0%) с вычетами. */
    case GENERAL = 'GENERAL';

    /**
     * Общая ставка НДС (п. 3 ст. 164 НК РФ): дата начала действия (Y-m-d) => ставка, %; по возрастанию дат.
     */
    private const GENERAL_RATE_SINCE = [
        '2019-01-01' => 20, // 303-ФЗ
        '2026-01-01' => 22, // 425-ФЗ
    ];

    private const GENERAL_RATE_BEFORE_2019 = 18;

    /**
     * Действующая ставка НДС, %; 0 — без НДС. Пониженные ставки УСН от даты не зависят, общая —
     * по календарной дате в её собственном часовом поясе: 01.01.2026 даёт 22% независимо от момента в UTC.
     */
    public function ratePercentOn(\DateTimeImmutable $date): int
    {
        return match ($this) {
            self::EXEMPT => 0,
            self::USN_5 => 5,
            self::USN_7 => 7,
            self::GENERAL => self::generalRateOn($date),
        };
    }

    private static function generalRateOn(\DateTimeImmutable $date): int
    {
        $day = $date->format('Y-m-d');
        $rate = self::GENERAL_RATE_BEFORE_2019;

        foreach (self::GENERAL_RATE_SINCE as $since => $sinceRate) {
            if ($day >= $since) {
                $rate = $sinceRate;
            }
        }

        return $rate;
    }

    public function label(): string
    {
        return match ($this) {
            self::EXEMPT => 'без НДС',
            self::USN_5 => 'НДС 5%',
            self::USN_7 => 'НДС 7%',
            self::GENERAL => 'НДС по общим ставкам',
        };
    }
}
