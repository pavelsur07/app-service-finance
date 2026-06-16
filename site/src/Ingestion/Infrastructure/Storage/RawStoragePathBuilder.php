<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Storage;

use App\Ingestion\DTO\RawBatch;

final readonly class RawStoragePathBuilder
{
    public function __construct(private PathSegmentNormalizer $pathSegmentNormalizer)
    {
    }

    public function build(RawBatch $batch, string $hash): string
    {
        return sprintf(
            '%s/%s/%s/%s/%s/%s/%s/%s/%s/%s.ndjson.gz',
            $this->pathSegmentNormalizer->normalize($batch->companyId),
            $this->pathSegmentNormalizer->normalize($batch->source->value),
            $this->pathSegmentNormalizer->normalize($batch->shopRef),
            $this->pathSegmentNormalizer->normalize($batch->resourceType),
            $batch->fetchedAt->format('Y'),
            $batch->fetchedAt->format('m'),
            $batch->fetchedAt->format('d'),
            $this->pathSegmentNormalizer->normalize($batch->syncJobId),
            $this->pathSegmentNormalizer->normalize($batch->externalId),
            $this->pathSegmentNormalizer->normalize($hash),
        );
    }
}
