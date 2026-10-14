<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Repository\Accounts;

use App\Cash\Repository\Accounts\MoneyFundRepository;
use App\Tests\Builders\Cash\MoneyFundBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Фонд по id строго в пределах компании. Функционально маршрут фонда не
 * проверить: фонды за флагом FEATURE_FUNDS_AND_WIDGET, в тестах он выключен.
 */
final class MoneyFundCompanyScopeTest extends IntegrationTestCase
{
    private const OWN_FUND = '33333333-3333-3333-3333-000000000201';
    private const FOREIGN_FUND = '33333333-3333-3333-3333-000000000202';

    private MoneyFundRepository $repository;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var MoneyFundRepository $repository */
        $repository = self::getContainer()->get(MoneyFundRepository::class);
        $this->repository = $repository;

        $owner = UserBuilder::aUser()->withEmail('owner-fund-scope@example.com')->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000041')->withEmail('owner-fund-scope-2@example.com')->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($otherOwner);
        $this->em->persist($other);
        $this->em->persist(MoneyFundBuilder::aFund()->withId(self::OWN_FUND)->withCompany($company)->build());
        $this->em->persist(MoneyFundBuilder::aFund()->withId(self::FOREIGN_FUND)->withCompany($other)->build());
        $this->em->flush();
        $this->em->clear();

        $this->companyId = (string) $company->getId();
    }

    public function testFindsOwnFund(): void
    {
        self::assertSame(self::OWN_FUND, $this->repository->findOneByIdAndCompanyId(self::OWN_FUND, $this->companyId)?->getId());
    }

    public function testForeignFundIsNotFound(): void
    {
        self::assertNull($this->repository->findOneByIdAndCompanyId(self::FOREIGN_FUND, $this->companyId));
    }

    public function testNonCanonicalIdIsNotFoundWithoutDatabaseError(): void
    {
        self::assertNull($this->repository->findOneByIdAndCompanyId('urn:uuid:'.self::OWN_FUND, $this->companyId));
        self::assertNull($this->repository->findOneByIdAndCompanyId('not-a-uuid', $this->companyId));
    }
}
