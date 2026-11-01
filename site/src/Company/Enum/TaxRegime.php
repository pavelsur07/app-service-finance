<?php

declare(strict_types=1);

namespace App\Company\Enum;

/**
 * Режим налогообложения компании. Подвид УСН («доходы» / «доходы − расходы») не хранится:
 * на НДС он не влияет.
 */
enum TaxRegime: string
{
    case OSNO = 'OSNO';
    case USN = 'USN';
    case AUSN = 'AUSN';
    case PSN = 'PSN';
    case ESHN = 'ESHN';

    public function label(): string
    {
        return match ($this) {
            self::OSNO => 'ОСНО',
            self::USN => 'УСН',
            self::AUSN => 'АУСН',
            self::PSN => 'Патент',
            self::ESHN => 'ЕСХН',
        };
    }

    /**
     * Режимы НДС, допустимые при этом режиме налогообложения; первый — режим по умолчанию.
     *
     * @return non-empty-list<VatMode>
     */
    public function allowedVatModes(): array
    {
        return match ($this) {
            self::OSNO => [VatMode::GENERAL, VatMode::EXEMPT],
            self::USN => [VatMode::EXEMPT, VatMode::USN_5, VatMode::USN_7, VatMode::GENERAL],
            self::AUSN, self::PSN => [VatMode::EXEMPT],
            self::ESHN => [VatMode::EXEMPT, VatMode::GENERAL],
        };
    }

    public function allows(VatMode $vatMode): bool
    {
        return \in_array($vatMode, $this->allowedVatModes(), true);
    }
}
