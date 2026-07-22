<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\CompanyMember;
use App\Company\Repository\UserRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class UserRepositoryTest extends IntegrationTestCase
{
    public function testFindOneByIdAndCompanyIdIncludesOwnerAndActiveMemberOnly(): void
    {
        $owner = UserBuilder::aUser()->withIndex(1)->build();
        $member = UserBuilder::aUser()->withIndex(2)->build();
        $disabledMember = UserBuilder::aUser()->withIndex(3)->build();
        $outsider = UserBuilder::aUser()->withIndex(4)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $activeMembership = new CompanyMember(
            Uuid::uuid4()->toString(),
            $company,
            $member,
            CompanyMember::ROLE_OPERATOR,
        );
        $disabledMembership = new CompanyMember(
            Uuid::uuid4()->toString(),
            $company,
            $disabledMember,
            CompanyMember::ROLE_OPERATOR,
        );
        $disabledMembership->disable();

        foreach ([$owner, $member, $disabledMember, $outsider, $company, $activeMembership, $disabledMembership] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);
        $companyId = (string) $company->getId();

        self::assertSame($owner, $repository->findOneByIdAndCompanyId((string) $owner->getId(), $companyId));
        self::assertSame($member, $repository->findOneByIdAndCompanyId((string) $member->getId(), $companyId));
        self::assertNull($repository->findOneByIdAndCompanyId((string) $disabledMember->getId(), $companyId));
        self::assertNull($repository->findOneByIdAndCompanyId((string) $outsider->getId(), $companyId));
    }
}
