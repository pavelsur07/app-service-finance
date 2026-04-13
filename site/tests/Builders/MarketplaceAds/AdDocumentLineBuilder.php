<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAds;

use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;

final class AdDocumentLineBuilder
{
    public const DEFAULT_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    public const DEFAULT_LISTING_ID = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    private string $id = self::DEFAULT_ID;
    private ?AdDocument $adDocument = null;
    private string $adDocumentId = AdDocumentBuilder::DEFAULT_ID;
    private string $listingId = self::DEFAULT_LISTING_ID;
    private string $sharePercent = '100.00';
    private string $cost = '100.00';
    private int $impressions = 1000;
    private int $clicks = 50;

    private function __construct()
    {
    }

    public static function aLine(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('aaaaaaaa-aaaa-aaaa-aaaa-%012d', $index);
        $clone->listingId = sprintf('bbbbbbbb-bbbb-bbbb-bbbb-%012d', $index);

        return $clone;
    }

    public function withAdDocument(AdDocument $adDocument): self
    {
        $clone = clone $this;
        $clone->adDocument = $adDocument;
        $clone->adDocumentId = $adDocument->getId();

        return $clone;
    }

    public function withAdDocumentId(string $adDocumentId): self
    {
        $clone = clone $this;
        $clone->adDocumentId = $adDocumentId;
        // Сбрасываем предварительно выставленный AdDocument — его id нужно привести
        // к новому значению, это делается лениво в build().
        $clone->adDocument = null;

        return $clone;
    }

    public function withListingId(string $listingId): self
    {
        $clone = clone $this;
        $clone->listingId = $listingId;

        return $clone;
    }

    public function withSharePercent(string $sharePercent): self
    {
        $clone = clone $this;
        $clone->sharePercent = $sharePercent;

        return $clone;
    }

    public function withCost(string $cost): self
    {
        $clone = clone $this;
        $clone->cost = $cost;

        return $clone;
    }

    public function withImpressions(int $impressions): self
    {
        $clone = clone $this;
        $clone->impressions = $impressions;

        return $clone;
    }

    public function withClicks(int $clicks): self
    {
        $clone = clone $this;
        $clone->clicks = $clicks;

        return $clone;
    }

    public function build(): AdDocumentLine
    {
        // AdDocumentLine хранит ManyToOne на AdDocument — чистый id туда передать нельзя.
        // Если caller не дал объект через withAdDocument(), собираем минимальный stub.
        $adDocument = $this->adDocument ?? AdDocumentBuilder::aDocument()->build();

        if ($adDocument->getId() !== $this->adDocumentId) {
            $adDocIdProperty = new \ReflectionProperty(AdDocument::class, 'id');
            $adDocIdProperty->setAccessible(true);
            $adDocIdProperty->setValue($adDocument, $this->adDocumentId);
        }

        $line = new AdDocumentLine(
            adDocument: $adDocument,
            listingId: $this->listingId,
            sharePercent: $this->sharePercent,
            cost: $this->cost,
            impressions: $this->impressions,
            clicks: $this->clicks,
        );

        $idProperty = new \ReflectionProperty(AdDocumentLine::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($line, $this->id);

        return $line;
    }
}
