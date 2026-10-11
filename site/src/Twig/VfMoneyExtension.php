<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Деньги по листу DS-Money («Ваш Финдир», docs/design-system/sheets) для
 * легаси-шаблонов: тысячи — U+00A0, десятичный разделитель — запятая, минус —
 * U+2212. Рубль по умолчанию не выводится: в таблицах он в заголовке колонки.
 */
final class VfMoneyExtension extends AbstractExtension
{
    private const NBSP = "\u{00A0}";
    private const MINUS = "\u{2212}";
    /** DS-Money: символ после числа; для валют без привычного символа — код. */
    private const SYMBOLS = ['RUB' => '₽', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
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
     * KPI и графики (DS-Money): «196,1 тыс. ₽», «1,2 млн ₽» — один знак после
     * запятой, ноль в хвосте отбрасывается. В таблицах суммы не сокращаются.
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

        $formatted = $this->format($this->round($decimal, '' === $unit ? 0 : 1));
        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return implode(self::NBSP, array_filter([$formatted, $unit, $currency], static fn (string $part): bool => '' !== $part));
    }

    /**
     * Сумма для таблицы (DS-Money): копейки приглушены, ноль приглушён.
     * tone: 'income' — зелёный (приход); без tone — основной цвет. Красный для сумм
     * не используется. signed: плюс у положительных — только у приходов в списке
     * операций. currency: код валюты строки; рубль не выводится, остальные —
     * символом после числа, приглушённо.
     */
    public function amount(
        int|float|string|null $value,
        int $decimals = 2,
        ?string $tone = null,
        bool $signed = false,
        ?string $currency = null,
    ): string {
        if (null !== $tone && 'income' !== $tone) {
            throw new \InvalidArgumentException(\sprintf('Unknown amount tone "%s".', $tone));
        }
        if (null === $value) {
            return '<span class="vf-amount">—</span>';
        }

        $rounded = $this->round($this->normalize($value), $decimals);
        $zero = 0 === bccomp($rounded, '0', $decimals);
        $formatted = $this->format($rounded);
        if ($signed && !$zero && !$this->isNegative($rounded)) {
            $formatted = '+'.$formatted;
        }
        $whole = $formatted;
        $minor = null;
        if (str_contains($formatted, ',')) {
            [$whole, $minor] = explode(',', $formatted, 2);
        }

        $classes = ['vf-amount'];
        if ($zero) {
            $classes[] = 'vf-amount--zero';
        } elseif ('income' === $tone) {
            $classes[] = 'vf-amount--income';
        }

        $html = htmlspecialchars($whole, \ENT_QUOTES);
        if (null !== $minor) {
            $html .= '<span class="vf-amount__minor">,'.htmlspecialchars($minor, \ENT_QUOTES).'</span>';
        }
        $currency = null === $currency ? null : strtoupper(trim($currency));
        if (null !== $currency && '' !== $currency && 'RUB' !== $currency) {
            $html .= self::NBSP.'<span class="vf-amount__currency">'.htmlspecialchars(self::SYMBOLS[$currency] ?? $currency, \ENT_QUOTES).'</span>';
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
