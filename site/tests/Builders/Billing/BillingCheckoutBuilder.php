<?php

declare(strict_types=1);

namespace App\Tests\Builders\Billing;

use App\Billing\Entity\BillingCheckout;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Billing\YooKassaResponses;

final class BillingCheckoutBuilder
{
    public const DEFAULT_ID = '01930000-0000-7000-8000-00000000b501';

    private string $id = self::DEFAULT_ID;
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private ?Tariff $tariff = null;
    private int $months = 1;
    private int $amountKopecks = 497000;
    private ?string $providerPaymentId = YooKassaResponses::PAYMENT_ID;
    private string $createdAt = '2027-03-10 10:00:00';

    public static function aCheckout(): self
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

    public function onTariff(Tariff $tariff, int $months, int $amountKopecks): self
    {
        $clone = clone $this;
        $clone->tariff = $tariff;
        $clone->months = $months;
        $clone->amountKopecks = $amountKopecks;

        return $clone;
    }

    public function withProviderPayment(?string $providerPaymentId): self
    {
        $clone = clone $this;
        $clone->providerPaymentId = $providerPaymentId;

        return $clone;
    }

    public function createdAt(string $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): BillingCheckout
    {
        $now = new \DateTimeImmutable($this->createdAt);
        $tariff = $this->tariff ?? TariffBuilder::aTariff()->withCode(TariffCode::START, 'Старт')->build();
        $checkout = new BillingCheckout($this->id, $this->companyId, $tariff, $this->months, $this->amountKopecks, $now);
        if (null !== $this->providerPaymentId) {
            $checkout->attachProviderPayment($this->providerPaymentId, 'https://yoomoney.ru/checkout/payments/v2/contract?orderId='.$this->providerPaymentId, $now);
        }

        return $checkout;
    }
}
