<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
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

    public function testGetAllActiveCompanyIdsReadsExistingCompaniesTable(): void
    {
        $userA = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000301')
            ->withEmail('company-a@example.test')
            ->build();
        $userB = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000302')
            ->withEmail('company-b@example.test')
            ->build();
        $companyA = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000301')
            ->withOwner($userA)
            ->withName('Company A')
            ->build();
        $companyB = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000302')
            ->withOwner($userB)
            ->withName('Company B')
            ->build();

        $this->em->persist($userA);
        $this->em->persist($userB);
        $this->em->persist($companyA);
        $this->em->persist($companyB);
        $this->em->flush();

        /** @var CompanyRepository $companyRepository */
        $companyRepository = static::getContainer()->get(CompanyRepository::class);

        self::assertSame([
            (string) $companyA->getId(),
            (string) $companyB->getId(),
        ], $companyRepository->getAllActiveCompanyIds());
    }
}
