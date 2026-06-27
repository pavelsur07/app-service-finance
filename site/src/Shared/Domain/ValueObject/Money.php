<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\MoneyMismatchException;
use Symfony\Component\Intl\Currencies;
use Webmozart\Assert\Assert;

final readonly class Money
{
    private function __construct(
        private int $amountMinor,
        private string $currency,
    ) {
        Assert::regex($this->currency, '/^[A-Z]{3}$/');
    }

    public static function fromMinor(int $amountMinor, string $currency): self
    {
        return new self($amountMinor, strtoupper($currency));
    }

    /**
     * Парсит decimal-строку («123.45», «1 234,56») в минорные единицы валюты.
     *
     * Масштаб определяется по валюте (RUB=2, JPY=0, BHD=3) через Intl,
     * округление — half-up. Арифметика через bcmath, без float.
     */
    public static function fromString(string $decimal, string $currency): self
    {
        $currency = strtoupper(trim($currency));

        $normalized = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($decimal));
        Assert::regex($normalized, '/^-?\d+(\.\d+)?$/');

        $scale = self::fractionDigits($currency);
        $scaled = \bcmul($normalized, \bcpow('10', (string) $scale), 12);
        $minor = RoundingMode::HALF_UP->roundToInteger($scaled);

        return new self((int) $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amountMinor, $this->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <=> $other->amountMinor;
    }

    public function isZero(): bool
    {
        return 0 === $this->amountMinor;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function abs(): self
    {
        return $this->amountMinor < 0 ? new self(-$this->amountMinor, $this->currency) : $this;
    }

    /**
     * Умножает сумму на произвольный множитель (decimal-строка), округляя результат
     * до целых минорных единиц. Валюта сохраняется.
     */
    public function multiply(string $factor, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        $product = \bcmul((string) $this->amountMinor, $factor, 12);

        return new self((int) $mode->roundToInteger($product), $this->currency);
    }

    /**
     * Возвращает указанный процент от суммы (например percentage('20') — 20%).
     */
    public function percentage(string $percent, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        $value = \bcdiv(\bcmul((string) $this->amountMinor, $percent, 12), '100', 12);

        return new self((int) $mode->roundToInteger($value), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Представляет сумму как decimal-строку с числом знаков по валюте («12345» RUB → «123.45»).
     */
    public function toDecimalString(): string
    {
        $scale = self::fractionDigits($this->currency);
        if (0 === $scale) {
            return (string) $this->amountMinor;
        }

        return \bcdiv((string) $this->amountMinor, \bcpow('10', (string) $scale), $scale);
    }

    private static function fractionDigits(string $currency): int
    {
        try {
            return Currencies::getFractionDigits($currency);
        } catch (\Throwable) {
            return 2;
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new MoneyMismatchException(sprintf('Cannot operate on %s and %s money values.', $this->currency, $other->currency));
        }
    }
}
