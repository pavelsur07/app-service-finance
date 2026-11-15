<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladVariantBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class ChangeListingMappingActionTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testSetReplaceReplayAndClearKeepHistoryAndSourceData(): void
    {
        $data = $this->mappingCatalog($this->em);
        $listing = $data['listing'];
        $command = $this->command($data['connection']->getId(), $listing->getId(), $data['product']->getExternalId());
        $action = self::getContainer()->get(ChangeListingMappingAction::class);
        $first = $action($command);
        self::assertSame(1, $first->revision);
        self::assertSame('mapped', $first->status);
        self::assertSame('product', $first->target?->type);
        $variant = MoySkladVariantBuilder::aVariant()->forProduct($data['product'])->build();
        $this->em->persist($variant);
        $this->em->flush();
        $second = $action($this->command($data['connection']->getId(), $listing->getId(), $variant->getExternalId(), 1, 'variant'));
        self::assertSame(2, $second->revision);
        self::assertSame('variant', $second->target?->type);
        self::assertSame(1, $action($command)->revision);
        $clear = $action($this->command($data['connection']->getId(), $listing->getId(), null, 2));
        self::assertSame(3, $clear->revision);
        self::assertSame('unmapped', $clear->status);
        self::assertNull($clear->target);
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        self::assertNull($listing->getProduct());
        self::assertSame('1000.00', $listing->getPrice());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT variants_count FROM moysklad_products'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_stock_snapshots'));
    }

    public function testNoOpsDoNotCreateRevisionsButStaleCasStillConflicts(): void
    {
        $data = $this->mappingCatalog($this->em);
        $action = self::getContainer()->get(ChangeListingMappingAction::class);
        $cid = $data['connection']->getId();
        $lid = $data['listing']->getId();
        $pid = $data['product']->getExternalId();
        self::assertSame(0, $action($this->command($cid, $lid, null))->revision);
        $action($this->command($cid, $lid, $pid));
        self::assertSame(1, $action($this->command($cid, $lid, $pid, 1))->revision);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $this->expectExceptionObject(new ListingMappingException('stale_mapping', 'Связь изменилась. Обновите страницу.'));
        $action($this->command($cid, $lid, $pid));
    }

    public function testRequestIdCannotBeReusedForAnotherPayload(): void
    {
        $data = $this->mappingCatalog($this->em);
        $action = self::getContainer()->get(ChangeListingMappingAction::class);
        $requestId = Uuid::uuid7()->toString();
        $action($this->command($data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId(), requestId: $requestId));
        try {
            $action($this->command($data['connection']->getId(), $data['listing']->getId(), null, 1, requestId: $requestId));
            self::fail('Reused request accepted.');
        } catch (ListingMappingException $error) {
            self::assertSame('idempotency_conflict', $error->reason);
        }
    }

    public function testManyListingsMayPointToOneProductIncludingInactiveListing(): void
    {
        $data = $this->mappingCatalog($this->em);
        $other = MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withIndex(2)->withMarketplaceSku('OZ-002')->build();
        $other->setIsActive(false);
        $this->em->persist($other);
        $this->em->flush();
        $action = self::getContainer()->get(ChangeListingMappingAction::class);
        foreach ([$data['listing'], $other] as $listing) {
            self::assertSame('mapped', $action($this->command($data['connection']->getId(), $listing->getId(), $data['product']->getExternalId()))->status);
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    public function testTransportFailureDoesNotInvalidateLoadedCatalogAndDisabledConnectionCanBeCleared(): void
    {
        $data = $this->mappingCatalog($this->em);
        $data['connection']->recordCheck(ConnectionCheckStatus::UNAVAILABLE, new \DateTimeImmutable());
        $data['connection']->setAccessTokenEncrypted('opaque-replaced-test-payload');
        $this->em->flush();
        $action = self::getContainer()->get(ChangeListingMappingAction::class);
        self::assertSame(1, $action($this->command($data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId()))->revision);
        $data['connection']->setIsActive(false);
        $this->em->flush();
        self::assertSame(2, $action($this->command($data['connection']->getId(), $data['listing']->getId(), null, 1))->revision);
    }

    public function testClearUsesOwnHistoryAfterListingDisappears(): void
    {
        $data = $this->mappingCatalog($this->em);
        $action = self::getContainer()->get(ChangeListingMappingAction::class);
        $action($this->command($data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId()));
        $this->connection->executeStatement('DELETE FROM marketplace_listings WHERE id = ?', [$data['listing']->getId()]);
        $state = $action($this->command($data['connection']->getId(), $data['listing']->getId(), null, 1));
        self::assertSame(2, $state->revision);
        self::assertSame('unmapped', $state->status);
        self::assertSame('ozon', $state->listingSnapshot['marketplace'] ?? null);
    }

    /** @param array<string, string|int|bool> $changes */
    #[DataProvider('unavailableTargets')]
    public function testRejectsUnavailableCatalogTargets(array $changes, string $reason): void
    {
        $data = $this->mappingCatalog($this->em, !isset($changes['not_ready']));
        $target = $data['product']->getExternalId();
        $type = 'product';
        if (isset($changes['archived'])) {
            $this->connection->executeStatement('UPDATE moysklad_products SET archived = TRUE');
        }
        if (isset($changes['count'])) {
            $this->connection->executeStatement('UPDATE moysklad_products SET variants_count = 1');
        }
        if (isset($changes['variant'])) {
            $variant = MoySkladVariantBuilder::aVariant()->forProduct($data['product'])->build();
            $this->em->persist($variant);
            $this->em->flush();
            if (isset($changes['select_variant'])) {
                $target = $variant->getExternalId();
                $type = 'variant';
            }
        }
        if (isset($changes['unknown'])) {
            $target = '99999999-9999-4999-8999-999999999999';
        }
        try {
            self::getContainer()->get(ChangeListingMappingAction::class)($this->command($data['connection']->getId(), $data['listing']->getId(), $target, type: $type));
            self::fail('Unavailable target accepted.');
        } catch (ListingMappingException $error) {
            self::assertSame($reason, $error->reason);
        }
    }

    /** @return iterable<string, array{array<string, string|int|bool>, string}> */
    public static function unavailableTargets(): iterable
    {
        yield 'archived product' => [['archived' => true], 'target_unavailable'];
        yield 'known variants count' => [['count' => true], 'target_unavailable'];
        yield 'actual variant with stale count' => [['variant' => true], 'target_unavailable'];
        yield 'archived variant parent' => [['variant' => true, 'select_variant' => true, 'archived' => true], 'target_unavailable'];
        yield 'unknown target' => [['unknown' => true], 'not_found'];
        yield 'no successful catalog import' => [['not_ready' => true], 'catalog_not_ready'];
    }

    private function command(string $connectionId, string $listingId, ?string $targetId, int $expected = 0, string $type = 'product', ?string $requestId = null): ChangeListingMappingCommand
    {
        return new ChangeListingMappingCommand(
            '11111111-1111-7111-8111-111111111111', '22222222-2222-4222-8222-000000000001',
            $connectionId, $listingId, null === $targetId ? ListingMappingOperation::CLEAR : ListingMappingOperation::SET,
            null === $targetId ? null : $type, $targetId, $expected, $requestId ?? Uuid::uuid7()->toString(),
        );
    }

    public function testExpectedDomainRejectionKeepsEntityManagerOpen(): void
    {
        $data = $this->mappingCatalog($this->em, false);
        try {
            self::getContainer()->get(ChangeListingMappingAction::class)($this->command($data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId()));
            self::fail('Incomplete catalog accepted.');
        } catch (ListingMappingException $error) {
            self::assertSame('catalog_not_ready', $error->reason);
        }
        self::assertTrue($this->em->isOpen());
    }

    #[DataProvider('foreignAndMissingSources')]
    public function testRejectsForeignAndMissingSourcesWithoutWriting(string $scenario): void
    {
        $data = $this->mappingCatalog($this->em);
        $foreignCompanyId = '99999999-9999-4999-8999-999999999999';
        $owner = $data['company']->getUser();
        self::assertNotNull($owner);
        $foreignCompany = \App\Tests\Builders\Company\CompanyBuilder::aCompany()->withId($foreignCompanyId)->withOwner($owner)->build();
        $foreignListing = MarketplaceListingBuilder::aListing()->forCompany($foreignCompany)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withIndex(2)->withMarketplaceSku('FOREIGN')->build();
        $otherCompanyId = str_starts_with($scenario, 'foreign') ? $foreignCompanyId : $data['connection']->getCompanyId();
        $otherConnection = \App\Tests\Builders\MoySklad\MoySkladConnectionBuilder::aConnection()->withIndex(2)->withCompanyId($otherCompanyId)->build();
        $otherConnection->setName('Other connection');
        $otherProduct = \App\Tests\Builders\MoySklad\MoySkladProductBuilder::aProduct()->forConnection($otherConnection)->withIndex(2)->build();
        $otherVariant = MoySkladVariantBuilder::aVariant()->forProduct($otherProduct)->withIndex(2)->build();
        foreach ([$foreignCompany, $foreignListing, $otherConnection, $otherProduct, $otherVariant] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $cid = 'foreign_connection' === $scenario ? $otherConnection->getId() : $data['connection']->getId();
        $lid = 'foreign_listing' === $scenario ? $foreignListing->getId() : $data['listing']->getId();
        $type = str_ends_with($scenario, 'variant') ? 'variant' : 'product';
        $target = str_ends_with($scenario, 'variant') ? $otherVariant->getExternalId() : (str_ends_with($scenario, 'product') ? $otherProduct->getExternalId() : $data['product']->getExternalId());
        if (str_starts_with($scenario, 'missing')) {
            $this->connection->executeStatement('DELETE FROM marketplace_listings WHERE id=?', [$lid]);
        }
        try {
            self::getContainer()->get(ChangeListingMappingAction::class)($this->command($cid, $lid, 'missing_clear' === $scenario ? null : $target, type: $type));
            self::fail('Foreign or missing source accepted.');
        } catch (ListingMappingException $error) {
            self::assertSame('not_found', $error->reason);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    /** @return iterable<string,array{string}> */
    public static function foreignAndMissingSources(): iterable
    {
        foreach (['foreign_connection', 'foreign_listing', 'other_product', 'foreign_product', 'other_variant', 'foreign_variant', 'missing_set', 'missing_clear'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }
}
