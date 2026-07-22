<?php

namespace App\Tests\Unit\Report\Cashflow;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Accounts\MoneyAccountType;
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
                'project_id' => null,
                'project_name' => null,
                'responsibility_center_id' => null,
            ],
        ]);

        $whereExpressions = [];
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('leftJoin')->willReturnSelf();
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

    public function testBuildFiltersTransactionsByResponsibilityCenter(): void
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
        $user->setEmail('cashflow-report-filter@example.com');
        $user->setPassword('pass');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Cashflow Co');

        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Operations');
        $centerId = '11111111-1111-4111-8111-111111111111';

        $categoryRepository->method('findTreeByCompany')
            ->with($company)
            ->willReturn([$category]);

        $filteredQuery = $this->createMock(Query::class);
        $filteredQuery->expects(self::once())->method('getArrayResult')->willReturn([
            [
                'category' => $category->getId(),
                'direction' => CashDirection::INFLOW->value,
                'amount' => '100.00',
                'currency' => 'RUB',
                'occurredAt' => new \DateTimeImmutable('2026-01-10 12:00:00'),
                'project_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'project_name' => 'Продажа компьютеров',
                'responsibility_center_id' => $centerId,
            ],
        ]);
        $companyQuery = $this->createMock(Query::class);
        $companyQuery->expects(self::once())->method('getArrayResult')->willReturn([
            [
                'category' => $category->getId(),
                'direction' => CashDirection::INFLOW->value,
                'amount' => '100.00',
                'currency' => 'RUB',
                'occurredAt' => new \DateTimeImmutable('2026-01-10 12:00:00'),
                'project_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'project_name' => 'Продажа компьютеров',
                'responsibility_center_id' => $centerId,
            ],
            [
                'category' => $category->getId(),
                'direction' => CashDirection::INFLOW->value,
                'amount' => '50.00',
                'currency' => 'RUB',
                'occurredAt' => new \DateTimeImmutable('2026-01-10 13:00:00'),
                'project_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'project_name' => 'Сервисные услуги',
                'responsibility_center_id' => '22222222-2222-4222-8222-222222222222',
            ],
        ]);

        $whereExpressions = [];
        $parameters = [];
        $filteredQueryBuilder = $this->createMock(QueryBuilder::class);
        $filteredQueryBuilder->method('select')->willReturnSelf();
        $filteredQueryBuilder->method('leftJoin')->willReturnSelf();
        $filteredQueryBuilder->method('where')->willReturnSelf();
        $filteredQueryBuilder->method('andWhere')
            ->willReturnCallback(function (string $expr) use (&$whereExpressions, $filteredQueryBuilder): QueryBuilder {
                $whereExpressions[] = $expr;

                return $filteredQueryBuilder;
            });
        $filteredQueryBuilder->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $filteredQueryBuilder): QueryBuilder {
                $parameters[$name] = $value;

                return $filteredQueryBuilder;
            });
        $filteredQueryBuilder->method('getQuery')->willReturn($filteredQuery);

        $companyQueryBuilder = $this->createMock(QueryBuilder::class);
        $companyQueryBuilder->method('select')->willReturnSelf();
        $companyQueryBuilder->method('leftJoin')->willReturnSelf();
        $companyQueryBuilder->method('where')->willReturnSelf();
        $companyQueryBuilder->method('andWhere')->willReturnSelf();
        $companyQueryBuilder->method('setParameter')->willReturnSelf();
        $companyQueryBuilder->method('getQuery')->willReturn($companyQuery);

        $transactionRepository->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturnOnConsecutiveCalls($filteredQueryBuilder, $companyQueryBuilder);

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'RUB');
        $account->setOpeningBalance('1000.00');

        $accountRepository->expects(self::once())
            ->method('findBy')
            ->with(['company' => $company])
            ->willReturn([$account]);
        $balanceRepository->expects(self::once())
            ->method('findOneBy')
            ->willReturn(null);
        $balanceRepository->expects(self::once())
            ->method('findLastBefore')
            ->with($company, $account, new \DateTimeImmutable('2026-01-10'))
            ->willReturn(null);

        $payload = $builder->build(new CashflowReportParams(
            $company,
            'day',
            new \DateTimeImmutable('2026-01-10'),
            new \DateTimeImmutable('2026-01-10'),
            $centerId,
        ));

        self::assertContains('t.responsibilityCenterId = :responsibilityCenterId', $whereExpressions);
        self::assertSame($centerId, $parameters['responsibilityCenterId']);
        self::assertSame($centerId, $payload['responsibility_center_id']);
        self::assertSame(100.0, $payload['categoryTotals'][$category->getId()]['totals']['RUB'][0]);
        self::assertSame(1000.0, $payload['openings']['RUB'][0]);
        self::assertSame(1150.0, $payload['closings']['RUB'][0]);
        self::assertSame(['RUB'], $payload['projectCenterMatrix']['currencies']);
        self::assertSame('Продажа компьютеров', $payload['projectCenterMatrix']['rowsByProject'][0]['project_name']);
        self::assertSame($centerId, $payload['projectCenterMatrix']['rowsByProject'][0]['responsibility_center_id']);
        self::assertSame(100.0, $payload['projectCenterMatrix']['rowsByProject'][0]['totals']['RUB'][0]);
        self::assertCount(1, $payload['projectCenterMatrix']['rowsByProject']);
    }
}
