<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Contract\MarketplaceFetcherInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class MarketplaceFetcherRegistry
{
    /**
     * @var array<int, MarketplaceFetcherInterface>
     */
    private array $fetchers;

    /**
     * @param iterable<MarketplaceFetcherInterface> $fetchers
     */
    public function __construct(
        #[TaggedIterator('marketplace.fetcher')]
        iterable $fetchers,
    ) {
        $this->fetchers = $fetchers instanceof \Traversable ? iterator_to_array($fetchers, false) : (array) $fetchers;
    }

    public function get(MarketplaceType $type): MarketplaceFetcherInterface
    {
        foreach ($this->fetchers as $fetcher) {
            if ($fetcher->supports($type)) {
                return $fetcher;
            }
        }

        throw new \RuntimeException(sprintf('Marketplace fetcher is not configured for type "%s".', $type->value));
    }
}
