<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\CompanyMember;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\HttpFoundation\Response;

final class CompanyMemberAccessTest extends WebTestCaseBase
{
    public function testActiveMemberCanOpenCompanyUsersPage(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedCompanyMember(CompanyMember::STATUS_ACTIVE);

        $client->loginUser($memberUser);
        $client->request('GET', sprintf('/company/%s/users', $company->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Участники '.$company->getName());
    }

    public function testDisabledMemberCannotOpenCompanyUsersPage(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedCompanyMember(CompanyMember::STATUS_DISABLED);

        $client->loginUser($memberUser);
        $client->request('GET', sprintf('/company/%s/users', $company->getId()));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array{0: \App\Company\Entity\Company, 1: \App\Company\Entity\User}
     */
    private function seedCompanyMember(string $memberStatus): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $memberUser = UserBuilder::aUser()
            ->withIndex(2)
            ->withEmail('member@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($owner)
            ->withName('Access Company')
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withStatus($memberStatus)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($member);
        $em->flush();

        return [$company, $memberUser];
    }
}
