<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service;

use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DailyBalanceRecalculatorTest extends TestCase
{
    public function testDelegatesEveryCompanyAccountToCanonicalBalanceService(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('daily-balance@example.test');
        $user->setPassword('pass');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Test Company');

        $firstAccount = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Bank', 'RUB');
        $secondAccount = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::CASH, 'Cash', 'RUB');
        $from = new \DateTimeImmutable('2026-01-10');
        $to = new \DateTimeImmutable('2026-01-12');

        $accountRepository = $this->getMockBuilder(MoneyAccountRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $accountRepository->expects(self::once())
            ->method('findBy')
            ->with(['company' => $company])
            ->willReturn([$firstAccount, $secondAccount]);

        $balanceService = $this->createMock(AccountBalanceService::class);
        $expectedAccounts = [$firstAccount, $secondAccount];
        $invocation = 0;
        $balanceService->expects(self::exactly(2))
            ->method('recalculateDailyRange')
            ->willReturnCallback(static function (
                Company $actualCompany,
                MoneyAccount $actualAccount,
                \DateTimeImmutable $actualFrom,
                \DateTimeImmutable $actualTo,
            ) use ($company, $expectedAccounts, $from, $to, &$invocation): void {
                self::assertSame($company, $actualCompany);
                self::assertSame($expectedAccounts[$invocation], $actualAccount);
                self::assertSame($from, $actualFrom);
                self::assertSame($to, $actualTo);
                ++$invocation;
            });

        $recalculator = new DailyBalanceRecalculator($accountRepository, $balanceService);
        $recalculator->recalcRange($company, $from, $to);
    }
}
