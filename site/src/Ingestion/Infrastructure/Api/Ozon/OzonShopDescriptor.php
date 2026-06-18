<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use Webmozart\Assert\Assert;

final readonly class OzonShopDescriptor
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public string $currency = 'RUB',
        public array $metadata = [],
    ) {
        Assert::notEmpty($this->externalId);
        Assert::notEmpty($this->name);
        Assert::regex($this->currency, '/^[A-Z]{3}$/');
    }
}
