<?php

declare(strict_types=1);

namespace App\Tests\Builders\Finance;

use App\Company\Enum\BusinessProfile;
use App\Finance\Entity\PLTemplateApplication;
use App\Finance\Enum\PLTemplateApplicationSource;

final class PLTemplateApplicationBuilder
{
    private string $companyId = '11111111-1111-4111-8111-111111111111';
    private BusinessProfile $profile = BusinessProfile::MARKETPLACE;
    private \DateTimeImmutable $appliedAt;

    private function __construct()
    {
        $this->appliedAt = new \DateTimeImmutable('2026-10-21 10:00:00');
    }

    public static function anApplication(): self
    {
        return new self();
    }

    public function forCompany(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withProfile(BusinessProfile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;

        return $clone;
    }

    public function appliedAt(\DateTimeImmutable $appliedAt): self
    {
        $clone = clone $this;
        $clone->appliedAt = $appliedAt;

        return $clone;
    }

    public function build(): PLTemplateApplication
    {
        return new PLTemplateApplication($this->companyId, $this->profile, 1, 72, PLTemplateApplicationSource::ONBOARDING, $this->appliedAt);
    }
}
