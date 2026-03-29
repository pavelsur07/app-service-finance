<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\MarketplaceAdvertisingCost;
use App\Marketplace\Enum\AdvertisingType;
use App\Marketplace\Enum\MarketplaceType;

final class MarketplaceAdvertisingCostBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_LISTING_ID = '22222222-2222-2222-2222-222222222222';

    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $listingId = self::DEFAULT_LISTING_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private \DateTimeImmutable $date;
    private AdvertisingType $advertisingType = AdvertisingType::CPC;
    private string $amount = '100.00';
    private array $analyticsData = [];
    private string $externalCampaignId = 'campaign-1';
    private ?array $rawData = null;

    private function __construct()
    {
        $this->date = new \DateTimeImmutable('2026-01-15');
    }

    public static function aAdvertisingCost(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->listingId = sprintf('22222222-2222-2222-2222-%012d', $index);
        $clone->externalCampaignId = sprintf('campaign-%d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withListingId(string $listingId): self
    {
        $clone = clone $this;
        $clone->listingId = $listingId;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withDate(\DateTimeImmutable $date): self
    {
        $clone = clone $this;
        $clone->date = $date;

        return $clone;
    }

    public function withAdvertisingType(AdvertisingType $advertisingType): self
    {
        $clone = clone $this;
        $clone->advertisingType = $advertisingType;

        return $clone;
    }

    public function withAmount(string $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;

        return $clone;
    }

    public function withAnalyticsData(array $analyticsData): self
    {
        $clone = clone $this;
        $clone->analyticsData = $analyticsData;

        return $clone;
    }

    public function withExternalCampaignId(string $externalCampaignId): self
    {
        $clone = clone $this;
        $clone->externalCampaignId = $externalCampaignId;

        return $clone;
    }

    public function withRawData(?array $rawData): self
    {
        $clone = clone $this;
        $clone->rawData = $rawData;

        return $clone;
    }

    public function build(): MarketplaceAdvertisingCost
    {
        return new MarketplaceAdvertisingCost(
            companyId: $this->companyId,
            listingId: $this->listingId,
            marketplace: $this->marketplace,
            date: $this->date,
            advertisingType: $this->advertisingType,
            amount: $this->amount,
            analyticsData: $this->analyticsData,
            externalCampaignId: $this->externalCampaignId,
            rawData: $this->rawData,
        );
    }
}
