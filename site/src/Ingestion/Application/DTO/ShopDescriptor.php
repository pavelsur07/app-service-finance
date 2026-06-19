<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class ShopDescriptor
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public string $currency,
        public array $metadata = [],
    ) {
        Assert::notEmpty($this->externalId);
        Assert::notEmpty($this->name);
        Assert::regex($this->currency, '/^[A-Z]{3}$/');
    }
}
