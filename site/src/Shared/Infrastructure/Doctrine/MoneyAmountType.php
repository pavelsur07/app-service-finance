<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BigIntType;

/**
 * BIGINT-колонка, гидрируемая в PHP `int` (а не `string`, как штатный BigIntType).
 *
 * Нужен для маппинга `Money::$amountMinor` (readonly int) в Embeddable: штатный
 * bigint отдаёт строку, что несовместимо с типизированным int-свойством.
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

    public function getName(): string
    {
        return self::NAME;
    }
}
