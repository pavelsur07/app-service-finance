<?php

declare(strict_types=1);

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
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Repository\ProjectDirectionRepository;
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
        $projectDirectionRepository = $this->createMock(ProjectDirectionRepository::class);
        $projectDirectionRepository->expects(self::never())->method('findByCompany');

        $builder = new CashflowReportBuilder(
            $categoryRepository,
            $transactionRepository,
            $accountRepository,
            $balanceRepository,
            $projectDirectionRepository,
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
        $projectDirectionRepository = $this->createMock(ProjectDirectionRepository::class);
        $projectDirectionRepository->expects(self::never())->method('findByCompany');

        $builder = new CashflowReportBuilder(
            $categoryRepository,
            $transactionRepository,
            $accountRepository,
            $balanceRepository,
            $projectDirectionRepository,
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

    public function testBuildCombinesPluralProjectSubtreeAndResponsibilityCenterFilters(): void
    {
        $categoryRepository = $this->createMock(CashflowCategoryRepository::class);
        $categoryRepository->method('findTreeByCompany')->willReturn([]);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $accountRepository = $this->createMock(MoneyAccountRepository::class);
        $accountRepository->method('findBy')->willReturn([]);
        $balanceRepository = $this->createMock(MoneyAccountDailyBalanceRepository::class);
        $projectDirectionRepository = $this->createMock(ProjectDirectionRepository::class);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-plural-filter@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $parent = new ProjectDirection('11111111-1111-4111-8111-111111111111', $company, 'Parent');
        $child = (new ProjectDirection('22222222-2222-4222-8222-222222222222', $company, 'Child'))
            ->setParent($parent);
        $other = new ProjectDirection('33333333-3333-4333-8333-333333333333', $company, 'Other');
        $projectDirectionRepository->expects(self::once())
            ->method('findByCompany')
            ->with($company)
            ->willReturn([$parent, $child, $other]);

        $whereExpressions = [];
        $parameters = [];
        $filteredBuilder = $this->queryBuilder([], $whereExpressions, $parameters);
        $companyWhereExpressions = [];
        $companyParameters = [];
        $companyBuilder = $this->queryBuilder([], $companyWhereExpressions, $companyParameters);
        $transactionRepository->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturnOnConsecutiveCalls($filteredBuilder, $companyBuilder);

        $centerIds = [
            '44444444-4444-4444-8444-444444444444',
            '55555555-5555-4555-8555-555555555555',
        ];
        $builder = new CashflowReportBuilder(
            $categoryRepository,
            $transactionRepository,
            $accountRepository,
            $balanceRepository,
            $projectDirectionRepository,
        );
        $payload = $builder->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            [(string) $parent->getId()],
            $centerIds,
        ));

        self::assertContains('IDENTITY(t.projectDirection) IN (:projectDirectionIds)', $whereExpressions);
        self::assertContains('t.responsibilityCenterId IN (:responsibilityCenterIds)', $whereExpressions);
        self::assertSame([$parent->getId(), $child->getId()], $parameters['projectDirectionIds']);
        self::assertSame($centerIds, $parameters['responsibilityCenterIds']);
        self::assertSame([$parent->getId()], $payload['project_direction_ids']);
        self::assertSame($centerIds, $payload['responsibility_center_ids']);
    }

    public function testBuildDoesNotIssueInvalidQueryForExplicitEmptyFilter(): void
    {
        $categoryRepository = $this->createMock(CashflowCategoryRepository::class);
        $categoryRepository->method('findTreeByCompany')->willReturn([]);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $accountRepository = $this->createMock(MoneyAccountRepository::class);
        $accountRepository->method('findBy')->willReturn([]);
        $balanceRepository = $this->createMock(MoneyAccountDailyBalanceRepository::class);
        $projectDirectionRepository = $this->createMock(ProjectDirectionRepository::class);
        $projectDirectionRepository->expects(self::never())->method('findByCompany');

        $whereExpressions = [];
        $parameters = [];
        $companyBuilder = $this->queryBuilder([], $whereExpressions, $parameters);
        $transactionRepository->expects(self::once())
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturn($companyBuilder);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-empty-filter@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $builder = new CashflowReportBuilder(
            $categoryRepository,
            $transactionRepository,
            $accountRepository,
            $balanceRepository,
            $projectDirectionRepository,
        );
        $payload = $builder->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            [],
        ));

        self::assertSame([], $payload['project_direction_ids']);
        self::assertSame([], $payload['projectCenterMatrix']['rowsByProject']);
        self::assertNotContains('IDENTITY(t.projectDirection) IN (:projectDirectionIds)', $whereExpressions);
    }

    public function testBuildCombinesPluralProjectFilterWithLegacyResponsibilityCenter(): void
    {
        $categoryRepository = $this->createMock(CashflowCategoryRepository::class);
        $categoryRepository->method('findTreeByCompany')->willReturn([]);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $accountRepository = $this->createMock(MoneyAccountRepository::class);
        $accountRepository->method('findBy')->willReturn([]);
        $balanceRepository = $this->createMock(MoneyAccountDailyBalanceRepository::class);
        $projectDirectionRepository = $this->createMock(ProjectDirectionRepository::class);
        $projectDirectionRepository->expects(self::never())->method('findByCompany');

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-mixed-filter@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $projectA = new ProjectDirection('66666666-6666-4666-8666-666666666661', $company, 'Project A');
        $projectB = new ProjectDirection('66666666-6666-4666-8666-666666666662', $company, 'Project B');
        $centerId = '66666666-6666-4666-8666-666666666663';

        $whereExpressions = [];
        $parameters = [];
        $filteredBuilder = $this->queryBuilder([], $whereExpressions, $parameters);
        $companyWhereExpressions = [];
        $companyParameters = [];
        $companyBuilder = $this->queryBuilder([], $companyWhereExpressions, $companyParameters);
        $transactionRepository->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturnOnConsecutiveCalls($filteredBuilder, $companyBuilder);

        $builder = new CashflowReportBuilder(
            $categoryRepository,
            $transactionRepository,
            $accountRepository,
            $balanceRepository,
            $projectDirectionRepository,
        );
        $builder->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            $centerId,
            [(string) $projectA->getId()],
            null,
            [$projectA, $projectB],
        ));

        self::assertContains('IDENTITY(t.projectDirection) IN (:projectDirectionIds)', $whereExpressions);
        self::assertContains('t.responsibilityCenterId = :responsibilityCenterId', $whereExpressions);
        self::assertSame([$projectA->getId()], $parameters['projectDirectionIds']);
        self::assertSame($centerId, $parameters['responsibilityCenterId']);
    }

    public function testBuildRejectsForeignProjectFromSuppliedCatalogue(): void
    {
        $categoryRepository = $this->createMock(CashflowCategoryRepository::class);
        $categoryRepository->method('findTreeByCompany')->willReturn([]);
        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $accountRepository = $this->createMock(MoneyAccountRepository::class);
        $accountRepository->method('findBy')->willReturn([]);
        $balanceRepository = $this->createMock(MoneyAccountDailyBalanceRepository::class);
        $projectDirectionRepository = $this->createMock(ProjectDirectionRepository::class);
        $projectDirectionRepository->expects(self::never())->method('findByCompany');

        $companyUser = new User(Uuid::uuid4()->toString());
        $companyUser->setEmail('cashflow-company@example.com');
        $companyUser->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $companyUser);
        $foreignUser = new User(Uuid::uuid4()->toString());
        $foreignUser->setEmail('cashflow-foreign@example.com');
        $foreignUser->setPassword('pass');
        $foreignCompany = new Company(Uuid::uuid4()->toString(), $foreignUser);
        $foreignProject = new ProjectDirection(
            '77777777-7777-4777-8777-777777777771',
            $foreignCompany,
            'Foreign Project',
        );

        $companyWhereExpressions = [];
        $companyParameters = [];
        $companyBuilder = $this->queryBuilder([], $companyWhereExpressions, $companyParameters);
        $transactionRepository->expects(self::once())
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturn($companyBuilder);

        $builder = new CashflowReportBuilder(
            $categoryRepository,
            $transactionRepository,
            $accountRepository,
            $balanceRepository,
            $projectDirectionRepository,
        );
        $payload = $builder->build(new CashflowReportParams(
            $company,
            'month',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            [(string) $foreignProject->getId()],
            null,
            [$foreignProject],
        ));

        self::assertSame([], $payload['projectCenterMatrix']['rowsByProject']);
        self::assertSame([$foreignProject->getId()], $payload['project_direction_ids']);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $whereExpressions
     * @param array<string,mixed> $parameters
     */
    private function queryBuilder(array $rows, array &$whereExpressions, array &$parameters): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getArrayResult')->willReturn($rows);

        $builder = $this->createMock(QueryBuilder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('leftJoin')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('andWhere')
            ->willReturnCallback(static function (string $expression) use (&$whereExpressions, $builder): QueryBuilder {
                $whereExpressions[] = $expression;

                return $builder;
            });
        $builder->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use (&$parameters, $builder): QueryBuilder {
                $parameters[$name] = $value;

                return $builder;
            });
        $builder->method('getQuery')->willReturn($query);

        return $builder;
    }
}
