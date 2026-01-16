<?php

namespace App\Cash\Service\Vat;

class VatCalculator
{
    /**
     * @return array{net: string, vat: string}
     */
    public function splitGross(float $gross, int $rate): array
    {
        if ($rate <= 0) {
            $formattedGross = number_format($gross, 2, '.', '');

            return [
                'net' => $formattedGross,
                'vat' => '0.00',
            ];
        }

        $vat = $gross * $rate / (100 + $rate);
        $net = $gross - $vat;

        return [
            'net' => number_format($net, 2, '.', ''),
            'vat' => number_format($vat, 2, '.', ''),
        ];
    }
}
