<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\ListingResolution;
use App\Ingestion\Domain\Contract\ListingResolverInterface;
use App\Ingestion\Enum\IngestSource;
use Psr\Log\LoggerInterface;

final readonly class WbListingResolver implements ListingResolverInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function supports(IngestSource $source): bool
    {
        return IngestSource::WILDBERRIES === $source;
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    public function resolve(string $companyId, array $sourceData): ?ListingResolution
    {
        $this->logger->warning('Wildberries ingestion listing resolver is not implemented yet.', [
            'companyId' => $companyId,
        ]);

        return null;
    }
}
