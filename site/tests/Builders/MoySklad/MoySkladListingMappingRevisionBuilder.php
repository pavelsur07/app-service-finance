<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Entity\MoySkladListingMappingRevision;
use App\MoySklad\Enum\ListingMappingOperation;
use Ramsey\Uuid\Uuid;

final class MoySkladListingMappingRevisionBuilder
{
    private string $companyId = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private string $connectionId = MoySkladConnectionBuilder::DEFAULT_ID;
    private string $listingId = '55555555-5555-4555-8555-000000000001';
    private int $revision = 1;
    private ?string $targetType = 'product';
    private ?string $targetId = '44444444-4444-4444-8444-000000000001';
    private ?string $requestId = null;

    public static function aRevision(): self
    {
        return new self();
    }

    public function withTenant(string $companyId, string $connectionId): self
    {
        $copy = clone $this;
        $copy->companyId = $companyId;
        $copy->connectionId = $connectionId;

        return $copy;
    }

    public function forListing(string $listingId): self
    {
        $copy = clone $this;
        $copy->listingId = $listingId;

        return $copy;
    }

    public function withRevision(int $revision): self
    {
        $copy = clone $this;
        $copy->revision = $revision;

        return $copy;
    }

    public function withTarget(string $type, string $id): self
    {
        $copy = clone $this;
        $copy->targetType = $type;
        $copy->targetId = $id;

        return $copy;
    }

    public function withRequestId(string $id): self
    {
        $copy = clone $this;
        $copy->requestId = $id;

        return $copy;
    }

    public function clear(): self
    {
        $copy = clone $this;
        $copy->targetType = null;
        $copy->targetId = null;

        return $copy;
    }

    public function build(): MoySkladListingMappingRevision
    {
        return new MoySkladListingMappingRevision(
            Uuid::uuid7()->toString(), $this->companyId, $this->connectionId, $this->listingId, $this->revision,
            null === $this->targetType ? ListingMappingOperation::CLEAR : ListingMappingOperation::SET,
            $this->targetType, $this->targetId, $this->requestId ?? Uuid::uuid7()->toString(),
            '22222222-2222-4222-8222-000000000001', $this->revision - 1, new \DateTimeImmutable('2026-11-15T00:00:00.123+00:00'),
            ['id' => $this->listingId, 'marketplace' => 'ozon', 'sku' => '001', 'supplierSku' => '001', 'size' => null, 'variantId' => null, 'title' => 'Листинг'],
            null === $this->targetType ? null : ['type' => $this->targetType, 'externalId' => $this->targetId, 'parentExternalId' => null, 'name' => 'Товар', 'code' => 'CODE', 'article' => '001', 'characteristics' => []],
        );
    }
}
