<?php

declare(strict_types=1);

namespace App\Tests\Builders\Billing;

use App\Billing\Entity\BillingExpiryReminder;
use App\Tests\Builders\Company\CompanyBuilder;

final class BillingExpiryReminderBuilder
{
    public const DEFAULT_ID = '01930000-0000-7000-8000-00000000b301';

    private string $id = self::DEFAULT_ID;
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private string $paidUntil = '2027-01-31';
    private int $daysBefore = 7;

    public static function aReminder(): self
    {
        return new self();
    }

    public function forCompany(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function before(string $paidUntil, int $daysBefore): self
    {
        $clone = clone $this;
        $clone->paidUntil = $paidUntil;
        $clone->daysBefore = $daysBefore;

        return $clone;
    }

    public function build(): BillingExpiryReminder
    {
        return new BillingExpiryReminder($this->companyId, new \DateTimeImmutable($this->paidUntil), $this->daysBefore, new \DateTimeImmutable('2027-01-24 10:00:00'), $this->id);
    }
}
