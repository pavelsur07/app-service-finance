<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\LastUpdatedAtResolver;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Repository\PLDailyTotalRepository;
use PHPUnit\Framework\TestCase;

final class LastUpdatedAtResolverTest extends TestCase
{
    public function testResolveReturnsLatestNonNullTimestamp(): void
    {
        $company = $this->createMock(Company::class);

        $cashTransactionRepository = $this->createMock(CashTransactionRepository::class);
        $cashTransactionRepository
            ->method('maxUpdatedAtForCompany')
            ->with($company)
            ->willReturn(null);

        $moneyFundMovementRepository = $this->createMock(MoneyFundMovementRepository::class);
        $moneyFundMovementRepository
            ->method('maxUpdatedAtForCompany')
            ->with($company)
            ->willReturn(new \DateTimeImmutable('2026-01-12 10:30:00'));

        $plDailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $plDailyTotalRepository
            ->method('maxUpdatedAtForCompany')
            ->with($company)
            ->willReturn(new \DateTimeImmutable('2026-01-10 08:00:00'));

        $resolver = new LastUpdatedAtResolver(
            $cashTransactionRepository,
            $moneyFundMovementRepository,
            $plDailyTotalRepository,
        );

        self::assertEquals(
            new \DateTimeImmutable('2026-01-12 10:30:00'),
            $resolver->resolve($company),
        );
    }
}
