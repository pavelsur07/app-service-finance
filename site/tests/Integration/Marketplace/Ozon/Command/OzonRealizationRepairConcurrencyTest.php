<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Command;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Company\Entity\Company;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Application\ReopenMonthStageAction;
use App\Marketplace\Enum\CloseStage;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use App\Tests\Support\Marketplace\OzonRealizationRepairFixture;
use Doctrine\DBAL\DriverManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class OzonRealizationRepairConcurrencyTest extends PostgresResetTestCase
{
    public function testRejectedReopenLeavesEntityManagerUsableForNextStage(): void
    {
        OzonRealizationRepairFixture::seed($this->em, self::getContainer()->get(\App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction::class), self::getContainer()->get(\App\Marketplace\Application\CloseMonthStageAction::class));
        $companyId = OzonRealizationRepairFixture::COMPANY;
        $this->connection->executeStatement('UPDATE companies SET finance_lock_before = ? WHERE id = ?', ['2026-10-31', $companyId]);
        try {
            self::getContainer()->get(ReopenMonthStageAction::class)(new ReopenMonthStageCommand($companyId, 'ozon', 2026, 10, CloseStage::SALES_RETURNS));
            self::fail('Expected a business refusal.');
        } catch (\DomainException) {
        }
        $nextUser = \App\Tests\Builders\Company\UserBuilder::aUser()->withIndex(2)->build();
        $this->em->persist($nextUser);
        $this->em->flush();
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [(string) $nextUser->getId()]));
    }

    public function testReopenRechecksFinanceLockEvenWithAPreloadedCompany(): void
    {
        OzonRealizationRepairFixture::seed($this->em, self::getContainer()->get(\App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction::class), self::getContainer()->get(\App\Marketplace\Application\CloseMonthStageAction::class));
        $companyId = OzonRealizationRepairFixture::COMPANY;
        $company = $this->em->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        self::assertNull($company->getFinanceLockBefore());
        $other = DriverManager::getConnection($this->connection->getParams());
        $other->executeStatement('UPDATE companies SET finance_lock_before = ? WHERE id = ?', ['2026-10-31', $companyId]);
        $other->close();
        $this->expectException(\DomainException::class);
        try {
            self::getContainer()->get(ReopenMonthStageAction::class)(new ReopenMonthStageCommand($companyId, 'ozon', 2026, 10, CloseStage::SALES_RETURNS));
        } finally {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM documents WHERE company_id = ?', [$companyId]));
            self::assertSame('closed', $this->connection->fetchOne('SELECT stage_sales_returns_status FROM marketplace_month_closes WHERE company_id = ?', [$companyId]));
        }
    }

    public function testOrdinaryProcessingAndCachedFactsCannotRemainStaleAfterRepairCommit(): void
    {
        $hash = OzonRealizationRepairFixture::seed($this->em, self::getContainer()->get(\App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction::class), self::getContainer()->get(\App\Marketplace\Application\CloseMonthStageAction::class));
        $key = 'ozon_repair_'.str_replace('-', '', Uuid::uuid7()->toString());
        $db = $this->connection;
        $raw = OzonRealizationRepairFixture::RAW;
        $companyId = OzonRealizationRepairFixture::COMPANY;
        // Pause after the final register rebuild and its early cache invalidations,
        // just before the repair's outer commit. A reader still sees old facts.
        $db->executeStatement("CREATE FUNCTION $key() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.company_id = ''$companyId''::uuid AND NEW.stage_sales_returns_status = ''closed'' THEN PERFORM pg_advisory_xact_lock(hashtext(''$key'')); END IF; RETURN NEW; END'");
        $db->executeStatement("CREATE TRIGGER $key BEFORE UPDATE ON marketplace_month_closes FOR EACH ROW EXECUTE FUNCTION $key()");
        $db->fetchOne('SELECT pg_advisory_lock(hashtext(?))', [$key]);
        $env = ['OZON_RACE_COMPANY' => OzonRealizationRepairFixture::COMPANY, 'OZON_RACE_DOCUMENT' => $raw, 'OZON_RACE_KEY' => $key];
        $cwd = (string) self::getContainer()->getParameter('kernel.project_dir');
        $repair = new Process([\PHP_BINARY, 'tests/Fixtures/Marketplace/process-ozon-realization.php'], $cwd, $env + ['OZON_RACE_MODE' => 'repair', 'OZON_RAW_HASH' => $hash], null, 20);
        $ordinary = new Process([\PHP_BINARY, 'tests/Fixtures/Marketplace/process-ozon-realization.php'], $cwd, $env, null, 20);
        try {
            $repair->start();
            self::assertTrue($repair->waitUntil(static fn (string $type, string $out): bool => str_contains($out, "ready\n")), $repair->getErrorOutput());
            $this->waitForLocks($key, 1);
            $ordinary->start();
            self::assertTrue($ordinary->waitUntil(static fn (string $type, string $out): bool => str_contains($out, "ready\n")), $ordinary->getErrorOutput());
            $this->waitForLocks($key, 2);
            $company = $this->em->find(Company::class, $companyId);
            self::assertInstanceOf(Company::class, $company);
            $cache = self::getContainer()->get(SnapshotCacheInvalidator::class);
            $versionDuringRepair = $cache->resolveVersionForCompany($company);
            self::assertSame('40.00', $db->fetchOne('SELECT SUM(amount_income)::text FROM pl_daily_totals WHERE company_id = ?', [$companyId]));
            $db->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            self::assertSame(0, $repair->wait(), $repair->getErrorOutput().$repair->getOutput());
            self::assertStringContainsString('repaired;', $repair->getOutput());
            self::assertGreaterThan($versionDuringRepair, $cache->resolveVersionForCompany($company), 'A snapshot cached before outer commit must be invalidated again afterwards.');
            self::assertSame(0, $ordinary->wait(), $ordinary->getErrorOutput());
            self::assertStringContainsString('rejected:', $ordinary->getOutput());
            self::assertSame(['rows' => 3, 'sales' => '20.00', 'returns' => '2.00'], $db->fetchAssociative('SELECT COUNT(*)::int rows,SUM(total_amount)::text sales,SUM(return_amount)::text returns FROM marketplace_ozon_realizations WHERE company_id = ? AND raw_document_id = ?', [OzonRealizationRepairFixture::COMPANY, $raw]));
            self::assertSame(['REV_SPP_RETURNS' => '-2.00', 'REV_SPP_SALES' => '20.00'], $db->fetchAllKeyValue('SELECT c.code,(SUM(t.amount_income)-SUM(t.amount_expense))::text FROM pl_daily_totals t JOIN pl_categories c ON c.id=t.pl_category_id AND c.company_id=t.company_id WHERE t.company_id = ? GROUP BY c.code ORDER BY c.code', [OzonRealizationRepairFixture::COMPANY]));
        } finally {
            $db->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            foreach ([$repair, $ordinary] as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $db->executeStatement("DROP TRIGGER $key ON marketplace_month_closes");
            $db->executeStatement("DROP FUNCTION $key()");
        }
    }

    private function waitForLocks(string $key, int $expected): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$key]);
            if ($expected !== $waiting) {
                usleep(10000);
            }
        } while ($expected !== $waiting && microtime(true) < $deadline);
        self::assertSame($expected, $waiting, 'Expected processes to overlap in the repair critical section.');
    }
}
