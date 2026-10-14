<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\MoneyMismatchException;
use App\Shared\Domain\Exception\MoneyOverflowException;
use App\Shared\Infrastructure\Doctrine\MoneyAmountType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Intl\Currencies;
use Webmozart\Assert\Assert;

#[ORM\Embeddable]
final readonly class Money
{
    private function __construct(
        #[ORM\Column(type: MoneyAmountType::NAME)]
        private int $amountMinor,
        #[ORM\Column(type: Types::STRING, length: 3)]
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

        return self::createSafe($minor, $currency);
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
        if (\PHP_INT_MIN === $this->amountMinor) {
            throw new MoneyOverflowException('Money amount overflows integer limits.');
        }

        return $this->amountMinor < 0 ? new self(-$this->amountMinor, $this->currency) : $this;
    }

    /**
     * Умножает сумму на произвольный множитель (decimal-строка), округляя результат
     * до целых минорных единиц. Валюта сохраняется.
     */
    public function multiply(string $factor, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        Assert::regex($factor, '/^-?\d+(\.\d+)?$/', 'Factor must be a valid decimal string.');

        $product = \bcmul((string) $this->amountMinor, $factor, 12);

        return self::createSafe($mode->roundToInteger($product), $this->currency);
    }

    /**
     * Возвращает указанный процент от суммы (например percentage('20') — 20%).
     */
    public function percentage(string $percent, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        Assert::regex($percent, '/^-?\d+(\.\d+)?$/', 'Percent must be a valid decimal string.');

        $value = \bcdiv(\bcmul((string) $this->amountMinor, $percent, 12), '100', 12);

        return self::createSafe($mode->roundToInteger($value), $this->currency);
    }

    /**
     * Умножает сумму на дробь numerator/denominator с одним округлением в конце.
     *
     * Для коэффициентов с бесконечной десятичной записью, где `multiply()` с
     * заранее посчитанным множителем потерял бы точность: НДС «из суммы»
     * `gross × rate / (100 + rate)` — `multiplyByRatio('5', '105')`.
     * Числитель и знаменатель — decimal-строки не более чем с 12 знаками после
     * точки (промежуточный scale BCMath — 12), знаменатель не равен нулю.
     * Округление точное при |знаменателе| < 10^12: усечение частного до 12
     * знаков не может перенести его через половину минорной единицы.
     */
    public function multiplyByRatio(string $numerator, string $denominator, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        Assert::regex($numerator, '/^-?\d+(\.\d{1,12})?$/', 'Numerator must be a decimal string with at most 12 fractional digits.');
        Assert::regex($denominator, '/^-?\d+(\.\d{1,12})?$/', 'Denominator must be a decimal string with at most 12 fractional digits.');
        Assert::true(0 !== \bccomp($denominator, '0', 12), 'Denominator must not be zero.');

        $value = \bcdiv(\bcmul((string) $this->amountMinor, $numerator, 12), $denominator, 12);

        return self::createSafe($mode->roundToInteger($value), $this->currency);
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

    /**
     * Создаёт Money из строкового значения минорных единиц, защищаясь от тихого
     * переполнения int: `(int)` для строки вне диапазона PHP_INT_MAX/MIN молча
     * оборачивается (в т.ч. в отрицательное значение), что недопустимо в деньгах.
     */
    private static function createSafe(string $amountMinor, string $currency): self
    {
        if (\bccomp($amountMinor, (string) \PHP_INT_MAX, 0) > 0 || \bccomp($amountMinor, (string) \PHP_INT_MIN, 0) < 0) {
            throw new MoneyOverflowException('Money amount overflows integer limits.');
        }

        return new self((int) $amountMinor, $currency);
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
