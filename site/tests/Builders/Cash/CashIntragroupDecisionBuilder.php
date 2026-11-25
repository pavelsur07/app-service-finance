<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Transaction\CashIntragroupDecision;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Ramsey\Uuid\Uuid;

final class CashIntragroupDecisionBuilder
{
    private ?string $id = null;
    private string $companyId = CompanyBuilder::DEFAULT_COMPANY_ID;
    private IntragroupDecisionType $type = IntragroupDecisionType::PAIR;
    private ?string $outflowId = null;
    private ?string $inflowId = null;

    public static function aDecision(): self
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

    public function withType(IntragroupDecisionType $type): self
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }

    public function withLegs(string $outflowId, string $inflowId): self
    {
        $clone = clone $this;
        $clone->outflowId = $outflowId;
        $clone->inflowId = $inflowId;

        return $clone;
    }

    public function build(): CashIntragroupDecision
    {
        return new CashIntragroupDecision(
            $this->id ?? Uuid::uuid7()->toString(),
            $this->companyId,
            $this->type,
            $this->outflowId ?? Uuid::uuid7()->toString(),
            $this->inflowId ?? Uuid::uuid7()->toString(),
            UserBuilder::DEFAULT_USER_ID,
            new \DateTimeImmutable('2026-11-25 12:00:00'),
        );
    }
}
