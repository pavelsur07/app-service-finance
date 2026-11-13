<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\Transaction\Vat;

/**
 * Общая ставка НДС (п. 3 ст. 164 НК РФ) по дате операции.
 *
 * Ставка определяется календарной датой операции в её собственном часовом поясе:
 * операция от 01.01.2026 получает 22% независимо от того, какой это момент в UTC.
 */
final readonly class VatRates
{
    /**
     * Дата начала действия (Y-m-d) => ставка, %; по возрастанию дат.
     */
    private const GENERAL_RATE_SINCE = [
        '2019-01-01' => 20, // 303-ФЗ
        '2026-01-01' => 22, // 425-ФЗ
    ];

    private const GENERAL_RATE_BEFORE_2019 = 18;

    public function generalRateOn(\DateTimeImmutable $date): int
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
}
