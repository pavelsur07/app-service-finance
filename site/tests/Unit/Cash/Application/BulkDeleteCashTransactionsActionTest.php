<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\BulkDeleteCashTransactionsAction;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BulkDeleteCashTransactionsActionTest extends TestCase
{
    /**
     * @param mixed[] $selection
     */
    #[DataProvider('invalidSelections')]
    public function testRejectsInvalidSelectionBeforeRepositoryLookup(array $selection): void
    {
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->build();
        $repository = $this->createMock(CashTransactionRepository::class);
        $repository->expects(self::never())->method('findActiveByIdsAndCompanyId');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $recalculator = $this->createMock(DailyBalanceRecalculator::class);
        $recalculator->expects(self::never())->method('recalcRange');

        $action = new BulkDeleteCashTransactionsAction(
            $repository,
            $entityManager,
            $recalculator,
            new SnapshotCacheInvalidator(new ArrayAdapter()),
        );

        $this->expectException(\DomainException::class);
        $action($company, $selection, null);
    }

    /**
     * @return iterable<string, array{mixed[]}>
     */
    public static function invalidSelections(): iterable
    {
        yield 'empty' => [[]];
        yield 'invalid UUID' => [['not-a-uuid']];
        yield 'more than one page' => [array_map(
            static fn (): string => Uuid::uuid4()->toString(),
            range(1, 21),
        )];
    }

    public function testDeletesSelectionWithOneBatchAndOneRecalculation(): void
    {
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->build();
        $firstAccount = $this->account($company);
        $secondAccount = $this->account($company);
        $first = $this->transaction($company, $firstAccount, '2026-08-05');
        $second = $this->transaction($company, $secondAccount, '2026-08-07');
        $ids = [$first->getId(), $second->getId()];

        $repository = $this->createMock(CashTransactionRepository::class);
        $repository->expects(self::once())
            ->method('findActiveByIdsAndCompanyId')
            ->with($ids, $company->getId())
            ->willReturn([$first, $second]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $recalculator = $this->createMock(DailyBalanceRecalculator::class);
        $recalculator->expects(self::once())
            ->method('recalcRange')
            ->with(
                $company,
                new \DateTimeImmutable('2026-08-05'),
                new \DateTimeImmutable('today'),
                self::callback(static function (array $accountIds) use ($firstAccount, $secondAccount): bool {
                    sort($accountIds);
                    $expected = [$firstAccount->getId(), $secondAccount->getId()];
                    sort($expected);

                    return $expected === $accountIds;
                }),
            );

        $cacheInvalidator = new SnapshotCacheInvalidator(new ArrayAdapter());

        $deletedCount = (new BulkDeleteCashTransactionsAction(
            $repository,
            $entityManager,
            $recalculator,
            $cacheInvalidator,
        ))($company, $ids, '22222222-2222-2222-2222-222222222222');

        self::assertSame(2, $deletedCount);
        self::assertTrue($first->isDeleted());
        self::assertTrue($second->isDeleted());
    }

    public function testLockedTransactionRejectsWholeSelectionBeforeMutation(): void
    {
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-08-06'));
        $account = $this->account($company);
        $locked = $this->transaction($company, $account, '2026-08-05');
        $open = $this->transaction($company, $account, '2026-08-06');

        $repository = $this->createMock(CashTransactionRepository::class);
        $repository->method('findActiveByIdsAndCompanyId')->willReturn([$locked, $open]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $recalculator = $this->createMock(DailyBalanceRecalculator::class);
        $recalculator->expects(self::never())->method('recalcRange');
        $cacheInvalidator = new SnapshotCacheInvalidator(new ArrayAdapter());

        $action = new BulkDeleteCashTransactionsAction(
            $repository,
            $entityManager,
            $recalculator,
            $cacheInvalidator,
        );

        try {
            $action($company, [$locked->getId(), $open->getId()], null);
            self::fail('Закрытый период должен отклонять всю массовую операцию.');
        } catch (FinancePeriodLockedException) {
            self::assertFalse($locked->isDeleted());
            self::assertFalse($open->isDeleted());
        }
    }

    private function account(Company $company): MoneyAccount
    {
        return MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();
    }

    private function transaction(
        Company $company,
        MoneyAccount $account,
        string $occurredAt,
    ): CashTransaction {
        return new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable($occurredAt),
        );
    }
}
