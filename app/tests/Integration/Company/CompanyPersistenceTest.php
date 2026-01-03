<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Entity\Company;
use App\Tests\Builders\Shared\CompanyBuilder;
use App\Tests\Builders\Shared\UserBuilder;
use App\Tests\_support\Kernel\KernelTestCaseBase;

final class CompanyPersistenceTest extends KernelTestCaseBase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->resetDb();
    }

    public function testCompanyPersistsWithOwner(): void
    {
        $em = $this->em();

        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->build();

        $em->persist($user);
        $em->persist($company);
        $em->flush();
        $em->clear();

        $companyFromDb = $em->getRepository(Company::class)->find($company->getId());

        self::assertNotNull($companyFromDb);
        self::assertNotNull($companyFromDb->getUser());
        self::assertSame($user->getId(), $companyFromDb->getUser()->getId());
    }
}
