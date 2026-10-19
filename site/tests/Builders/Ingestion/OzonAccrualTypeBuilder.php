<?php

declare(strict_types=1);

namespace App\Tests\Builders\Ingestion;

use App\Ingestion\Entity\OzonAccrualType;
use Ramsey\Uuid\Uuid;

final class OzonAccrualTypeBuilder
{
    private int $typeId = 29;
    private string $name = 'LastMileCourier';
    private ?string $description = 'Доставка до места выдачи';
    private string $rawRecordId;
    private \DateTimeImmutable $versionFetchedAt;

    public function __construct()
    {
        $this->rawRecordId = Uuid::uuid7()->toString();
        $this->versionFetchedAt = new \DateTimeImmutable('2026-10-08 02:30:00');
    }

    public static function aType(): self
    {
        return new self();
    }

    public function withTypeId(int $typeId): self
    {
        $this->typeId = $typeId;

        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function withDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function withRawRecordId(string $rawRecordId): self
    {
        $this->rawRecordId = $rawRecordId;

        return $this;
    }

    public function withVersionFetchedAt(\DateTimeImmutable $versionFetchedAt): self
    {
        $this->versionFetchedAt = $versionFetchedAt;

        return $this;
    }

    public function build(): OzonAccrualType
    {
        return new OzonAccrualType(
            typeId: $this->typeId,
            name: $this->name,
            description: $this->description,
            rawRecordId: $this->rawRecordId,
            versionFetchedAt: $this->versionFetchedAt,
        );
    }
}
