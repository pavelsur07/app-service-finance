<?php

declare(strict_types=1);

namespace App\Twig;

use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Intl\Currencies;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class CurrencyFormatExtension extends AbstractExtension
{
    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_currency', [$this, 'formatCurrency']),
            new TwigFilter('format_minor_currency', [$this, 'formatMinorCurrency']),
        ];
    }

    public function formatCurrency(float|int|string $amount, string $currency): string
    {
        $currency = strtoupper($currency);

        $fractionDigits = $this->getFractionDigits($currency);
        $formatted = $this->formatNumber($amount, $fractionDigits);
        $symbol = $this->getCurrencySymbol($currency);

        return trim(sprintf('%s %s', $formatted, $symbol));
    }

    public function formatMinorCurrency(int $amountMinor, string $currency): string
    {
        $currency = strtoupper($currency);
        $decimal = Money::fromMinor($amountMinor, $currency)->toDecimalString();
        $negative = str_starts_with($decimal, '-');
        $unsigned = ltrim($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $groupedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) ?? $whole;
        $formatted = ($negative ? '-' : '').$groupedWhole;

        if ('' !== $fraction) {
            $formatted .= ','.$fraction;
        }

        return trim(sprintf('%s %s', $formatted, $this->getCurrencySymbol($currency)));
    }

    private function formatNumber(float|int|string $amount, int $fractionDigits): string
    {
        if (is_string($amount)) {
            $normalized = str_replace(["\u{00A0}", ' '], '', $amount);
            $normalized = str_replace(',', '.', $normalized);
            $value = (float) $normalized;
        } else {
            $value = (float) $amount;
        }

        return number_format($value, $fractionDigits, ',', ' ');
    }

    private function getCurrencySymbol(string $currency): string
    {
        try {
            return Currencies::getSymbol($currency);
        } catch (\InvalidArgumentException) {
            return $currency;
        }
    }

    private function getFractionDigits(string $currency): int
    {
        try {
            return Currencies::getFractionDigits($currency);
        } catch (\InvalidArgumentException) {
            return 2;
        }
    }
}
