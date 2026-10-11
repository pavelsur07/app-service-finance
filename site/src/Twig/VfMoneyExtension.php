<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Деньги по правилам DS «Ваш Финдир» для легаси-шаблонов: тысячи — U+00A0,
 * десятичный разделитель — запятая, минус — U+2212. Знак валюты по умолчанию не
 * выводится: в таблицах он в заголовке колонки.
 */
final class VfMoneyExtension extends AbstractExtension
{
    private const NBSP = "\u{00A0}";
    private const MINUS = "\u{2212}";
    private const TONES = ['income', 'profit'];
    private const SHORT_UNITS = [
        [9, 'млрд'],
        [6, 'млн'],
        [3, 'тыс.'],
    ];

    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('vf_money', $this->money(...)),
            new TwigFilter('vf_money_short', $this->moneyShort(...)),
            new TwigFilter('vf_amount', $this->amount(...), ['is_safe' => ['html']]),
        ];
    }

    public function money(int|float|string|null $value, int $decimals = 2, ?string $currency = null): string
    {
        if (null === $value) {
            return '—';
        }

        $formatted = $this->format($this->round($this->normalize($value), $decimals));

        return null === $currency ? $formatted : $formatted.self::NBSP.$currency;
    }

    /**
     * KPI: «6,48 млн ₽». До двух знаков после запятой, нули в хвосте отбрасываются.
     */
    public function moneyShort(int|float|string|null $value, string $currency = '₽'): string
    {
        if (null === $value) {
            return '—';
        }

        $decimal = $this->normalize($value);
        $unit = '';
        foreach (self::SHORT_UNITS as [$power, $label]) {
            if (bccomp($this->abs($decimal), bcpow('10', (string) $power), 0) >= 0) {
                $decimal = bcdiv($decimal, bcpow('10', (string) $power), 10);
                $unit = $label;
                break;
            }
        }

        $formatted = $this->format($this->round($decimal, '' === $unit ? 0 : 2));
        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return implode(self::NBSP, array_filter([$formatted, $unit, $currency], static fn (string $part): bool => '' !== $part));
    }

    /**
     * Сумма для таблицы: копейки приглушены, цвет по смыслу.
     * tone: income — зелёный; profit — danger, если меньше нуля; null — обычный цвет.
     */
    public function amount(int|float|string|null $value, int $decimals = 2, ?string $tone = null): string
    {
        if (null !== $tone && !\in_array($tone, self::TONES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown amount tone "%s".', $tone));
        }
        if (null === $value) {
            return '<span class="vf-amount">—</span>';
        }

        $rounded = $this->round($this->normalize($value), $decimals);
        $formatted = $this->format($rounded);
        $whole = $formatted;
        $minor = null;
        if (str_contains($formatted, ',')) {
            [$whole, $minor] = explode(',', $formatted, 2);
        }

        $classes = ['vf-amount'];
        if ('income' === $tone) {
            $classes[] = 'vf-amount--income';
        } elseif ('profit' === $tone && $this->isNegative($rounded)) {
            $classes[] = 'vf-amount--loss';
        }

        $html = htmlspecialchars($whole, \ENT_QUOTES);
        if (null !== $minor) {
            $html .= '<span class="vf-amount__minor">,'.htmlspecialchars($minor, \ENT_QUOTES).'</span>';
        }

        return \sprintf('<span class="%s">%s</span>', implode(' ', $classes), $html);
    }

    private function normalize(int|float|string $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Money value must be finite.');
            }

            return number_format($value, 6, '.', '');
        }

        $value = trim($value);
        if (!preg_match('/^-?\d+(\.\d+)?$/D', $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid money value "%s".', $value));
        }

        return $value;
    }

    /**
     * Округление половины от нуля: bcadd отбрасывает разряды сверх scale.
     */
    private function round(string $decimal, int $decimals): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($decimals + 1)), $decimals + 1);

        return bcadd($decimal, $this->isNegative($decimal) ? '-'.$half : $half, $decimals);
    }

    private function format(string $decimal): string
    {
        $negative = $this->isNegative($decimal);
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', self::NBSP, $whole) ?? $whole;

        return ($negative ? self::MINUS : '').$grouped.('' !== $fraction ? ','.$fraction : '');
    }

    private function isNegative(string $decimal): bool
    {
        return -1 === bccomp($decimal, '0', 10);
    }

    private function abs(string $decimal): string
    {
        return ltrim($decimal, '-');
    }
}
