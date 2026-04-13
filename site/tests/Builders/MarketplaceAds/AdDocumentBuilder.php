<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;

final class AdDocumentBuilder
{
    public const DEFAULT_ID = '99999999-9999-9999-9999-999999999999';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_RAW_DOCUMENT_ID = '88888888-8888-8888-8888-888888888888';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private \DateTimeImmutable $reportDate;
    private string $campaignId = 'CAMP-001';
    private string $campaignName = 'Default campaign';
    private string $parentSku = 'SKU-001';
    private string $totalCost = '100.00';
    private int $totalImpressions = 1000;
    private int $totalClicks = 50;
    private string $adRawDocumentId = self::DEFAULT_RAW_DOCUMENT_ID;

    private function __construct()
    {
        $this->reportDate = new \DateTimeImmutable('2025-01-15');
    }

    public static function aDocument(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('99999999-9999-9999-9999-%012d', $index);
        $clone->campaignId = sprintf('CAMP-%03d', $index);
        $clone->parentSku = sprintf('SKU-%03d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withCampaignId(string $campaignId): self
    {
        $clone = clone $this;
        $clone->campaignId = $campaignId;

        return $clone;
    }

    public function withParentSku(string $parentSku): self
    {
        $clone = clone $this;
        $clone->parentSku = $parentSku;

        return $clone;
    }

    public function withTotalCost(string $totalCost): self
    {
        $clone = clone $this;
        $clone->totalCost = $totalCost;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withReportDate(\DateTimeImmutable $reportDate): self
    {
        $clone = clone $this;
        $clone->reportDate = $reportDate;

        return $clone;
    }

    public function withCampaignName(string $campaignName): self
    {
        $clone = clone $this;
        $clone->campaignName = $campaignName;

        return $clone;
    }

    public function withTotalImpressions(int $totalImpressions): self
    {
        $clone = clone $this;
        $clone->totalImpressions = $totalImpressions;

        return $clone;
    }

    public function withTotalClicks(int $totalClicks): self
    {
        $clone = clone $this;
        $clone->totalClicks = $totalClicks;

        return $clone;
    }

    public function withAdRawDocumentId(string $adRawDocumentId): self
    {
        $clone = clone $this;
        $clone->adRawDocumentId = $adRawDocumentId;

        return $clone;
    }

    public function build(): AdDocument
    {
        $doc = new AdDocument(
            companyId: $this->companyId,
            marketplace: $this->marketplace,
            reportDate: $this->reportDate,
            campaignId: $this->campaignId,
            campaignName: $this->campaignName,
            parentSku: $this->parentSku,
            totalCost: $this->totalCost,
            totalImpressions: $this->totalImpressions,
            totalClicks: $this->totalClicks,
            adRawDocumentId: $this->adRawDocumentId,
        );

        // Конструктор сам генерирует UUID v7 — перезаписываем на детерминированный DEFAULT_ID.
        $idProperty = new \ReflectionProperty(AdDocument::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($doc, $this->id);

        return $doc;
    }
}
