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
