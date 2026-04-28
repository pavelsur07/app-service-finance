<?php

declare(strict_types=1);

namespace App\Tests\Builders\Inventory;

use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Marketplace\Enum\MarketplaceType;

final class InventorySnapshotSessionBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_TRIGGERED_BY = '22222222-2222-2222-2222-222222222222';
    public const DEFAULT_CORRELATION_ID = '33333333-3333-7333-8333-333333333333';

    private string $companyId = self::DEFAULT_COMPANY_ID;
    private MarketplaceType $source = MarketplaceType::WILDBERRIES;
    private SnapshotTriggerType $triggerType = SnapshotTriggerType::Manual;
    private ?string $triggeredBy = self::DEFAULT_TRIGGERED_BY;
    private ?int $expectedPages = 10;
    private ?string $correlationId = self::DEFAULT_CORRELATION_ID;

    private function __construct()
    {
    }

    public static function aSession(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withSource(MarketplaceType $source): self
    {
        $clone = clone $this;
        $clone->source = $source;

        return $clone;
    }

    public function withTriggerType(SnapshotTriggerType $triggerType): self
    {
        $clone = clone $this;
        $clone->triggerType = $triggerType;

        return $clone;
    }

    public function withTriggeredBy(?string $triggeredBy): self
    {
        $clone = clone $this;
        $clone->triggeredBy = $triggeredBy;

        return $clone;
    }

    public function withExpectedPages(?int $expectedPages): self
    {
        $clone = clone $this;
        $clone->expectedPages = $expectedPages;

        return $clone;
    }

    public function withCorrelationId(string $correlationId): self
    {
        $clone = clone $this;
        $clone->correlationId = $correlationId;

        return $clone;
    }

    public function build(): InventorySnapshotSession
    {
        return new InventorySnapshotSession(
            companyId: $this->companyId,
            source: $this->source,
            triggerType: $this->triggerType,
            correlationId: $this->correlationId,
            triggeredBy: $this->triggeredBy,
            expectedPages: $this->expectedPages,
        );
    }
}
