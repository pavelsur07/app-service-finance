<?php

namespace App\Twig;

use InvalidArgumentException;
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
        } catch (InvalidArgumentException) {
            return $currency;
        }
    }

    private function getFractionDigits(string $currency): int
    {
        try {
            return Currencies::getFractionDigits($currency);
        } catch (InvalidArgumentException) {
            return 2;
        }
    }
}

