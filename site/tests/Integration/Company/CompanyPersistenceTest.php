<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CompanyPersistenceTest extends IntegrationTestCase
{
    public function testCompanyPersistsWithOwner(): void
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->build();

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $this->em->clear();

        /** @var Company|null $companyFromDb */
        $companyFromDb = $this->em->getRepository(Company::class)->find($company->getId());

        self::assertNotNull($companyFromDb);
        self::assertNotNull($companyFromDb->getUser());
        self::assertSame($user->getId(), $companyFromDb->getUser()->getId());
    }
}
