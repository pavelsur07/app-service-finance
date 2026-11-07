<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Application;

use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MonthCloseAdvisoryLockQuery;
use App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class ProcessOzonRealizationConcurrencyTest extends PostgresResetTestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function modes(): iterable
    {
        yield 'first processing' => [false, false];
        yield 'reprocessing' => [true, false];
        yield 'manual versus handler' => [false, true];
    }

    #[DataProvider('modes')]
    public function testConcurrentCallsKeepOneCopyIncludingLegitimateRepeatedRows(bool $reprocess, bool $withHandler): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)->withDocumentType('realization')->build();
        $sale = ['item' => ['sku' => '123'], 'delivery_commission' => ['price_per_instance' => 10, 'quantity' => 1]];
        $doc->setRawData(['result' => [
            'header' => ['start_date' => '2026-01-01', 'stop_date' => '2026-01-31'],
            'rows' => [$sale, $sale, ['item' => ['sku' => '123'], 'return_commission' => ['price_per_instance' => 2, 'quantity' => 1]]],
        ]]);
        foreach ([$user, $company, $doc] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = (string) $company->getId();
        $docId = $doc->getId();
        if ($reprocess) {
            self::getContainer()->get(ProcessOzonRealizationAction::class)($companyId, $docId);
        }
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $this->em->persist($otherCompany);
        $otherDocs = [];
        foreach ([[$company, '2026-02-01', '2026-02-28'], [$otherCompany, '2026-01-01', '2026-01-31']] as [$owner, $from, $to]) {
            $otherDoc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($owner)
                ->withMarketplace(MarketplaceType::OZON)->withDocumentType('realization')
                ->withPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($to))->build();
            $otherDoc->setRawData(['result' => ['header' => ['start_date' => $from, 'stop_date' => $to], 'rows' => [$sale]]]);
            $this->em->persist($otherDoc);
            $otherDocs[] = [(string) $owner->getId(), $otherDoc->getId()];
        }
        $this->em->flush();
        $key = 'ozon_race_'.str_replace('-', '', Uuid::uuid7()->toString());
        $db = $this->connection;
        // Hold every INSERT at a DB barrier. Old code lets both calls pass the
        // existence check; protected code makes the second wait on the month.
        $db->executeStatement("CREATE FUNCTION $key() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.raw_document_id = ''$docId''::uuid THEN PERFORM pg_advisory_xact_lock(hashtext(''$key'')); END IF; RETURN NEW; END'");
        $db->executeStatement("CREATE TRIGGER $key BEFORE INSERT ON marketplace_ozon_realizations FOR EACH ROW EXECUTE FUNCTION $key()");
        $db->fetchOne('SELECT pg_advisory_lock(hashtext(?))', [$key]);
        $processes = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $process = new Process([\PHP_BINARY, 'tests/Fixtures/Marketplace/process-ozon-realization.php'],
                    (string) self::getContainer()->getParameter('kernel.project_dir'),
                    ['OZON_RACE_COMPANY' => $companyId, 'OZON_RACE_DOCUMENT' => $docId, 'OZON_RACE_KEY' => $key,
                        'OZON_RACE_MODE' => $withHandler && 1 === $i ? 'handler' : 'direct', 'OZON_RACE_CONNECTION' => Uuid::uuid7()->toString()], null, 20);
                $process->start();
                $processes[] = $process;
                self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "ready\n")), $process->getErrorOutput());
            }
            $deadline = microtime(true) + 8;
            do {
                $waiting = (int) $db->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$key]);
                if (2 !== $waiting) {
                    usleep(10000);
                }
            } while (2 !== $waiting && microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both calls must overlap before releasing the INSERT barrier.');
            foreach ($otherDocs as [$otherCompanyId, $otherDocId]) {
                $other = new Process([\PHP_BINARY, 'tests/Fixtures/Marketplace/process-ozon-realization.php'],
                    (string) self::getContainer()->getParameter('kernel.project_dir'),
                    ['OZON_RACE_COMPANY' => $otherCompanyId, 'OZON_RACE_DOCUMENT' => $otherDocId, 'OZON_RACE_KEY' => $key.'_other'], null, 5);
                self::assertSame(0, $other->run(), $other->getErrorOutput());
                self::assertStringContainsString("done\n", $other->getOutput(), 'Another company/month must not wait for this month.');
            }
            $db->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                self::assertStringContainsString("done\n", $process->getOutput());
            }
            self::assertSame(['n' => 3, 'sales' => '20.00', 'returns' => '2.00'], $db->fetchAssociative(
                'SELECT COUNT(*)::int n, SUM(total_amount)::text sales, SUM(return_amount)::text returns FROM marketplace_ozon_realizations WHERE company_id = ? AND raw_document_id = ?', [$companyId, $docId],
            ));
            if ($withHandler) {
                self::assertSame('success', $db->fetchOne("SELECT status FROM marketplace_financial_report_sync_statuses WHERE company_id = ? AND report_type = 'ozon_realization'", [$companyId]));
            }
        } finally {
            $db->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $db->executeStatement("DROP TRIGGER $key ON marketplace_ozon_realizations");
            $db->executeStatement("DROP FUNCTION $key()");
        }
    }

    public function testWaitingCallRechecksFinalClosureAfterAcquiringTheMonthLock(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withDocumentType('realization')->build();
        $doc->setRawData(['result' => ['header' => ['start_date' => '2026-01-01', 'stop_date' => '2026-01-31'],
            'rows' => [['item' => ['sku' => '123'], 'delivery_commission' => ['price_per_instance' => 10, 'quantity' => 1]]]]]);
        foreach ([$user, $company, $doc] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = (string) $company->getId();
        $key = 'ozon_close_'.str_replace('-', '', Uuid::uuid7()->toString());
        $this->connection->beginTransaction();
        self::getContainer()->get(MonthCloseAdvisoryLockQuery::class)->lock($companyId, MarketplaceType::OZON, 2026, 1);
        $process = new Process([\PHP_BINARY, 'tests/Fixtures/Marketplace/process-ozon-realization.php'],
            (string) self::getContainer()->getParameter('kernel.project_dir'),
            ['OZON_RACE_COMPANY' => $companyId, 'OZON_RACE_DOCUMENT' => $doc->getId(), 'OZON_RACE_KEY' => $key], null, 15);
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "ready\n")), $process->getErrorOutput());
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$key]);
                if (1 !== $waiting) {
                    usleep(10000);
                }
            } while (1 !== $waiting && microtime(true) < $deadline);
            self::assertSame(1, $waiting);
            $month = new MarketplaceMonthClose(Uuid::uuid7()->toString(), $companyId, MarketplaceType::OZON, 2026, 1);
            $month->closeStage(CloseStage::SALES_RETURNS, (string) $user->getId(), [], []);
            $this->em->persist($month);
            $this->em->flush();
            $this->connection->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertStringContainsString('rejected:', $process->getOutput());
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_realizations WHERE company_id = ?', [$companyId]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}
