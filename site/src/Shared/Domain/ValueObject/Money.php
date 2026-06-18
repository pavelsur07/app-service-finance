<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\MoneyMismatchException;
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

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new MoneyMismatchException(sprintf('Cannot operate on %s and %s money values.', $this->currency, $other->currency));
        }
    }
}
