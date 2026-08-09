<?php

declare(strict_types=1);

namespace App\Cash\Enum;

enum FiatCurrency: string
{
    case RUB = 'RUB';
    case USD = 'USD';
    case EUR = 'EUR';
    case KZT = 'KZT';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $currency): string => $currency->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            '₽ RUB' => self::RUB->value,
            '$ USD' => self::USD->value,
            '€ EUR' => self::EUR->value,
            '₸ KZT' => self::KZT->value,
        ];
    }

    public static function fromCode(string $currency): self
    {
        $normalized = strtoupper(trim($currency));

        return self::tryFrom($normalized)
            ?? throw new \InvalidArgumentException(sprintf('Unsupported fiat currency: %s.', $normalized));
    }

    public function scale(): int
    {
        return 2;
    }
}
