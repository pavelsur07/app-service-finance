<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository;
use App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;

final class ListingMappingsQueryTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testLatestClearAndBoundedHistoryPreserveSnapshots(): void
    {
        $data = $this->mappingCatalog($this->em);
        $repo = self::getContainer()->get(MoySkladListingMappingRepository::class);
        for ($i = 1; $i <= 22; ++$i) {
            $builder = MoySkladListingMappingRevisionBuilder::aRevision()->forListing($data['listing']->getId())->withRevision($i);
            $repo->append((22 === $i ? $builder->clear() : $builder)->build());
        }
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladListingMappingsQuery::class);
        $current = $query->currentForListings($data['connection']->getCompanyId(), $data['connection']->getId(), [$data['listing']->getId()]);
        self::assertSame(22, $current[$data['listing']->getId()]->revision);
        self::assertSame('unmapped', $current[$data['listing']->getId()]->status);
        $history = $query->history($data['connection']->getCompanyId(), $data['connection']->getId(), $data['listing']->getId());
        self::assertSame(22, $history->totalItems);
        self::assertCount(20, $history->items);
        self::assertSame(22, $history->items[0]->revision);
        self::assertCount(2, $query->history($data['connection']->getCompanyId(), $data['connection']->getId(), $data['listing']->getId(), 2)->items);
        self::assertSame([], $query->history('99999999-9999-4999-8999-999999999999', $data['connection']->getId(), $data['listing']->getId())->items);
    }

    public function testArchiveAndMissingListingRequireReviewWithoutChangingRevision(): void
    {
        $data = $this->mappingCatalog($this->em);
        $repo = self::getContainer()->get(MoySkladListingMappingRepository::class);
        $repo->append(MoySkladListingMappingRevisionBuilder::aRevision()->forListing($data['listing']->getId())->build());
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladListingMappingsQuery::class);
        $this->connection->executeStatement('UPDATE moysklad_products SET archived = TRUE');
        $current = $query->currentForListings($data['connection']->getCompanyId(), $data['connection']->getId(), [$data['listing']->getId()]);
        self::assertSame('needs_review', $current[$data['listing']->getId()]->status);
        self::assertSame(1, $current[$data['listing']->getId()]->revision);
        self::assertSame('Товар', $query->history($data['connection']->getCompanyId(), $data['connection']->getId(), $data['listing']->getId())->items[0]->targetSnapshot['name'] ?? null);
        $this->connection->executeStatement('DELETE FROM marketplace_listings WHERE id = ?', [$data['listing']->getId()]);
        $current = $query->currentForListings($data['connection']->getCompanyId(), $data['connection']->getId(), [$data['listing']->getId()]);
        self::assertSame('listing_unavailable', $current[$data['listing']->getId()]->reason);
    }

    public function testLimitsBatchBeforeQuery(): void
    {
        $query = self::getContainer()->get(MoySkladListingMappingsQuery::class);
        self::assertSame([], $query->currentForListings('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333', []));
        $ids = [];
        for ($i = 1; $i <= 100; ++$i) {
            $ids[] = sprintf('55555555-5555-4555-8555-%012d', $i);
        }
        self::assertCount(100, $query->currentForListings('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333', $ids));
        $this->expectException(\InvalidArgumentException::class);
        $query->currentForListings('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333', array_fill(0, 101, '55555555-5555-4555-8555-000000000001'));
    }

    public function testCurrentNormalizesUppercaseIdsAndForeignCompanyReturnsDefaults(): void
    {
        $data = $this->mappingCatalog($this->em);
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $listing = \App\Tests\Builders\Marketplace\MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withId($id)->withMarketplaceSku('SECOND')->build();
        $this->em->persist($listing);
        self::getContainer()->get(MoySkladListingMappingRepository::class)->append(MoySkladListingMappingRevisionBuilder::aRevision()->forListing($id)->build());
        $this->em->flush();
        $query = self::getContainer()->get(MoySkladListingMappingsQuery::class);
        $states = $query->currentForListings($data['connection']->getCompanyId(), $data['connection']->getId(), [strtoupper($id)]);
        self::assertSame([$id], array_keys($states));
        self::assertSame(1, $states[$id]->revision);
        $foreign = $query->currentForListings('99999999-9999-4999-8999-999999999999', $data['connection']->getId(), [$id]);
        self::assertSame(0, $foreign[$id]->revision);
        self::assertSame('unmapped', $foreign[$id]->status);
        self::assertSame([], $query->history($data['connection']->getCompanyId(), $data['connection']->getId(), $id, 99)->items);
    }

    public function testHistoryRejectsMalformedIdsBeforeSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(MoySkladListingMappingsQuery::class)->history('11111111-1111-7111-8111-111111111111', '33333333-3333-7333-8333-333333333333', 'invalid');
    }
}
