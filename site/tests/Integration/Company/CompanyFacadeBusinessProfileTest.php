<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Enum\BusinessProfile;
use App\Company\Exception\CompanyNotFoundException;
use App\Company\Facade\CompanyFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CompanyFacadeBusinessProfileTest extends IntegrationTestCase
{
    public function testReturnsStoredProfile(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $company->setBusinessProfile(BusinessProfile::UNIVERSAL);
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(BusinessProfile::UNIVERSAL, self::getContainer()->get(CompanyFacade::class)->getBusinessProfile((string) $company->getId()));
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(CompanyNotFoundException::class);

        self::getContainer()->get(CompanyFacade::class)->getBusinessProfile(Uuid::uuid7()->toString());
    }
}
