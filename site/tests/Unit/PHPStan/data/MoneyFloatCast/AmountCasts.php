<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\MoneyFloatCast;

final class AmountCasts
{
    public function getAmount(): string
    {
        return '1.00';
    }

    public function getVatAmount(): ?string
    {
        return null;
    }

    public static function getTotalAmount(): string
    {
        return '1.00';
    }

    public function getName(): string
    {
        return '1';
    }

    /**
     * @return list<float|string>
     */
    public function casts(?self $other): array
    {
        $amount = $this->getAmount();

        return [
            (float) $this->getAmount(),
            (float) $other?->getVatAmount(),
            (float) self::getTotalAmount(),
            (float) $this->getName(),
            (float) $amount,
            (string) $this->getAmount(),
        ];
    }
}
