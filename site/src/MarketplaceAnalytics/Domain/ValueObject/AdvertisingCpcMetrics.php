<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\ValueObject;

use Webmozart\Assert\Assert;

final readonly class AdvertisingCpcMetrics
{
    public function __construct(
        public string $spend,
        public int $impressions,
        public int $clicks,
        public float $ctr,
        public string $cpc,
        public string $cpm,
        public int $orders,
        public string $cpo,
        public string $revenue,
        public float $cr,
        public string $acos,
    ) {
        Assert::greaterThanEq((float) $spend, 0.0);
        Assert::greaterThanEq($impressions, 0);
        Assert::greaterThanEq($clicks, 0);
        Assert::greaterThanEq($orders, 0);
    }

    public function toArray(): array
    {
        return [
            'spend' => $this->spend,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'ctr' => $this->ctr,
            'cpc' => $this->cpc,
            'cpm' => $this->cpm,
            'orders' => $this->orders,
            'cpo' => $this->cpo,
            'revenue' => $this->revenue,
            'cr' => $this->cr,
            'acos' => $this->acos,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            spend: $data['spend'] ?? '0.00',
            impressions: $data['impressions'] ?? 0,
            clicks: $data['clicks'] ?? 0,
            ctr: $data['ctr'] ?? 0.0,
            cpc: $data['cpc'] ?? '0.00',
            cpm: $data['cpm'] ?? '0.00',
            orders: $data['orders'] ?? 0,
            cpo: $data['cpo'] ?? '0.00',
            revenue: $data['revenue'] ?? '0.00',
            cr: $data['cr'] ?? 0.0,
            acos: $data['acos'] ?? '0.00',
        );
    }

    public function isEmpty(): bool
    {
        return bccomp($this->spend, '0.00', 2) === 0
            && $this->impressions === 0
            && $this->clicks === 0;
    }

    public static function empty(): self
    {
        return new self(
            spend: '0.00',
            impressions: 0,
            clicks: 0,
            ctr: 0.0,
            cpc: '0.00',
            cpm: '0.00',
            orders: 0,
            cpo: '0.00',
            revenue: '0.00',
            cr: 0.0,
            acos: '0.00',
        );
    }
}
