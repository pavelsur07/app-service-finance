<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Normalizer;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class RowClassifierRegistry implements RowClassifierRegistryInterface
{
    /** @var RowClassifierInterface[] */
    private array $classifiers;

    /** @param iterable<RowClassifierInterface> $classifiers */
    public function __construct(#[TaggedIterator('marketplace.row_classifier')] iterable $classifiers)
    {
        $this->classifiers = is_array($classifiers) ? $classifiers : iterator_to_array($classifiers, false);
    }

    public function get(MarketplaceType $type): RowClassifierInterface
    {
        foreach ($this->classifiers as $classifier) {
            if ($classifier->supports($type)) {
                return $classifier;
            }
        }

        throw new \RuntimeException(sprintf('No row classifier for marketplace: %s', $type->value));
    }
}
