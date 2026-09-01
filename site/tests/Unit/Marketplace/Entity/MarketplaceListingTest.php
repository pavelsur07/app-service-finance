<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Entity;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;

final class MarketplaceListingTest extends TestCase
{
    public function testMarketplaceVariantIdCanBeSet(): void
    {
        $listing = $this->createListing();

        $listing->setMarketplaceVariantId(' 123456 ');

        self::assertSame('123456', $listing->getMarketplaceVariantId());
    }

    public function testEmptyMarketplaceVariantIdIsStoredAsNull(): void
    {
        $listing = $this->createListing();

        $listing->setMarketplaceVariantId('  ');

        self::assertNull($listing->getMarketplaceVariantId());
    }

    public function testMarketplaceVariantIdLongerThanColumnLimitIsRejected(): void
    {
        $listing = $this->createListing();

        $this->expectException(\InvalidArgumentException::class);

        $listing->setMarketplaceVariantId(str_repeat('1', 101));
    }

    public function testMarketplaceCreatedAtIsNullByDefault(): void
    {
        $listing = $this->createListing();

        self::assertNull($listing->getMarketplaceCreatedAt());
    }

    public function testMarketplaceCreatedAtKeepsTheDateTheProductWasCreatedOnTheMarketplace(): void
    {
        $listing = $this->createListing();
        $ozonCreatedAt = new \DateTimeImmutable('2021-08-24T14:15:19+00:00');

        $listing->setMarketplaceCreatedAt($ozonCreatedAt);

        self::assertSame($ozonCreatedAt, $listing->getMarketplaceCreatedAt());
    }

    public function testLastSeenAtIsNullByDefault(): void
    {
        $listing = $this->createListing();

        self::assertNull($listing->getLastSeenAt());
    }

    public function testLastSeenAtCanBeSet(): void
    {
        $listing = $this->createListing();
        $seenAt = new \DateTimeImmutable('2026-09-01T03:40:00+00:00');

        $listing->setLastSeenAt($seenAt);

        self::assertSame($seenAt, $listing->getLastSeenAt());
    }

    private function createListing(): MarketplaceListing
    {
        return new MarketplaceListing(
            '55555555-5555-4555-8555-000000000001',
            CompanyBuilder::aCompany()->build(),
            null,
            MarketplaceType::WILDBERRIES,
        );
    }
}
