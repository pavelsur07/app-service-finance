<?php

namespace App\Tests\Unit\Report\Cashflow;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashflowReportBuilderTest extends TestCase
{
    public function testBuildExcludesSoftDeletedTransactionsFromCategoryTotals(): void
    {
        $categoryRepository = $this->getMockBuilder(CashflowCategoryRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findTreeByCompany'])
            ->getMock();
        $transactionRepository = $this->getMockBuilder(CashTransactionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $accountRepository = $this->getMockBuilder(MoneyAccountRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $balanceRepository = $this->getMockBuilder(MoneyAccountDailyBalanceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy', 'findLastBefore'])
            ->getMock();

        $builder = new CashflowReportBuilder(
            $categoryRepository,
            $transactionRepository,
            $accountRepository,
            $balanceRepository,
        );

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-report@example.com');
        $user->setPassword('pass');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Cashflow Co');

        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Operations');

        $categoryRepository->expects(self::once())
            ->method('findTreeByCompany')
            ->with($company)
            ->willReturn([$category]);

        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getArrayResult')->willReturn([
            [
                'category' => $category->getId(),
                'direction' => CashDirection::INFLOW->value,
                'amount' => '100.00',
                'currency' => 'USD',
                'occurredAt' => new \DateTimeImmutable('2026-01-10 12:00:00'),
            ],
        ]);

        $whereExpressions = [];
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')
            ->willReturnCallback(function (string $expr) use (&$whereExpressions, $queryBuilder): QueryBuilder {
                $whereExpressions[] = $expr;

                return $queryBuilder;
            });
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $transactionRepository->expects(self::once())
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturn($queryBuilder);

        $accountRepository->expects(self::once())
            ->method('findBy')
            ->with(['company' => $company])
            ->willReturn([]);

        $payload = $builder->build(new CashflowReportParams(
            $company,
            'day',
            new \DateTimeImmutable('2026-01-10'),
            new \DateTimeImmutable('2026-01-10'),
        ));

        self::assertContains('t.deletedAt IS NULL', $whereExpressions);
        self::assertSame(100.0, $payload['categoryTotals'][$category->getId()]['totals']['USD'][0]);
    }
}
