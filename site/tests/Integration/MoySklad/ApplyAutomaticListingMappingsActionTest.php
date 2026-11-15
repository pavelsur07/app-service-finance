<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\ApplyAutomaticListingMappingsAction;
use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ApplyAutomaticMappingsCommand;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Application\DTO\AutomaticMappingPreview;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class ApplyAutomaticListingMappingsActionTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testBatchRetryAndReplayAfterManualClearNeverRestoreIt(): void
    {
        $data = $this->mappingCatalog($this->em);
        foreach (range(2, 25) as $i) {
            $this->em->persist(MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em->flush();
        $preview = $this->preview($data);
        $command = $this->command($preview, array_column($preview->items, 'listingId'));
        $action = self::getContainer()->get(ApplyAutomaticListingMappingsAction::class);
        self::assertSame(25, $action($command)->accepted);
        self::assertSame(25, $action($command)->accepted);
        self::assertSame(25, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $item = $preview->items[0];
        self::getContainer()->get(ChangeListingMappingAction::class)(new ChangeListingMappingCommand($preview->companyId, $preview->actorUserId, $preview->connectionId,
            $item->listingId, ListingMappingOperation::CLEAR, null, null, 1, Uuid::uuid7()->toString()));
        self::assertSame(25, $action($command)->accepted);
        self::assertSame('clear', $this->connection->fetchOne('SELECT operation FROM moysklad_listing_mapping_revisions WHERE listing_id=? ORDER BY revision DESC LIMIT 1', [$item->listingId]));
        self::assertSame(26, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $snapshot = json_decode($this->connection->fetchOne('SELECT listing_snapshot FROM moysklad_listing_mapping_revisions WHERE listing_id=? AND revision=1', [$item->listingId]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($preview->id, $snapshot['autoMapping']['previewId']);
        self::assertSame(['direct_article'], $snapshot['autoMapping']['matchedRules']);
    }

    #[DataProvider('changes')]
    public function testStalePreviewIsRejectedWithoutClosingEntityManager(string $change): void
    {
        $data = $this->mappingCatalog($this->em);
        $preview = $this->preview($data);
        if ('duplicate' === $change) {
            $this->em->persist(MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->build());
        } elseif ('clear' === $change) {
            $writer = self::getContainer()->get(ChangeListingMappingAction::class);
            $writer(new ChangeListingMappingCommand($preview->companyId, $preview->actorUserId, $preview->connectionId, $preview->items[0]->listingId, ListingMappingOperation::SET, 'product', $data['product']->getExternalId(), 0, Uuid::uuid7()->toString()));
            $writer(new ChangeListingMappingCommand($preview->companyId, $preview->actorUserId, $preview->connectionId, $preview->items[0]->listingId, ListingMappingOperation::CLEAR, null, null, 1, Uuid::uuid7()->toString()));
        } elseif ('disabled' === $change) {
            $this->connection->executeStatement('UPDATE moysklad_connections SET is_active=FALSE');
        } elseif ('archive' === $change) {
            $this->connection->executeStatement('UPDATE moysklad_products SET archived=TRUE');
        } else {
            $column = match ($change) {
                'article' => 'supplier_sku', 'size' => 'size', 'provider' => 'marketplace', 'inactive' => 'is_active', default => 'marketplace_sku',
            };
            $value = match ($change) {
                'provider' => 'wildberries', 'inactive' => 'false', default => 'CHANGED',
            };
            $this->connection->executeStatement('UPDATE marketplace_listings SET '.$column.'=? WHERE id=?', [$value, $data['listing']->getId()]);
        }
        $this->em->flush();
        $result = self::getContainer()->get(ApplyAutomaticListingMappingsAction::class)($this->command($preview, [$preview->items[0]->listingId]));
        self::assertSame(0, $result->accepted);
        self::assertSame(1, $result->conflicts);
        self::assertTrue($this->em->isOpen());
        self::assertSame('clear' === $change ? 2 : 0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    /** @return iterable<string,array{string}> */
    public static function changes(): iterable
    {
        foreach (['duplicate', 'clear', 'disabled', 'archive', 'article', 'size', 'provider', 'inactive', 'sku'] as $change) {
            yield $change => [$change];
        }
    }

    public function testRequestIdCannotMixManualAndAutomaticOrDifferentGuard(): void
    {
        $data = $this->mappingCatalog($this->em);
        $preview = $this->preview($data);
        $item = $preview->items[0];
        $writer = self::getContainer()->get(ChangeListingMappingAction::class);
        $guard = new AutomaticMappingGuard($preview->id, $preview->ruleVersion, $item->sourceFingerprint, null);
        $writer(new ChangeListingMappingCommand($preview->companyId, $preview->actorUserId, $preview->connectionId, $item->listingId, ListingMappingOperation::SET, $item->targetType, $item->targetExternalId, 0, $item->requestId, $guard));
        foreach ([null, new AutomaticMappingGuard(Uuid::uuid7()->toString(), $preview->ruleVersion, $item->sourceFingerprint, null)] as $otherGuard) {
            try {
                $writer(new ChangeListingMappingCommand($preview->companyId, $preview->actorUserId, $preview->connectionId, $item->listingId, ListingMappingOperation::SET, $item->targetType, $item->targetExternalId, 0, $item->requestId, $otherGuard));
                self::fail('Guard mismatch accepted.');
            } catch (ListingMappingException $error) {
                self::assertSame('idempotency_conflict', $error->reason);
            }
        }
    }

    public function testSelectionCannotContainForeignDuplicateOrMoreThanTwentyFiveIds(): void
    {
        $data = $this->mappingCatalog($this->em);
        $preview = $this->preview($data);
        foreach ([[Uuid::uuid7()->toString()], [$preview->items[0]->listingId, $preview->items[0]->listingId], array_fill(0, 26, $preview->items[0]->listingId)] as $ids) {
            try {
                $this->command($preview, $ids);
                self::fail('Invalid selection accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
            }
        }
    }

    public function testTechnicalFailurePreservesAcceptedRowsAndStopsRemainder(): void
    {
        $data = $this->mappingCatalog($this->em);
        foreach (range(2, 3) as $i) {
            $this->em->persist(MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em->flush();
        $preview = $this->preview($data);
        $failId = $preview->items[1]->listingId;
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.fail_automatic_mapping() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.listing_id = '".$failId."'::uuid THEN RAISE EXCEPTION 'Test insertion failure'; END IF; RETURN NEW; END; \$\$");
        $this->connection->executeStatement('CREATE TRIGGER fail_automatic_mapping BEFORE INSERT ON moysklad_listing_mapping_revisions FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_automatic_mapping()');
        $result = self::getContainer()->get(ApplyAutomaticListingMappingsAction::class)($this->command($preview, array_column($preview->items, 'listingId')));
        self::assertSame(1, $result->accepted);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->notProcessed);
        self::assertFalse($this->em->isOpen());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    public function testDeletedListingManualClearHasItsOwnAuditAndReplays(): void
    {
        $data = $this->mappingCatalog($this->em);
        $p = $this->preview($data);
        self::getContainer()->get(ApplyAutomaticListingMappingsAction::class)($this->command($p, [$p->items[0]->listingId]));
        $this->connection->executeStatement('DELETE FROM marketplace_listings WHERE id=?', [$p->items[0]->listingId]);
        $clear = new ChangeListingMappingCommand($p->companyId, $p->actorUserId, $p->connectionId, $p->items[0]->listingId, ListingMappingOperation::CLEAR, null, null, 1, Uuid::uuid7()->toString());
        $writer = self::getContainer()->get(ChangeListingMappingAction::class);
        $first = $writer($clear);
        self::assertSame($first->revisionId, $writer($clear)->revisionId);
        self::assertArrayNotHasKey('autoMapping', $first->listingSnapshot ?? []);
    }

    public function testExplicitSizeApplyRechecksCharacteristicAndRejectsTamperedTarget(): void
    {
        $data = $this->mappingCatalog($this->em);
        $variant = \App\Tests\Builders\MoySklad\MoySkladVariantBuilder::aVariant()->forProduct($data['product'])->withArticle(null)->build();
        $now = new \DateTimeImmutable();
        $chars = [['id' => 'size', 'name' => 'Размер', 'value' => 'M']];
        $variant->applySnapshot(new \App\MoySklad\Domain\VariantSnapshot($variant->getExternalId(), $data['product']->getExternalId(), 'M', '', null, null, $chars, false, $now), $now);
        $source = MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex(2)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::WILDBERRIES)->withMarketplaceSku('WB')->withSupplierSku('001')->build();
        $source->setSize('M');
        $this->em->persist($variant);
        $this->em->persist($source);
        $this->em->flush();
        $c = $data['connection'];
        $p = AutomaticMappingPreview::fromPage($c->getCompanyId(), $data['actor'], $c->getId(), 'wildberries', self::getContainer()->get(AutomaticMappingPreviewQuery::class)->page($c->getCompanyId(), $c->getId(), 'wildberries', '', 1, 'size'), $now);
        $item = $p->items[0];
        self::assertSame('ready', $item->decision->status->value);
        try {
            self::getContainer()->get(ChangeListingMappingAction::class)(new ChangeListingMappingCommand($p->companyId, $p->actorUserId, $p->connectionId, $item->listingId, ListingMappingOperation::SET, 'variant', Uuid::uuid7()->toString(), 0, $item->requestId, new AutomaticMappingGuard($p->id, $p->ruleVersion, $item->sourceFingerprint, 'size')));
            self::fail('Tampered target accepted.');
        } catch (ListingMappingException $error) {
            self::assertSame('automatic_mapping_conflict', $error->reason);
        }
        $this->connection->executeStatement("UPDATE moysklad_variants SET characteristics='[]'");
        self::assertSame(1, self::getContainer()->get(ApplyAutomaticListingMappingsAction::class)($this->command($p, [$item->listingId]))->conflicts);
        $this->connection->executeStatement('UPDATE moysklad_variants SET characteristics=?', [json_encode($chars, \JSON_THROW_ON_ERROR)]);
        self::assertSame(1, self::getContainer()->get(ApplyAutomaticListingMappingsAction::class)($this->command($p, [$item->listingId]))->accepted);
        $snapshot = json_decode($this->connection->fetchOne('SELECT listing_snapshot FROM moysklad_listing_mapping_revisions'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['parent_article_size'], $snapshot['autoMapping']['matchedRules']);
    }

    public function testEnvelopeScopeNonReadyAndExpiryRejectBeforeAnyWrite(): void
    {
        $data = $this->mappingCatalog($this->em);
        $p = $this->preview($data);
        $ids = [$p->items[0]->listingId];
        foreach (['company', 'actor', 'connection', 'marketplace'] as $field) {
            try {
                new ApplyAutomaticMappingsCommand('company' === $field ? Uuid::uuid7()->toString() : $p->companyId,
                    'actor' === $field ? Uuid::uuid7()->toString() : $p->actorUserId,
                    'connection' === $field ? Uuid::uuid7()->toString() : $p->connectionId,
                    'marketplace' === $field ? 'wildberries' : $p->marketplace, $p, $ids);
                self::fail('Foreign envelope accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
            }
        }
        $payload = $p->toArray();
        $payload['items'][0]['decision']['status'] = 'no_match';
        $payload['items'][0]['decision']['target'] = null;
        try {
            $this->command(AutomaticMappingPreview::fromArray($payload), $ids);
            self::fail('Non-READY selection accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($this->em->isOpen());
        }
        $payload = $p->toArray();
        $payload['createdAt'] = time() - 1000;
        $payload['expiresAt'] = time() - 100;
        $this->expectExceptionObject(new ListingMappingException('not_found', 'Предпросмотр истёк. Сформируйте новый.'));
        self::getContainer()->get(ApplyAutomaticListingMappingsAction::class)($this->command(AutomaticMappingPreview::fromArray($payload), $ids));
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection,actor:string} $data */
    private function preview(array $data): AutomaticMappingPreview
    {
        $c = $data['connection'];

        return AutomaticMappingPreview::fromPage($c->getCompanyId(), $data['actor'], $c->getId(), 'ozon', self::getContainer()->get(AutomaticMappingPreviewQuery::class)->page($c->getCompanyId(), $c->getId(), 'ozon', '', 1, null), new \DateTimeImmutable());
    }

    /** @param list<string> $ids */
    private function command(AutomaticMappingPreview $p, array $ids): ApplyAutomaticMappingsCommand
    {
        return new ApplyAutomaticMappingsCommand($p->companyId, $p->actorUserId, $p->connectionId, $p->marketplace, $p, $ids);
    }
}
