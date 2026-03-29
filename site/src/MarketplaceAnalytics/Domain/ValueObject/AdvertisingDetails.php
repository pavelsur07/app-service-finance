<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\ValueObject;

final readonly class AdvertisingDetails
{
    public function __construct(
        public AdvertisingCpcMetrics $cpc,
        public AdvertisingOtherMetrics $other,
    ) {}

    public function totalSpend(): string
    {
        return bcadd($this->cpc->spend, $this->other->spend, 2);
    }

    public function toArray(): array
    {
        return [
            'cpc' => $this->cpc->toArray(),
            'other' => $this->other->toArray(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            cpc: AdvertisingCpcMetrics::fromArray($data['cpc'] ?? []),
            other: AdvertisingOtherMetrics::fromArray($data['other'] ?? []),
        );
    }

    public static function empty(): self
    {
        return new self(
            AdvertisingCpcMetrics::empty(),
            AdvertisingOtherMetrics::empty(),
        );
    }
}
