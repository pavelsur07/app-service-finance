<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BigIntType;

/**
 * BIGINT-колонка, гидрируемая всегда в PHP `int`.
 *
 * Нужен для маппинга `Money::$amountMinor` (readonly int) в Embeddable. Штатный
 * bigint в DBAL 4 отдаёт `string` для значений вне PHP int, а в DBAL 3 отдавал
 * строку всегда — тип фиксирует `int` явно. Пара для string-свойств —
 * StringBigIntType.
 *
 * Диапазон: PHP int 64-бит = ±9.2·10^18 копеек ≈ ±9.2·10^16 ₽ — покрывает decimal(18,2).
 */
final class MoneyAmountType extends BigIntType
{
    public const NAME = 'money_amount_minor';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }

        return (int) $value;
    }
}
