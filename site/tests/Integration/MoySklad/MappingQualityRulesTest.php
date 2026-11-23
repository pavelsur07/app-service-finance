<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Domain\ProductSnapshot;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;

final class MappingQualityRulesTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testCodeAndArticleConflictsAreNotHidden(): void
    {
        $d = $this->mappingCatalog($this->em);
        $this->em->persist(MoySkladProductBuilder::aProduct()->forConnection($d['connection'])->withIndex(2)->withArticle('OTHER')->withCode('001')->build());
        $this->em->flush();
        $page = self::getContainer()->get(AutomaticMappingPreviewQuery::class)->page($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', '', 1, null);
        self::assertSame('ambiguous', $page->decisions[$d['listing']->getId()]->status->value);
        self::assertCount(2, $page->decisions[$d['listing']->getId()]->candidates);
    }

    public function testBarcodeWorksWithoutArticleAndPackageCheckStillBlocksIt(): void
    {
        $d = $this->mappingCatalog($this->em);
        $d['listing']->setSupplierSku(null);
        $d['listing']->setName('Набор 3 шт');
        $now = new \DateTimeImmutable();
        $d['product']->applySnapshot(new ProductSnapshot($d['product']->getExternalId(), 'Товар 1 шт', '', null, 'OTHER', 0, false, $now, ['000123']), $now);
        $this->em->persist(new MarketplaceListingBarcode(Uuid::uuid7()->toString(), $d['listing'], $d['connection']->getCompanyId(), 'ozon', '000123'));
        $this->em->flush();
        $query = self::getContainer()->get(AutomaticMappingPreviewQuery::class);
        self::assertSame('package_review', $query->page($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', '', 1, null)->decisions[$d['listing']->getId()]->status->value);
        $d['listing']->setName('Набор 1 шт');
        $this->em->flush();
        $decision = $query->page($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', '', 1, null)->decisions[$d['listing']->getId()];
        self::assertSame('ready', $decision->status->value);
        self::assertSame(['exact_barcode'], $decision->matchedRules);
    }

    public function testWbPresetIsReadFromScopedSettings(): void
    {
        $d = $this->mappingCatalog($this->em);
        $listing = MarketplaceListingBuilder::aListing()->forCompany($d['company'])->withIndex(2)->withMarketplace(MarketplaceType::WILDBERRIES)->withMarketplaceSku('WB-2')->withSupplierSku('001w')->build();
        $this->em->persist($listing);
        $this->em->flush();
        $query = self::getContainer()->get(AutomaticMappingPreviewQuery::class);
        $args = [$d['connection']->getCompanyId(), $d['connection']->getId(), 'wildberries', '', 1, null];
        self::assertSame('no_match', $query->page(...$args)->decisions[$listing->getId()]->status->value);
        $this->connection->executeStatement("INSERT INTO moysklad_mapping_settings VALUES (?, ?, 'wildberries', TRUE, 1)", array_slice($args, 0, 2));
        self::assertSame('ready', $query->page(...$args)->decisions[$listing->getId()]->status->value);
    }

    public function testConfirmationIsSeparateIdempotentAndExpiresWhenSourceChanges(): void
    {
        $d = $this->mappingCatalog($this->em);
        $company = $d['connection']->getCompanyId();
        $connection = $d['connection']->getId();
        $id = $d['listing']->getId();
        $d['listing']->setName('Набор 5 шт');
        $now = new \DateTimeImmutable();
        $d['product']->applySnapshot(new ProductSnapshot($d['product']->getExternalId(), 'Товар 1 шт', '', null, '001', 0, false, $now), $now);
        $this->em->flush();
        $facade = self::getContainer()->get(\App\Marketplace\Facade\MarketplaceFacade::class);
        $targets = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery::class);
        $policy = new \App\MoySklad\Domain\AutomaticListingMappingPolicy();
        $target = $targets->find($company, $connection, 'product', $d['product']->getExternalId());
        self::assertNotNull($target);
        $fingerprint = $policy->packageReviewFingerprint($facade->getListingsMetaByIds($company, [$id])[$id], $target);
        $command = new ChangeListingMappingCommand($company, $d['actor'], $connection, $id, ListingMappingOperation::SET, 'product', $d['product']->getExternalId(), 0, Uuid::uuid7()->toString(), packageReviewConfirmed: true, packageReviewFingerprint: $fingerprint);
        $writer = self::getContainer()->get(ChangeListingMappingAction::class);
        $state = $writer($command);
        self::assertSame(1, $state->revision);
        self::assertSame($state->revisionId, $writer($command)->revisionId);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_package_reviews'));
        $mappings = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery::class);
        self::assertSame('mapped', $mappings->currentForListings($company, $connection, [$id])[$id]->status);
        $d['listing']->setName('Набор 2 шт');
        $this->em->flush();
        self::assertSame('package_review', $mappings->currentForListings($company, $connection, [$id])[$id]->reason);
        try {
            $writer(new ChangeListingMappingCommand($company, $d['actor'], $connection, $id, ListingMappingOperation::SET, 'product', $d['product']->getExternalId(), 1, Uuid::uuid7()->toString(), packageReviewConfirmed: true, packageReviewFingerprint: $fingerprint));
            self::fail('Old confirmation must not approve changed source.');
        } catch (ListingMappingException $e) {
            self::assertSame('stale_package_review', $e->reason);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $fresh = $policy->packageReviewFingerprint($facade->getListingsMetaByIds($company, [$id])[$id], $target);
        foreach (range(1, 2) as $attempt) {
            $again = $writer(new ChangeListingMappingCommand($company, $d['actor'], $connection, $id, ListingMappingOperation::SET, 'product', $d['product']->getExternalId(), 1, Uuid::uuid7()->toString(), packageReviewConfirmed: true, packageReviewFingerprint: $fresh));
            self::assertSame($state->revisionId, $again->revisionId);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_package_reviews WHERE revision=1'));
        self::assertSame('mapped', $mappings->currentForListings($company, $connection, [$id])[$id]->status);
        $this->connection->executeStatement('UPDATE moysklad_products SET archived=TRUE WHERE external_id=?', [$target->externalId]);
        $d['listing']->setName('Набор 3 шт');
        $this->em->flush();
        self::assertSame('archived', $mappings->currentForListings($company, $connection, [$id])[$id]->reason);
        self::assertSame('archived', $writer(new ChangeListingMappingCommand($company, $d['actor'], $connection, $id, ListingMappingOperation::SET, 'product', $target->externalId, 1, Uuid::uuid7()->toString()))->reason);
    }

    public function testManualPackageMismatchCannotBeAssignedWithoutExplicitConfirmation(): void
    {
        $d = $this->mappingCatalog($this->em);
        $d['listing']->setName('Набор 5 шт');
        $now = new \DateTimeImmutable();
        $d['product']->applySnapshot(new ProductSnapshot($d['product']->getExternalId(), 'Товар 1 шт', '', null, '001', 0, false, $now), $now);
        $this->em->flush();
        try {
            (self::getContainer()->get(ChangeListingMappingAction::class))(new ChangeListingMappingCommand($d['connection']->getCompanyId(), $d['actor'], $d['connection']->getId(), $d['listing']->getId(), ListingMappingOperation::SET, 'product', $d['product']->getExternalId(), 0, Uuid::uuid7()->toString()));
            self::fail('Package mismatch must require explicit review.');
        } catch (ListingMappingException $e) {
            self::assertSame('package_review_required', $e->reason);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }
}
