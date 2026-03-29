<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\ValueObject;

use Webmozart\Assert\Assert;

final readonly class AdvertisingOtherMetrics
{
    public function __construct(
        public string $spend,
        public array $details,
    ) {
        Assert::greaterThanEq((float) $spend, 0.0);
    }

    public function toArray(): array
    {
        return [
            'spend' => $this->spend,
            'details' => $this->details,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            spend: $data['spend'] ?? '0.00',
            details: $data['details'] ?? [],
        );
    }

    public function isEmpty(): bool
    {
        return bccomp($this->spend, '0.00', 2) === 0;
    }

    public static function empty(): self
    {
        return new self(
            spend: '0.00',
            details: [],
        );
    }
}
