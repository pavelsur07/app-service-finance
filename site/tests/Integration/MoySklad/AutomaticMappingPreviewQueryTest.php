<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;

final class AutomaticMappingPreviewQueryTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testCurrentClearedInactiveAndOtherMarketplaceAreNotAutomaticCandidates(): void
    {
        $data = $this->mappingCatalog($this->em);
        $listings = [$data['listing']];
        foreach (range(2, 5) as $i) {
            $listing = MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i)->withMarketplace(5 === $i ? MarketplaceType::WILDBERRIES : MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build();
            $this->em->persist($listing);
            $listings[] = $listing;
        }
        $listings[3]->setIsActive(false);
        $this->em->persist(MoySkladListingMappingRevisionBuilder::aRevision()->forListing($listings[1]->getId())->build());
        $this->em->persist(MoySkladListingMappingRevisionBuilder::aRevision()->forListing($listings[2]->getId())->clear()->build());
        $this->em->flush();
        $result = self::getContainer()->get(AutomaticMappingPreviewQuery::class)->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'ozon', '', 1, null);
        self::assertSame(4, $result->listingsPage->totalItems);
        self::assertSame('ready', $result->decisions[$listings[0]->getId()]->status->value);
        self::assertSame('existing_mapping', $result->decisions[$listings[1]->getId()]->status->value);
        self::assertSame('cleared_mapping', $result->decisions[$listings[2]->getId()]->status->value);
        self::assertSame('listing_unavailable', $result->decisions[$listings[3]->getId()]->status->value);
        self::assertSame(1, $result->counts['ready']);
        self::assertArrayNotHasKey($listings[4]->getId(), $result->decisions);
    }

    public function testUnreadyCatalogIsExplicitAndPageIsBounded(): void
    {
        $data = $this->mappingCatalog($this->em, false);
        foreach (range(2, 26) as $i) {
            $this->em->persist(MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em->flush();
        $query = self::getContainer()->get(AutomaticMappingPreviewQuery::class);
        $result = $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'ozon', '', 1, null);
        self::assertCount(25, $result->decisions);
        self::assertSame(25, $result->counts['catalog_not_ready']);
        self::assertSame(26, $result->listingsPage->totalItems);
        self::assertCount(1, $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'ozon', '', 2, null)->decisions);
    }

    public function testWildberriesSizesChooseDistinctVariantsByExplicitCharacteristic(): void
    {
        $data = $this->mappingCatalog($this->em);
        $parent = \App\Tests\Builders\MoySklad\MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->withArticle('WB-STYLE')->withVariantsCount(2)->build();
        $this->em->persist($parent);
        $ids = [];
        foreach (['M', 'L'] as $i => $size) {
            $variant = \App\Tests\Builders\MoySklad\MoySkladVariantBuilder::aVariant()->forProduct($parent)->withIndex($i + 1)->withArticle(null)->build();
            $now = new \DateTimeImmutable();
            $variant->applySnapshot(new \App\MoySklad\Domain\VariantSnapshot($variant->getExternalId(), $parent->getExternalId(), 'Size '.$size, '', null, null,
                [['id' => 'size', 'name' => 'Размер', 'value' => $size]], false, $now), $now);
            $listing = MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i + 2)->withMarketplace(MarketplaceType::WILDBERRIES)->withMarketplaceSku('1000')->withMarketplaceVariantId((string) (2001 + $i))->withSupplierSku('WB-STYLE')->build();
            $listing->setSize($size);
            $this->em->persist($listing);
            $this->em->persist($variant);
            $ids[$listing->getId()] = $variant->getExternalId();
        }
        $this->em->flush();
        $result = self::getContainer()->get(AutomaticMappingPreviewQuery::class)->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'wildberries', '1000', 1, 'size');
        self::assertSame(2, $result->counts['ready']);
        foreach ($ids as $listingId => $variantId) {
            self::assertSame($variantId, $result->decisions[$listingId]->target?->externalId);
            self::assertSame(['parent_article_size'], $result->decisions[$listingId]->matchedRules);
        }
    }

    public function testPreviewQueryCountDoesNotGrowWithRows(): void
    {
        $data = $this->mappingCatalog($this->em);
        $query = self::getContainer()->get(AutomaticMappingPreviewQuery::class);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'ozon', '', 1, null);
        $one = $this->mappingQueries($holder);
        self::assertGreaterThan(0, $one);
        foreach (range(2, 25) as $i) {
            $this->em->persist(MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em->flush();
        $holder->reset();
        $page = $query->page($data['connection']->getCompanyId(), $data['connection']->getId(), 'ozon', '', 1, null);
        self::assertSame(25, $page->counts['ready']);
        self::assertSame($one, $this->mappingQueries($holder));
    }

    private function mappingQueries(\Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder $holder): int
    {
        /** @var array<string,list<array{sql:string}>> $data */
        $data = $holder->getData();

        return count(array_filter($data['default'] ?? [], static fn (array $row): bool => 1 === preg_match('/marketplace_listings|moysklad_(listing_mapping_revisions|products|variants|sync_cursors|connections)/', $row['sql'])));
    }
}
