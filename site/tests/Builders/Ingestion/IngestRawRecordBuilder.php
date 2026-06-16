<?php

declare(strict_types=1);

namespace App\Tests\Builders\Ingestion;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use Ramsey\Uuid\Uuid;

final class IngestRawRecordBuilder
{
    private string $companyId;
    private string $connectionRef = 'connection-1';
    private string $shopRef = 'shop-1';
    private IngestSource $source = IngestSource::OZON;
    private string $resourceType = 'seller-report';
    private string $externalId = 'external-1';
    private string $storagePath = 'company/ozon/shop/seller-report/2026/06/15/job-1.ndjson.gz';
    private string $hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private int $byteSize = 100;
    private \DateTimeImmutable $fetchedAt;
    private string $syncJobId = 'job-1';

    public function __construct()
    {
        $this->companyId = Uuid::uuid7()->toString();
        $this->fetchedAt = new \DateTimeImmutable('2026-06-15 12:00:00');
    }

    public static function aRawRecord(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $this->companyId = $companyId;

        return $this;
    }

    public function withStoragePath(string $storagePath): self
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function build(): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: $this->companyId,
            connectionRef: $this->connectionRef,
            shopRef: $this->shopRef,
            source: $this->source,
            resourceType: $this->resourceType,
            externalId: $this->externalId,
            storagePath: $this->storagePath,
            hash: $this->hash,
            byteSize: $this->byteSize,
            fetchedAt: $this->fetchedAt,
            syncJobId: $this->syncJobId,
        );
    }
}
