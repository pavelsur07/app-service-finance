<?php

declare(strict_types=1);

namespace App\Cash\ValueObject\Transfer;

use App\Cash\Enum\FiatCurrency;

final readonly class EffectiveExchangeRate
{
    public const SOURCE_MANUAL_EFFECTIVE = 'manual_effective';

    public function __construct(
        private string $value,
        private FiatCurrency $baseCurrency,
        private FiatCurrency $quoteCurrency,
        private \DateTimeImmutable $date,
    ) {
        if (1 !== preg_match('/^\d+\.\d{18}$/', $value) || bccomp($value, '0', 18) <= 0) {
            throw new \DomainException('Эффективный курс должен быть положительным decimal со scale 18.');
        }

        if ($baseCurrency === $quoteCurrency) {
            throw new \DomainException('Для одинаковых валют эффективный курс не создаётся.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function baseCurrency(): FiatCurrency
    {
        return $this->baseCurrency;
    }

    public function quoteCurrency(): FiatCurrency
    {
        return $this->quoteCurrency;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function source(): string
    {
        return self::SOURCE_MANUAL_EFFECTIVE;
    }
}
