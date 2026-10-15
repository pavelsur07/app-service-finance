<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\LastUpdatedAtResolver;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Mock\MocksFinalClasses;
use PHPUnit\Framework\TestCase;

final class LastUpdatedAtResolverTest extends TestCase
{
    use MocksFinalClasses;

    private const COMPANY_ID = '76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12';

    public function testResolveReturnsLatestNonNullTimestamp(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();

        self::assertEquals(
            new \DateTimeImmutable('2026-01-12 10:30:00'),
            $this->resolver($company, new \DateTimeImmutable('2026-01-12 10:30:00'), new \DateTimeImmutable('2026-01-10 08:00:00'))->resolve($company),
        );
        self::assertEquals(
            new \DateTimeImmutable('2026-01-10 08:00:00'),
            $this->resolver($company, null, new \DateTimeImmutable('2026-01-10 08:00:00'))->resolve($company),
        );
        self::assertNull($this->resolver($company, null, null)->resolve($company));
    }

    private function resolver(Company $company, ?\DateTimeImmutable $cashAt, ?\DateTimeImmutable $plAt): LastUpdatedAtResolver
    {
        $cashFacade = $this->createFinalMock(CashFacade::class);
        $cashFacade->method('getLastCashActivityAt')->with(self::COMPANY_ID)->willReturn($cashAt);

        $plDailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $plDailyTotalRepository->method('maxUpdatedAtForCompany')->with($company)->willReturn($plAt);

        return new LastUpdatedAtResolver(self::asInstanceOf($cashFacade, CashFacade::class), $plDailyTotalRepository);
    }
}
