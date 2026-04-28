<?php

declare(strict_types=1);

namespace App\Tests\Builders\Inventory;

use App\Inventory\Entity\Location;
use App\Inventory\Enum\LocationType;
use App\Marketplace\Enum\MarketplaceType;

final class LocationBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $companyId = self::DEFAULT_COMPANY_ID;
    private LocationType $type = LocationType::MpWarehouse;
    private MarketplaceType $externalSystem = MarketplaceType::WILDBERRIES;
    private ?string $externalId = 'ext-001';
    private string $code = 'LOC-001';
    private string $name = 'Main Warehouse';
    private bool $isActive = true;
    private ?array $metadata = ['source' => 'builder'];

    private function __construct()
    {
    }

    public static function aLocation(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->externalId = sprintf('ext-%03d', $index);
        $clone->code = sprintf('LOC-%03d', $index);
        $clone->name = sprintf('Location %d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withExternalId(?string $externalId): self
    {
        $clone = clone $this;
        $clone->externalId = $externalId;

        return $clone;
    }

    public function withCode(string $code): self
    {
        $clone = clone $this;
        $clone->code = $code;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withMetadata(?array $metadata): self
    {
        $clone = clone $this;
        $clone->metadata = $metadata;

        return $clone;
    }

    public function inactive(): self
    {
        $clone = clone $this;
        $clone->isActive = false;

        return $clone;
    }

    public function build(): Location
    {
        $location = new Location(
            companyId: $this->companyId,
            type: $this->type,
            externalSystem: $this->externalSystem,
            code: $this->code,
            name: $this->name,
            externalId: $this->externalId,
            metadata: $this->metadata,
        );

        if (!$this->isActive) {
            $location->setIsActive(false);
        }

        return $location;
    }
}
