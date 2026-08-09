<?php

declare(strict_types=1);

namespace App\Cash\Service\Transfer;

use App\Cash\Enum\FiatCurrency;
use App\Cash\ValueObject\Transfer\EffectiveExchangeRate;
use App\Shared\Domain\ValueObject\Money;

final class EffectiveExchangeRateCalculator
{
    public const SCALE = 18;

    public function calculate(
        string $sourceAmount,
        string $sourceCurrency,
        string $targetAmount,
        string $targetCurrency,
        \DateTimeImmutable $date,
    ): ?EffectiveExchangeRate {
        $baseCurrency = FiatCurrency::fromCode($sourceCurrency);
        $quoteCurrency = FiatCurrency::fromCode($targetCurrency);
        $source = $this->positiveMoney($sourceAmount, $baseCurrency);
        $target = $this->positiveMoney($targetAmount, $quoteCurrency);

        if ($baseCurrency === $quoteCurrency) {
            if (!$source->equals($target)) {
                throw new \DomainException('Суммы перевода в одной валюте должны совпадать.');
            }

            return null;
        }

        if (!$baseCurrency->canTransferTo($quoteCurrency)) {
            throw new \DomainException('Разрешены только кросс-валютные переводы RUB↔USD и RUB↔EUR.');
        }

        $unrounded = bcdiv(
            (string) $target->amountMinor(),
            (string) $source->amountMinor(),
            self::SCALE + 1,
        );
        $roundingIncrement = '0.'.str_repeat('0', self::SCALE).'5';
        $value = bcadd($unrounded, $roundingIncrement, self::SCALE);

        return new EffectiveExchangeRate($value, $baseCurrency, $quoteCurrency, $date);
    }

    private function positiveMoney(string $amount, FiatCurrency $currency): Money
    {
        $normalized = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($amount));
        if (1 !== preg_match('/^\d+(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new \DomainException('Сумма перевода должна быть положительным decimal.');
        }

        $fraction = $matches[1] ?? '';
        if (strlen($fraction) > $currency->scale()) {
            throw new \DomainException(sprintf('Сумма перевода должна иметь не более %d знаков после запятой.', $currency->scale()));
        }

        $money = Money::fromString($normalized, $currency->value);
        if (!$money->isPositive()) {
            throw new \DomainException('Суммы перевода должны быть положительными.');
        }

        return $money;
    }
}
