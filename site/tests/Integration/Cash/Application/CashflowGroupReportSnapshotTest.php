<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Report\BuildCashflowGroupReport;
use App\Company\Entity\Company;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\IntegrationTestCase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use Ramsey\Uuid\Uuid;

final class CashflowGroupReportSnapshotTest extends IntegrationTestCase
{
    use IntragroupReportFixture;

    public function testRejectsExistingReadCommittedTransactionBeforeLookingUpAGroup(): void
    {
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $date = new \DateTimeImmutable('2026-11-01');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('REPEATABLE READ');

        self::getContainer()->get(BuildCashflowGroupReport::class)(Uuid::uuid7()->toString(), $date, $date);
    }

    #[SkipDatabaseRollback]
    public function testReclassificationFromAnotherConnectionAppearsOnlyInANewSnapshot(): void
    {
        $this->connection->transactional(function (): void {
            $this->createGroupFixture();
            $this->fillMissingInns();
            $this->addTransaction(991, '0001', '2026-11-04', '-125.25', 'ООО "Бета"', 'OP_OUT_OTHER');
            $this->addTransaction(992, '0003', '2026-11-04', '125.25', 'ООО "Альфа"', 'OP_IN_OTHER');
            $this->em->flush();
        });
        $companyId = (string) $this->companies['Альфа']->getId();
        $writer = DriverManager::getConnection($this->connection->getParams());
        try {
            $this->connection->transactional(function () use ($writer, $companyId): void {
                $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $old = array_column($this->report()->lines, null, 'key');
                self::assertSame('125.25', $old['OP_OUT_OTHER']->elimination);
                self::assertSame('0.00', $old['OP_OUT_MARKETING']->elimination);

                self::assertSame(1, $writer->executeStatement(
                    'UPDATE cash_transaction_split SET cashflow_category_id = (SELECT id FROM cashflow_categories WHERE company_id = ? AND group_code = ?) WHERE company_id = ? AND cash_transaction_id = ?',
                    [$companyId, 'OP_OUT_MARKETING', $companyId, self::transactionId(991)],
                ));
                $current = array_column($this->report()->lines, null, 'key');
                foreach (['OP_OUT_OTHER', 'OP_OUT_MARKETING'] as $code) {
                    self::assertSame($old[$code]->amounts, $current[$code]->amounts);
                    self::assertSame($old[$code]->elimination, $current[$code]->elimination);
                }
            });
            $next = array_column($this->report()->lines, null, 'key');
            self::assertSame('0.00', $next['OP_OUT_OTHER']->elimination);
            self::assertSame('125.25', $next['OP_OUT_MARKETING']->elimination);
            self::assertSame('-125.25', $next['OP_OUT_MARKETING']->amounts[$companyId]);
        } finally {
            $writer->close();
            $ids = array_values(array_map(static fn (Company $c): string => (string) $c->getId(), $this->companies));
            $this->connection->transactional(function () use ($ids): void {
                foreach (['cash_transaction', 'money_account', 'counterparty', 'cashflow_categories', 'company_consolidation_member'] as $table) {
                    $this->connection->executeStatement('DELETE FROM '.$table.' WHERE company_id IN (?)', [$ids], [ArrayParameterType::STRING]);
                }
                $this->connection->executeStatement('DELETE FROM companies WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
                $owner = $this->companies['Альфа']->getUser();
                self::assertNotNull($owner);
                $this->connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$owner->getId()]);
            });
        }
    }
}
