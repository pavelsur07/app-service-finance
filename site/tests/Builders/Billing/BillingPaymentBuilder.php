<?php

declare(strict_types=1);

namespace App\Tests\Builders\Billing;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\PaymentSource;
use App\Tests\Builders\Company\CompanyBuilder;

final class BillingPaymentBuilder
{
    public const DEFAULT_ID = '01930000-0000-7000-8000-00000000b201';

    private string $id = self::DEFAULT_ID;
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private ?Tariff $tariff = null;
    private int $months = 1;
    private int $amountKopecks = 497000;
    private ?string $externalId = null;
    private string $periodFrom = '2027-01-01';
    private string $periodUntil = '2027-01-31';
    private int $carriedDays = 0;
    private int $carriedKopecks = 0;

    public static function aPayment(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function forCompany(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function onTariff(Tariff $tariff): self
    {
        $clone = clone $this;
        $clone->tariff = $tariff;

        return $clone;
    }

    public function withAmount(int $months, int $amountKopecks): self
    {
        $clone = clone $this;
        $clone->months = $months;
        $clone->amountKopecks = $amountKopecks;

        return $clone;
    }

    public function withExternalId(?string $externalId): self
    {
        $clone = clone $this;
        $clone->externalId = $externalId;

        return $clone;
    }

    public function forPeriod(string $from, string $until): self
    {
        $clone = clone $this;
        $clone->periodFrom = $from;
        $clone->periodUntil = $until;

        return $clone;
    }

    public function withCarried(int $days, int $kopecks): self
    {
        $clone = clone $this;
        $clone->carriedDays = $days;
        $clone->carriedKopecks = $kopecks;

        return $clone;
    }

    public function build(): BillingPayment
    {
        return new BillingPayment(
            $this->id,
            $this->companyId,
            $this->tariff ?? TariffBuilder::aTariff()->build(),
            $this->months,
            $this->amountKopecks,
            new \DateTimeImmutable('2027-01-01 15:00:00'),
            new PaidPeriod(new \DateTimeImmutable($this->periodFrom), new \DateTimeImmutable($this->periodUntil)),
            PaymentSource::MANUAL_INVOICE,
            $this->externalId,
            new \DateTimeImmutable('2027-01-01 15:00:01'),
            $this->carriedDays,
            $this->carriedKopecks,
        );
    }
}
