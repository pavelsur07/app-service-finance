<?php

namespace App\Enum;

enum CompanyTaxSystem: string
{
    case USN_NO_VAT = 'USN_NO_VAT';
    case USN_VAT_5 = 'USN_VAT_5';
    case USN_VAT_7 = 'USN_VAT_7';
    case OSNO = 'OSNO';

    public function label(): string
    {
        return match ($this) {
            self::USN_NO_VAT => 'УСН без НДС',
            self::USN_VAT_5 => 'УСН с НДС 5%',
            self::USN_VAT_7 => 'УСН с НДС 7%',
            self::OSNO => 'ОСНО',
        };
    }
}
