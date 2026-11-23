<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class AutomaticMappingConcurrencyTest extends PostgresResetTestCase
{
    use MappingFixtures;

    public function testBusyListingReturnsRetryableConflictWithinBoundedWait(): void
    {
        $input = $this->input();
        $processes = [];
        $this->connection->beginTransaction();
        try {
            $this->connection->fetchOne('SELECT id FROM marketplace_listings WHERE id=? FOR KEY SHARE', [$input['listing']]);
            $processes[] = $this->worker($input + ['mode' => 'auto']);
            $started = microtime(true);
            self::assertSame(0, $processes[0]->wait(), $processes[0]->getErrorOutput());
            self::assertStringContainsString('rejected:listing_busy', $processes[0]->getOutput());
            self::assertLessThan(10, microtime(true) - $started);
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        } finally {
            $this->finish($processes);
        }
    }

    #[DataProvider('barcodeMutations')]
    public function testSourceLockProtectsBarcodeInsertAndTransfer(string $mode): void
    {
        $input = $this->input();
        $company = $this->em->getReference(\App\Company\Entity\Company::class, $input['company']);
        self::assertInstanceOf(\App\Company\Entity\Company::class, $company);
        $other = \App\Tests\Builders\Marketplace\MarketplaceListingBuilder::aListing()->forCompany($company)->withIndex(2)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withMarketplaceSku('OTHER')->build();
        $this->em->persist($other);
        $this->em->flush();
        if ('barcode_move' === $mode) {
            $this->connection->executeStatement("INSERT INTO marketplace_listing_barcodes (id,listing_id,company_id,marketplace,barcode) VALUES (?, ?, ?, 'ozon', '000123')", [Uuid::uuid7()->toString(), $input['listing'], $input['company']]);
        }
        $processes = [];
        $this->connection->beginTransaction();
        try {
            self::getContainer()->get(MarketplaceFacade::class)->lockListingMetaForExternalMapping($input['company'], $input['listing']);
            $processes[] = $this->worker($input + ['mode' => $mode, 'other' => $other->getId()]);
            $this->assertWaiting($input['application']);
            $this->connection->commit();
            self::assertSame(0, $processes[0]->wait(), $processes[0]->getErrorOutput());
            self::assertStringContainsString('success:barcode', $processes[0]->getOutput());
        } finally {
            $this->finish($processes);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function barcodeMutations(): iterable
    {
        yield 'new barcode' => ['barcode_insert'];
        yield 'barcode moved away' => ['barcode_move'];
    }

    #[DataProvider('catalogOrders')]
    public function testActualCatalogImportAndAutomaticWriterUseSameSessionLock(bool $importFirst, string $kind): void
    {
        $input = $this->input();
        $application = $input['application'];
        $processes = [];
        $this->connection->beginTransaction();
        try {
            $this->pause($application);
            $first = $input + ['mode' => $importFirst ? 'sync_'.$kind.'_paused' : 'auto_paused'];
            $processes[] = $this->worker($first);
            $this->assertWaiting($application);
            $second = array_replace($input, ['application' => $application.'-second', 'mode' => $importFirst ? 'auto' : 'sync_'.$kind]);
            $processes[] = $this->worker($second);
            self::assertSame(0, $processes[1]->wait(), $processes[1]->getErrorOutput());
            self::assertStringContainsString($importFirst ? 'rejected:catalog_busy' : 'retry:temporary', $processes[1]->getOutput());
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
            $this->connection->commit();
            self::assertSame(0, $processes[0]->wait(), $processes[0]->getErrorOutput());
            self::assertStringContainsString($importFirst ? 'success:imported' : 'success:1', $processes[0]->getOutput());
            if ($importFirst) {
                self::assertSame('duplicate' === $kind ? 2 : 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_products'));
                $retry = $this->worker(array_replace($input, ['mode' => 'auto']));
                $processes[] = $retry;
                self::assertSame(0, $retry->wait(), $retry->getErrorOutput());
                self::assertStringContainsString('rejected:automatic_mapping_conflict', $retry->getOutput());
            }
            self::assertSame($importFirst ? 0 : 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        } finally {
            $this->finish($processes);
        }
    }

    #[DataProvider('orders')]
    public function testSourceUpdateAndActualAutomaticWriterSerializeFreshMetadata(bool $sourceFirst): void
    {
        $input = $this->input();
        $processes = [];
        $this->connection->beginTransaction();
        try {
            if ($sourceFirst) {
                $this->connection->executeStatement("UPDATE marketplace_listings SET supplier_sku='CHANGED' WHERE id=?", [$input['listing']]);
                $processes[] = $this->worker($input + ['mode' => 'auto']);
                $this->assertWaiting($input['application']);
            } else {
                $this->pause($input['application']);
                $processes[] = $this->worker($input + ['mode' => 'auto_paused']);
                $this->assertWaiting($input['application']);
                $processes[] = $this->worker(array_replace($input, ['mode' => 'source', 'application' => $input['application'].'-source']));
                $this->assertWaiting($input['application'].'-source');
            }
            $this->connection->commit();
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
            }
            self::assertStringContainsString($sourceFirst ? 'rejected:automatic_mapping_conflict' : 'success:1', $processes[0]->getOutput());
            self::assertSame($sourceFirst ? 0 : 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        } finally {
            $this->finish($processes);
        }
    }

    public function testIdenticalAutomaticPostsAreAcceptedOnce(): void
    {
        $input = $this->input();
        $processes = [];
        $this->connection->beginTransaction();
        try {
            $this->pause($input['application']);
            $processes[] = $this->worker($input + ['mode' => 'auto_paused']);
            $this->assertWaiting($input['application']);
            $processes[] = $this->worker(array_replace($input, ['mode' => 'auto', 'application' => $input['application'].'-replay']));
            $this->assertWaiting($input['application'].'-replay');
            $this->connection->commit();
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                self::assertStringContainsString('success:1', $process->getOutput());
            }
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        } finally {
            $this->finish($processes);
        }
    }

    public function testAnotherRunningImportSkipsDuplicateWithoutRetryOrErrorLog(): void
    {
        $input = $this->input();
        $processes = [];
        $this->connection->beginTransaction();
        try {
            $this->pause($input['application']);
            $processes[] = $this->worker($input + ['mode' => 'sync_duplicate_paused']);
            $this->assertWaiting($input['application']);
            $processes[] = $this->worker(array_replace($input, ['mode' => 'sync_duplicate_handler', 'application' => $input['application'].'-duplicate']));
            self::assertSame(0, $processes[1]->wait(), $processes[1]->getErrorOutput());
            self::assertStringContainsString('success:handler:0:0', $processes[1]->getOutput());
            $this->connection->commit();
            self::assertSame(0, $processes[0]->wait(), $processes[0]->getErrorOutput());
            self::assertStringContainsString('success:imported', $processes[0]->getOutput());
        } finally {
            $this->finish($processes);
        }
    }

    public function testFacadeLockRequiresTransaction(): void
    {
        $input = $this->input();
        $this->expectException(\LogicException::class);
        self::getContainer()->get(MarketplaceFacade::class)->lockListingMetaForExternalMapping($input['company'], $input['listing']);
    }

    public function testFacadeLockScopesTenantAndReadsFreshDbalMetadata(): void
    {
        $input = $this->input();
        $this->connection->beginTransaction();
        try {
            $facade = self::getContainer()->get(MarketplaceFacade::class);
            self::assertNull($facade->lockListingMetaForExternalMapping(Uuid::uuid7()->toString(), $input['listing']));
            $this->connection->executeStatement("UPDATE marketplace_listings SET supplier_sku='FRESH' WHERE id=?", [$input['listing']]);
            self::assertSame('FRESH', $facade->lockListingMetaForExternalMapping($input['company'], $input['listing'])?->supplierSku);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return iterable<string,array{bool,string}> */
    public static function catalogOrders(): iterable
    {
        foreach (['duplicate', 'archive'] as $kind) {
            yield $kind.' other writer first' => [true, $kind];
            yield $kind.' automatic writer first' => [false, $kind];
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function orders(): iterable
    {
        yield 'other writer first' => [true];
        yield 'automatic writer first' => [false];
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        $data = $this->mappingCatalog($this->em);
        $data['connection']->setAccessToken('local-test-token');
        $this->em->flush();
        $company = $data['connection']->getCompanyId();
        $source = self::getContainer()->get(MarketplaceFacade::class)->getListingsMetaByIds($company, [$data['listing']->getId()])[$data['listing']->getId()];

        $decision = self::getContainer()->get(\App\MoySklad\Infrastructure\Query\AutomaticMappingPreviewQuery::class)->page($company, $data['connection']->getId(), 'ozon', '', 1, null)->decisions[$source->id];

        return ['company' => $company, 'connection' => $data['connection']->getId(), 'listing' => $source->id, 'product' => $data['product']->getExternalId(),
            'request' => Uuid::uuid7()->toString(), 'application' => 'auto-race-'.Uuid::uuid7()->toString(),
            'guard' => ['previewId' => Uuid::uuid7()->toString(), 'ruleVersion' => \App\MoySklad\Application\DTO\AutomaticMappingGuard::RULE_VERSION, 'sourceFingerprint' => (new AutomaticListingMappingPolicy())->sourceFingerprint($source), 'sizeCharacteristicId' => null, 'candidateFingerprint' => $decision->fingerprint()]];
    }

    private function pause(string $application): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?),hashtext(?))', ['mapping-test-pause', $application]);
    }

    /** @param array<string,mixed> $input */
    private function worker(array $input): Process
    {
        $process = new Process([\PHP_BINARY, 'tests/Fixtures/MoySklad/mapping-worker.php'], self::getContainer()->getParameter('kernel.project_dir'), input: json_encode($input, \JSON_THROW_ON_ERROR), timeout: 15);
        $process->start();
        self::assertTrue($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, "ready\n")), $process->getErrorOutput());

        return $process;
    }

    private function assertWaiting(string $application): void
    {
        $deadline = microtime(true) + 5;
        do {
            $this->connection->fetchOne('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$application]);
            if (1 === $waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::assertSame(1, $waiting, 'Actual independent sessions must contend.');
    }

    /** @param list<Process> $processes */
    private function finish(array $processes): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
