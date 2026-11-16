<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Infrastructure\Query\MappingDiagnosticsQuery;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use App\MoySklad\MessageHandler\DiagnoseMappingsHandler;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;

final class MappingDiagnosticsTest extends PostgresResetTestCase
{
    use MappingFixtures;

    public function testWholeAccountUsesTransactionsOfAtMostHundredAndReplayDoesNotDuplicateRows(): void
    {
        $d = $this->mappingCatalog($this->em);
        foreach (range(2, 205) as $i) {
            $this->em->persist(MarketplaceListingBuilder::aListing()->forCompany($d['company'])->withIndex($i)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em->flush();
        $this->connection->executeStatement('CREATE TEMP TABLE ms_diagnostic_batch_counts (txid BIGINT PRIMARY KEY, rows INT NOT NULL)');
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.enforce_diagnostic_batch() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE n INT;
            BEGIN
                INSERT INTO ms_diagnostic_batch_counts VALUES (txid_current(), 1)
                ON CONFLICT (txid) DO UPDATE SET rows=ms_diagnostic_batch_counts.rows+1 RETURNING rows INTO n;
                IF n > 100 THEN RAISE EXCEPTION 'diagnostic batch exceeds 100'; END IF;
                RETURN NEW;
            END $$
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER test_diagnostic_batch BEFORE INSERT ON moysklad_mapping_diagnostic_rows FOR EACH ROW EXECUTE FUNCTION pg_temp.enforce_diagnostic_batch()');
        try {
            $message = new DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', Uuid::uuid7()->toString());
            $handler = self::getContainer()->get(DiagnoseMappingsHandler::class);
            $holder = self::getContainer()->get('doctrine.debug_data_holder');
            $holder->reset();
            $handler($message);
            $handler($message);
            /** @var array<string,list<array{sql:string}>> $queries */
            $queries = $holder->getData();
            $catalogScans = array_filter($queries['default'] ?? [], static fn (array $query): bool => str_contains($query['sql'], 'string_agg(hash'));
            self::assertCount(2, $catalogScans, 'Whole-account fingerprints are bounded to initialization and final publication, independent of batches/replay.');
            $view = self::getContainer()->get(MappingDiagnosticsQuery::class)->page($message->companyId, $message->connectionId, 'ozon', 1);
            self::assertSame('completed', $view->latestRun?->status);
            self::assertSame(205, $view->completedRun?->processed);
            self::assertSame(205, $view->completedRun->counts['ready']);
            self::assertSame(205, $view->totalItems);
            self::assertCount(25, $view->rows);
            self::assertFalse($view->stale);
            self::assertSame(205, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_diagnostic_rows'));
            self::assertSame(100, (int) $this->connection->fetchOne('SELECT MAX(rows) FROM ms_diagnostic_batch_counts'));
            self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ms_diagnostic_batch_counts'));
            self::assertNull(self::getContainer()->get(MappingDiagnosticsQuery::class)->page(Uuid::uuid7()->toString(), $message->connectionId, 'ozon', 1)->completedRun);
        } finally {
            $this->connection->executeStatement('DROP TRIGGER test_diagnostic_batch ON moysklad_mapping_diagnostic_rows');
        }
    }

    public function testFailurePreservesLastCompletedAndRetryResumesCommittedBatch(): void
    {
        $d = $this->mappingCatalog($this->em);
        $message = new DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', Uuid::uuid7()->toString());
        $handler = self::getContainer()->get(DiagnoseMappingsHandler::class);
        $handler($message);
        foreach (range(2, 101) as $i) {
            $this->em->persist(MarketplaceListingBuilder::aListing()->forCompany($d['company'])->withIndex($i)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em->flush();
        $next = new DiagnoseMappingsMessage($message->companyId, $message->connectionId, 'ozon', Uuid::uuid7()->toString());
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.fail_second_diagnostic_batch() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT COUNT(*) FROM moysklad_mapping_diagnostic_rows WHERE run_id=NEW.run_id) >= 100 THEN
                    RAISE EXCEPTION 'simulated diagnostic failure';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER test_diagnostic_failure BEFORE INSERT ON moysklad_mapping_diagnostic_rows FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_second_diagnostic_batch()');
        try {
            $handler($next);
            self::fail('Second batch must fail.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('simulated diagnostic failure', $e->getMessage());
        } finally {
            $this->connection->executeStatement('DROP TRIGGER test_diagnostic_failure ON moysklad_mapping_diagnostic_rows');
        }
        $query = self::getContainer()->get(MappingDiagnosticsQuery::class);
        $failed = $query->page($message->companyId, $message->connectionId, 'ozon', 1);
        self::assertSame('failed', $failed->latestRun?->status);
        self::assertSame(100, $failed->latestRun->processed);
        self::assertSame($message->runId, $failed->completedRun?->id);
        self::assertTrue($failed->stale);
        $handler($next);
        $done = $query->page($message->companyId, $message->connectionId, 'ozon', 1);
        self::assertSame($next->runId, $done->completedRun?->id);
        self::assertSame(101, $done->totalItems);
        self::assertFalse($done->stale);
        self::assertSame(101, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_diagnostic_rows WHERE run_id=?', [$next->runId]));
    }

    public function testChangedInputDuringScanDoesNotReplaceCompletedReport(): void
    {
        $d = $this->mappingCatalog($this->em);
        $message = new DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', Uuid::uuid7()->toString());
        $handler = self::getContainer()->get(DiagnoseMappingsHandler::class);
        $handler($message);
        foreach (range(2, 205) as $i) {
            $this->em->persist(MarketplaceListingBuilder::aListing()->forCompany($d['company'])->withIndex($i)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em->flush();
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.change_diagnostic_source() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE marketplace_listings SET name='Changed during scan' WHERE id=NEW.listing_id;
                RETURN NEW;
            END $$
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER test_diagnostic_change AFTER INSERT ON moysklad_mapping_diagnostic_rows FOR EACH ROW EXECUTE FUNCTION pg_temp.change_diagnostic_source()');
        try {
            $handler(new DiagnoseMappingsMessage($message->companyId, $message->connectionId, 'ozon', Uuid::uuid7()->toString()));
        } finally {
            $this->connection->executeStatement('DROP TRIGGER test_diagnostic_change ON moysklad_mapping_diagnostic_rows');
        }
        $view = self::getContainer()->get(MappingDiagnosticsQuery::class)->page($message->companyId, $message->connectionId, 'ozon', 1);
        self::assertSame('stale', $view->latestRun?->status);
        self::assertSame(205, $view->latestRun->processed, 'Drift in a committed early batch prevents publication after the final batch.');
        self::assertSame($message->runId, $view->completedRun?->id);
        self::assertTrue($view->stale);
    }

    public function testConfirmationInvalidatesReportAfterListingChangesMarketplace(): void
    {
        $d = $this->mappingCatalog($this->em);
        $company = $d['connection']->getCompanyId();
        $connection = $d['connection']->getId();
        $id = $d['listing']->getId();
        $this->connection->executeStatement("UPDATE marketplace_listings SET name='Набор 3 шт' WHERE id=?", [$id]);
        $this->connection->executeStatement("UPDATE moysklad_products SET name='Товар 1 шт' WHERE external_id=?", [$d['product']->getExternalId()]);
        $confirm = function (int $revision) use ($d, $company, $connection, $id): void {
            $source = self::getContainer()->get(\App\Marketplace\Facade\MarketplaceFacade::class)->getListingsMetaByIds($company, [$id])[$id];
            $target = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery::class)->find($company, $connection, 'product', $d['product']->getExternalId());
            self::assertNotNull($target);
            $fingerprint = (new \App\MoySklad\Domain\AutomaticListingMappingPolicy())->packageReviewFingerprint($source, $target);
            self::getContainer()->get(\App\MoySklad\Application\Action\ChangeListingMappingAction::class)(new \App\MoySklad\Application\Command\ChangeListingMappingCommand($company, $d['actor'], $connection, $id, \App\MoySklad\Enum\ListingMappingOperation::SET, 'product', $target->externalId, $revision, Uuid::uuid7()->toString(), packageReviewConfirmed: true, packageReviewFingerprint: $fingerprint));
        };
        $confirm(0);
        $this->connection->executeStatement("UPDATE marketplace_listings SET marketplace='wildberries', name='Набор 5 шт' WHERE id=?", [$id]);
        self::getContainer()->get(DiagnoseMappingsHandler::class)(new DiagnoseMappingsMessage($company, $connection, 'wildberries', Uuid::uuid7()->toString()));
        $query = self::getContainer()->get(MappingDiagnosticsQuery::class);
        self::assertFalse($query->page($company, $connection, 'wildberries', 1)->stale);
        $confirm(1);
        self::assertTrue($query->page($company, $connection, 'wildberries', 1)->stale);
    }

    public function testSourceCatalogSettingsAssignmentsAndConfirmationsInvalidateCompletedReport(): void
    {
        $d = $this->mappingCatalog($this->em);
        $message = new DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', Uuid::uuid7()->toString());
        $handler = self::getContainer()->get(DiagnoseMappingsHandler::class);
        $handler($message);
        $query = self::getContainer()->get(MappingDiagnosticsQuery::class);
        self::assertFalse($query->page($message->companyId, $message->connectionId, 'ozon', 1)->stale);
        $this->connection->executeStatement("UPDATE marketplace_listings SET name='Набор 3 шт' WHERE id=?", [$d['listing']->getId()]);
        self::assertTrue($query->page($message->companyId, $message->connectionId, 'ozon', 1)->stale);
        $message = new DiagnoseMappingsMessage($message->companyId, $message->connectionId, 'ozon', Uuid::uuid7()->toString());
        $handler($message);
        $this->connection->executeStatement("UPDATE moysklad_products SET name='Набор 1 шт' WHERE external_id=?", [$d['product']->getExternalId()]);
        self::assertTrue($query->page($message->companyId, $message->connectionId, 'ozon', 1)->stale);
        $message = new DiagnoseMappingsMessage($message->companyId, $message->connectionId, 'ozon', Uuid::uuid7()->toString());
        $handler($message);
        $filtered = $query->page($message->companyId, $message->connectionId, 'ozon', 1, reason: 'package_review', manualReview: true);
        self::assertSame(1, $filtered->totalItems);
        self::assertSame('package_review', $filtered->rows[0]->decision->status->value);
        $this->connection->executeStatement("INSERT INTO moysklad_mapping_settings VALUES (?, ?, 'ozon', FALSE, 1)", [$message->companyId, $message->connectionId]);
        self::assertTrue($query->page($message->companyId, $message->connectionId, 'ozon', 1)->stale);
        $message = new DiagnoseMappingsMessage($message->companyId, $message->connectionId, 'ozon', Uuid::uuid7()->toString());
        $handler($message);
        $facade = self::getContainer()->get(\App\Marketplace\Facade\MarketplaceFacade::class);
        $target = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery::class)->find($message->companyId, $message->connectionId, 'product', $d['product']->getExternalId());
        self::assertNotNull($target);
        $policy = new \App\MoySklad\Domain\AutomaticListingMappingPolicy();
        $writer = self::getContainer()->get(\App\MoySklad\Application\Action\ChangeListingMappingAction::class);
        $fingerprint = $policy->packageReviewFingerprint($facade->getListingsMetaByIds($message->companyId, [$d['listing']->getId()])[$d['listing']->getId()], $target);
        $writer(new \App\MoySklad\Application\Command\ChangeListingMappingCommand($message->companyId, $d['actor'], $message->connectionId, $d['listing']->getId(), \App\MoySklad\Enum\ListingMappingOperation::SET, 'product', $target->externalId, 0, Uuid::uuid7()->toString(), packageReviewConfirmed: true, packageReviewFingerprint: $fingerprint));
        self::assertTrue($query->page($message->companyId, $message->connectionId, 'ozon', 1)->stale);
        $this->connection->executeStatement("UPDATE marketplace_listings SET name='Набор 5 шт' WHERE id=?", [$d['listing']->getId()]);
        $message = new DiagnoseMappingsMessage($message->companyId, $message->connectionId, 'ozon', Uuid::uuid7()->toString());
        $handler($message);
        self::assertFalse($query->page($message->companyId, $message->connectionId, 'ozon', 1)->stale);
        $fingerprint = $policy->packageReviewFingerprint($facade->getListingsMetaByIds($message->companyId, [$d['listing']->getId()])[$d['listing']->getId()], $target);
        $writer(new \App\MoySklad\Application\Command\ChangeListingMappingCommand($message->companyId, $d['actor'], $message->connectionId, $d['listing']->getId(), \App\MoySklad\Enum\ListingMappingOperation::SET, 'product', $target->externalId, 1, Uuid::uuid7()->toString(), packageReviewConfirmed: true, packageReviewFingerprint: $fingerprint));
        self::assertTrue($query->page($message->companyId, $message->connectionId, 'ozon', 1)->stale);
    }
}
