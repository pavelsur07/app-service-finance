<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Application;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Application\Action\RebuildPnlPeriodAction;
use App\Finance\Application\Command\RebuildPnlPeriodCommand;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class RebuildPnlPeriodActionTest extends IntegrationTestCase
{
    public function testRebuildCreatesCanonicalDailyAndMonthlyTotals(): void
    {
        $company = $this->createCompanyWithDefaultProject();
        $companyId = (string) $company->getId();
        $this->persistTransaction($companyId, TransactionType::SALE, TransactionDirection::IN, 12345, '2026-02-10 10:00:00 UTC', 'sale-1');
        $this->persistTransaction($companyId, TransactionType::COMMISSION, TransactionDirection::OUT, 2345, '2026-02-10 12:00:00 UTC', 'commission-1');
        $this->em->flush();

        /** @var RebuildPnlPeriodAction $action */
        $action = self::getContainer()->get(RebuildPnlPeriodAction::class);
        $action(new RebuildPnlPeriodCommand($companyId, 2026, 2));

        /** @var PLDirtyPeriodRepository $dirtyRepository */
        $dirtyRepository = self::getContainer()->get(PLDirtyPeriodRepository::class);
        self::assertSame(PLDirtyPeriodStatus::DONE, $dirtyRepository->findOne($companyId, 2026, 2, '')?->getStatus());

        self::assertSame('123.45', $this->sumColumn('pl_daily_totals', $companyId, 'amount_income'));
        self::assertSame('23.45', $this->sumColumn('pl_daily_totals', $companyId, 'amount_expense'));
        self::assertSame('123.45', $this->sumColumn('pl_monthly_snapshots', $companyId, 'amount_income'));
        self::assertSame('23.45', $this->sumColumn('pl_monthly_snapshots', $companyId, 'amount_expense'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_daily_totals WHERE company_id = :company_id AND rebuilt_at IS NOT NULL', ['company_id' => $companyId]));
    }

    public function testRebuildMapsOutboundRefundToRefundCategory(): void
    {
        $company = $this->createCompanyWithDefaultProject();
        $companyId = (string) $company->getId();
        $this->persistTransaction($companyId, TransactionType::REFUND, TransactionDirection::OUT, 283700, '2026-02-11 10:00:00 UTC', 'refund-1');
        $this->em->flush();

        /** @var RebuildPnlPeriodAction $action */
        $action = self::getContainer()->get(RebuildPnlPeriodAction::class);
        $action(new RebuildPnlPeriodCommand($companyId, 2026, 2));

        $row = $this->connection->fetchAssociative(
            'SELECT c.code,
                    COALESCE(SUM(d.amount_income), 0)::numeric(18,2)::text AS amount_income,
                    COALESCE(SUM(d.amount_expense), 0)::numeric(18,2)::text AS amount_expense
             FROM pl_daily_totals d
             INNER JOIN pl_categories c ON c.id = d.pl_category_id
             WHERE d.company_id = :company_id
             GROUP BY c.code',
            ['company_id' => $companyId],
        );

        self::assertIsArray($row);
        self::assertSame('INGESTION_REFUND_OUT', $row['code']);
        self::assertSame('0.00', $row['amount_income']);
        self::assertSame('2837.00', $row['amount_expense']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM pl_daily_totals d
             INNER JOIN pl_categories c ON c.id = d.pl_category_id
             WHERE d.company_id = :company_id
               AND c.code = 'INGESTION_OTHER_EXPENSE'",
            ['company_id' => $companyId],
        ));
    }

    public function testSourceScopedRebuildIsFailedUntilFinanceSourceLinkingIsDecided(): void
    {
        $company = $this->createCompanyWithDefaultProject();
        $companyId = (string) $company->getId();

        /** @var RebuildPnlPeriodAction $action */
        $action = self::getContainer()->get(RebuildPnlPeriodAction::class);
        $action(new RebuildPnlPeriodCommand($companyId, 2026, 2, 'ozon:shop-1'));

        /** @var PLDirtyPeriodRepository $dirtyRepository */
        $dirtyRepository = self::getContainer()->get(PLDirtyPeriodRepository::class);
        $period = $dirtyRepository->findOne($companyId, 2026, 2, 'ozon:shop-1');

        self::assertSame(PLDirtyPeriodStatus::FAILED, $period?->getStatus());
        self::assertStringContainsString('source-linking', (string) $period?->getLastError());
    }

    private function createCompanyWithDefaultProject(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('pnl-rebuild@example.com');
        $user->setPassword('password');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('P&L rebuild company');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Основной');

        foreach ([$user, $company, $project] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $company;
    }

    private function persistTransaction(
        string $companyId,
        TransactionType $type,
        TransactionDirection $direction,
        int $amountMinor,
        string $occurredAt,
        string $externalId,
    ): void {
        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'ozon:shop-1',
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable($occurredAt),
            operationGroupId: Uuid::uuid7()->toString(),
            type: $type,
            direction: $direction,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable($occurredAt),
            rawRecordId: Uuid::uuid7()->toString(),
        ));
    }

    private function sumColumn(string $table, string $companyId, string $column): string
    {
        return (string) $this->connection->fetchOne(
            sprintf('SELECT COALESCE(SUM(%s), 0)::numeric(18,2)::text FROM %s WHERE company_id = :company_id', $column, $table),
            ['company_id' => $companyId],
        );
    }
}
