<?php

declare(strict_types=1);

namespace App\Balance\ReadModel;

use App\Balance\DTO\BalanceRowView;
use App\Shared\Domain\ValueObject\Money;

final class BalanceReport
{
    /**
     * @param list<string> $currencies
     * @param list<BalanceRowView> $roots
     * @param array<string, string> $totals currency => asset decimal string
     * @param array<string, string> $passiveTotals
     * @param array<string, string> $differences
     */
    public function __construct(
        private \DateTimeImmutable $date,
        private array $currencies,
        private array $roots,
        private array $totals,
        private array $passiveTotals = [],
        private array $differences = [],
        private bool $initialized = false,
    ) {
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return list<string>
     */
    public function getCurrencies(): array
    {
        return $this->currencies;
    }

    /**
     * @return list<BalanceRowView>
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * @return array<string, string>
     */
    public function getTotals(): array
    {
        return $this->totals;
    }

    /** @return array<string, string> */
    public function getAssetTotals(): array
    {
        return $this->totals;
    }

    /** @return array<string, string> */
    public function getPassiveTotals(): array
    {
        return $this->passiveTotals;
    }

    /** @return array<string, string> */
    public function getDifferences(): array
    {
        return $this->differences;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getCurrency(): ?string
    {
        return $this->currencies[0] ?? null;
    }

    public function getAssetMinor(): string
    {
        $currency = $this->getCurrency();

        return null === $currency ? '0' : (string) Money::fromString($this->totals[$currency] ?? '0', $currency)->amountMinor();
    }

    public function getPassiveMinor(): string
    {
        $currency = $this->getCurrency();

        return null === $currency ? '0' : (string) Money::fromString($this->passiveTotals[$currency] ?? '0', $currency)->amountMinor();
    }

    public function getDifferenceMinor(): string
    {
        $currency = $this->getCurrency();

        return null === $currency ? '0' : (string) Money::fromString($this->differences[$currency] ?? '0', $currency)->amountMinor();
    }
}
