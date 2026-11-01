<?php

declare(strict_types=1);

namespace App\Tests\Builders\Billing;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Entity\Tariff;
use App\Tests\Builders\Company\CompanyBuilder;

final class CompanySubscriptionBuilder
{
    public const DEFAULT_ID = '01930000-0000-7000-8000-00000000b101';

    private string $id = self::DEFAULT_ID;
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private ?Tariff $tariff = null;
    private string $paidFrom = '2027-01-01';
    private ?string $paidUntil = '2027-01-31';

    public static function aSubscription(): self
    {
        return new self();
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

    public function paid(string $from, string $until): self
    {
        $clone = clone $this;
        $clone->paidFrom = $from;
        $clone->paidUntil = $until;

        return $clone;
    }

    /** Бессрочная подписка; без onTariff() — на бессрочном тарифе из TariffBuilder. */
    public function perpetualFrom(string $from): self
    {
        $clone = clone $this;
        $clone->paidFrom = $from;
        $clone->paidUntil = null;

        return $clone;
    }

    public function build(): CompanySubscription
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        if (null === $this->paidUntil) {
            $tariff = $this->tariff ?? TariffBuilder::aTariff()->perpetual()->build();

            return CompanySubscription::startPerpetual($this->companyId, $tariff, new \DateTimeImmutable($this->paidFrom), $now, $this->id);
        }

        $tariff = $this->tariff ?? TariffBuilder::aTariff()->build();
        $period = new PaidPeriod(new \DateTimeImmutable($this->paidFrom), new \DateTimeImmutable($this->paidUntil));

        return CompanySubscription::startPaid($this->companyId, $tariff, $period, $now, $this->id);
    }
}
