<?php

declare(strict_types=1);

namespace App\Cash\Service\Vat;

use App\Shared\Domain\ValueObject\Money;

final readonly class VatCalculator
{
    /**
     * Выделяет НДС из суммы с НДС: vat = gross × rate / (100 + rate), net = gross − vat.
     *
     * Округление одно — у НДС, до минорных единиц валюты, половина от нуля
     * (`HALF_UP`, как прежний `number_format()`); net — точный остаток, поэтому
     * net + vat всегда равно gross.
     *
     * @return array{net: Money, vat: Money}
     */
    public function splitGross(Money $gross, int $rate): array
    {
        if ($rate <= 0) {
            return [
                'net' => $gross,
                'vat' => Money::fromMinor(0, $gross->currency()),
            ];
        }

        $vat = $gross->multiplyByRatio((string) $rate, (string) (100 + $rate));

        return [
            'net' => $gross->subtract($vat),
            'vat' => $vat,
        ];
    }
}
