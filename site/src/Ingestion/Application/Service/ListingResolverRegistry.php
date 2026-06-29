<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\ListingResolution;
use App\Ingestion\Domain\Contract\BulkListingResolverInterface;
use App\Ingestion\Domain\Contract\ListingResolverInterface;
use App\Ingestion\Enum\IngestSource;
use Psr\Log\LoggerInterface;

final class ListingResolverRegistry
{
    /** @var list<ListingResolverInterface> */
    private array $resolvers;

    /**
     * @param iterable<ListingResolverInterface> $resolvers
     */
    public function __construct(iterable $resolvers, private readonly LoggerInterface $logger)
    {
        $this->resolvers = $resolvers instanceof \Traversable ? iterator_to_array($resolvers, false) : array_values($resolvers);
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    public function resolve(IngestSource $source, string $companyId, array $sourceData): ?ListingResolution
    {
        foreach ($this->resolvers as $resolver) {
            if (!$resolver->supports($source)) {
                continue;
            }

            try {
                return $resolver->resolve($companyId, $sourceData);
            } catch (\Throwable $exception) {
                $this->logger->warning('Ingestion listing resolver failed.', [
                    'companyId' => $companyId,
                    'source' => $source->value,
                    'resolver' => $resolver::class,
                    'exceptionClass' => $exception::class,
                    'errorMessage' => $exception->getMessage(),
                ]);

                return null;
            }
        }

        $this->logger->warning('No ingestion listing resolver registered for source.', [
            'companyId' => $companyId,
            'source' => $source->value,
        ]);

        return null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $sourceDataRows
     *
     * @return array<int|string, ListingResolution|null>
     */
    public function resolveMany(IngestSource $source, string $companyId, array $sourceDataRows): array
    {
        foreach ($this->resolvers as $resolver) {
            if (!$resolver->supports($source)) {
                continue;
            }

            try {
                if ($resolver instanceof BulkListingResolverInterface) {
                    return $resolver->resolveMany($companyId, $sourceDataRows);
                }

                $result = [];
                foreach ($sourceDataRows as $key => $sourceData) {
                    $result[$key] = $resolver->resolve($companyId, $sourceData);
                }

                return $result;
            } catch (\Throwable $exception) {
                $this->logger->warning('Ingestion listing resolver failed.', [
                    'companyId' => $companyId,
                    'source' => $source->value,
                    'resolver' => $resolver::class,
                    'exceptionClass' => $exception::class,
                    'errorMessage' => $exception->getMessage(),
                ]);

                return array_fill_keys(array_keys($sourceDataRows), null);
            }
        }

        $this->logger->warning('No ingestion listing resolver registered for source.', [
            'companyId' => $companyId,
            'source' => $source->value,
        ]);

        return array_fill_keys(array_keys($sourceDataRows), null);
    }
}
