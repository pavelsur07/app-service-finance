<?php
declare(strict_types=1);

namespace App\Finance\Engine;

use App\Enum\PLValueFormat;

final class ValueFormatter
{
    public function format(float $value, PLValueFormat $format): string
    {
        return match($format) {
            PLValueFormat::MONEY   => number_format($value, 2, '.', ' '),
            PLValueFormat::PERCENT => number_format($value * 100.0, 1, '.', ' ').' %',
            PLValueFormat::RATIO   => number_format($value, 4, '.', ' '),
            PLValueFormat::QTY     => number_format($value, 2, '.', ' '),
        };
    }
}
