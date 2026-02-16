<?php

namespace App\Tests\Unit\Cash\Service;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Enum\MoneyAccountType;
use App\Service\DailyBalanceRecalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class DailyBalanceRecalculatorTest extends TestCase
{
    public function testRecalcRangeAddsDeletedAtFilterForTransactions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $txRepo = $this->getMockBuilder(CashTransactionRepository::class)->disableOriginalConstructor()->onlyMethods(['createQueryBuilder'])->getMock();
        $dailyRepo = $this->getMockBuilder(MoneyAccountDailyBalanceRepository::class)->disableOriginalConstructor()->onlyMethods(['createQueryBuilder', 'findOneBy'])->getMock();
        $accountRepo = $this->getMockBuilder(MoneyAccountRepository::class)->disableOriginalConstructor()->onlyMethods(['findBy'])->getMock();

        $recalculator = new DailyBalanceRecalculator($em, $txRepo, $dailyRepo, $accountRepo);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('daily-balance-filter@example.com');
        $user->setPassword('pass');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Test Company');

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');

        $from = new \DateTimeImmutable('2026-01-10');
        $to = new \DateTimeImmutable('2026-01-10');

        $accountRepo->expects(self::once())
            ->method('findBy')
            ->with(['company' => $company])
            ->willReturn([$account]);

        $maxDateQuery = $this->createMock(Query::class);
        $maxDateQuery->method('getSingleScalarResult')->willReturn(null);
        $maxDateQb = $this->createMock(QueryBuilder::class);
        $maxDateQb->method('select')->willReturnSelf();
        $maxDateQb->method('where')->willReturnSelf();
        $maxDateQb->method('setParameter')->willReturnSelf();
        $maxDateQb->method('andWhere')->willReturnSelf();
        $maxDateQb->method('getQuery')->willReturn($maxDateQuery);

        $txQuery = $this->createMock(Query::class);
        $txQuery->method('getResult')->willReturn([]);
        $txQb = $this->createMock(QueryBuilder::class);
        $txQb->method('where')->willReturnSelf();
        $txQb->method('setParameter')->willReturnSelf();
        $txWhere = [];
        $txQb->method('andWhere')
            ->willReturnCallback(function (string $expr) use (&$txWhere, $txQb): QueryBuilder {
                $txWhere[] = $expr;

                return $txQb;
            });
        $txQb->method('orderBy')->willReturnSelf();
        $txQb->method('addOrderBy')->willReturnSelf();
        $txQb->method('getQuery')->willReturn($txQuery);

        $prevQuery = $this->createMock(Query::class);
        $prevQuery->method('getOneOrNullResult')->willReturn(null);
        $prevQb = $this->createMock(QueryBuilder::class);
        $prevQb->method('where')->willReturnSelf();
        $prevQb->method('setParameter')->willReturnSelf();
        $prevQb->method('andWhere')->willReturnSelf();
        $prevQb->method('orderBy')->willReturnSelf();
        $prevQb->method('setMaxResults')->willReturnSelf();
        $prevQb->method('getQuery')->willReturn($prevQuery);

        $sameQuery = $this->createMock(Query::class);
        $sameQuery->method('getOneOrNullResult')->willReturn(null);
        $sameQb = $this->createMock(QueryBuilder::class);
        $sameQb->method('where')->willReturnSelf();
        $sameQb->method('setParameter')->willReturnSelf();
        $sameQb->method('andWhere')->willReturnSelf();
        $sameQb->method('setMaxResults')->willReturnSelf();
        $sameQb->method('getQuery')->willReturn($sameQuery);

        $dailyRepo->expects(self::exactly(3))
            ->method('createQueryBuilder')
            ->with('b')
            ->willReturnOnConsecutiveCalls($maxDateQb, $prevQb, $sameQb);

        $dailyRepo->method('findOneBy')->willReturn(null);

        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $txRepo->expects(self::once())
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturn($txQb);

        $recalculator->recalcRange($company, $from, $to);

        self::assertContains('t.deletedAt IS NULL', $txWhere);
    }
}
