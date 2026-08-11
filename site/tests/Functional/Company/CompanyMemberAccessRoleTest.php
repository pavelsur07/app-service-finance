<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\SystemCompanyRoles;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyInviteBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Db\SystemCompanyRolesSeeder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CompanyMemberAccessRoleTest extends WebTestCaseBase
{
    public function testOwnerCanChangeMemberAccessRole(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($member);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/users/%s/access-role', $member->getId()), [
            'roleId' => SystemCompanyRoles::FULL_ACCESS_ID,
            '_token' => $this->csrfToken($client, 'member_access_role_'.$member->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyMember::class, $member->getId());
        self::assertInstanceOf(CompanyMember::class, $updated);
        self::assertNotNull($updated->getAccessRole());
        self::assertSame(SystemCompanyRoles::FULL_ACCESS_ID, $updated->getAccessRole()->getId());
    }

    public function testOwnerCannotAssignRoleOfAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $otherOwner = UserBuilder::aUser()->withIndex(2)->withEmail('other@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(3)->withEmail('member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $otherRole = new CompanyRole(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'Чужой шаблон',
            [Module::FINANCE->value => AccessLevel::WRITE->value],
            $otherCompany,
        );
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($otherOwner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($otherCompany);
        $em->persist($otherRole);
        $em->persist($member);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/users/%s/access-role', $member->getId()), [
            'roleId' => $otherRole->getId(),
            '_token' => $this->csrfToken($client, 'member_access_role_'.$member->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyMember::class, $member->getId());
        self::assertInstanceOf(CompanyMember::class, $updated);
        self::assertNull($updated->getAccessRole());
    }

    public function testOwnerCannotChangeOwnerMemberAccessRole(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $ownerMember = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($owner)
            ->withRole(CompanyMember::ROLE_OWNER)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($ownerMember);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/users/%s/access-role', $ownerMember->getId()), [
            'roleId' => SystemCompanyRoles::FINANCE_ID,
            '_token' => $this->csrfToken($client, 'member_access_role_'.$ownerMember->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyMember::class, $ownerMember->getId());
        self::assertInstanceOf(CompanyMember::class, $updated);
        self::assertNull($updated->getAccessRole());
    }

    public function testOwnerCannotAssignOwnerRoleTemplateToMember(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($member);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/users/%s/access-role', $member->getId()), [
            'roleId' => SystemCompanyRoles::OWNER_ID,
            '_token' => $this->csrfToken($client, 'member_access_role_'.$member->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyMember::class, $member->getId());
        self::assertInstanceOf(CompanyMember::class, $updated);
        self::assertNull($updated->getAccessRole());
    }

    public function testOwnerCannotRemoveLastAdminAccess(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        // CompanyOwnerMembershipCreator создаёт участника-владельца; он не должен
        // учитываться как "другой admin" при защите последнего административного доступа.
        $ownerMember = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($owner)
            ->withRole(CompanyMember::ROLE_OWNER)
            ->withId('44444444-4444-4444-4444-444444444444')
            ->build();
        // Участник действительно администратор: только тогда его понижение что-то отнимает.
        $adminRole = new CompanyRole(
            '66666666-6666-4666-8666-666666666666',
            'Администратор',
            [Module::ADMIN->value => AccessLevel::WRITE->value],
            $company,
        );
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withAccessRole($adminRole)
            ->withId('55555555-5555-5555-5555-555555555555')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($adminRole);
        $em->persist($ownerMember);
        $em->persist($member);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/users/%s/access-role', $member->getId()), [
            'roleId' => SystemCompanyRoles::FINANCE_ID,
            '_token' => $this->csrfToken($client, 'member_access_role_'.$member->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyMember::class, $member->getId());
        self::assertInstanceOf(CompanyMember::class, $updated);
        // Finance-шаблон не содержит admin:write, участник-владелец «другим admin» не считается,
        // поэтому единственного делегированного администратора понизить нельзя.
        $accessRole = $updated->getAccessRole();
        self::assertInstanceOf(CompanyRole::class, $accessRole);
        self::assertSame((string) $adminRole->getId(), (string) $accessRole->getId());
    }

    public function testOwnerCanAssignLimitedRoleToMemberWhoWasNeverAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $memberUser = UserBuilder::aUser()->withIndex(2)->withEmail('member@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        // Участник без шаблона: администратором он не был, значит и отнимать нечего.
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withId('55555555-5555-5555-5555-555555555555')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($memberUser);
        $em->persist($company);
        $em->persist($member);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', sprintf('/company/users/%s/access-role', $member->getId()), [
            'roleId' => SystemCompanyRoles::FINANCE_ID,
            '_token' => $this->csrfToken($client, 'member_access_role_'.$member->getId()),
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $updated = $this->em()->find(CompanyMember::class, $member->getId());
        self::assertInstanceOf(CompanyMember::class, $updated);
        $accessRole = $updated->getAccessRole();
        self::assertInstanceOf(CompanyRole::class, $accessRole);
        self::assertSame(SystemCompanyRoles::FINANCE_ID, (string) $accessRole->getId());
    }

    public function testInviteWithSelectedRoleAssignsRoleOnAccept(): void
    {
        $client = static::createClient();
        $this->resetDb();
        (new SystemCompanyRolesSeeder())->seed($this->em());

        $owner = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $operator = UserBuilder::aUser()->withIndex(2)->withEmail('operator@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $marketplaceRole = $this->em()->find(CompanyRole::class, SystemCompanyRoles::MARKETPLACE_ID);
        self::assertInstanceOf(CompanyRole::class, $marketplaceRole);

        $plainToken = 'selected-role-token';
        $tokenService = self::getContainer()->get(\App\Company\Service\InviteTokenService::class);
        $invite = CompanyInviteBuilder::anInvite()
            ->withCompany($company)
            ->withCreatedBy($owner)
            ->withEmail('operator@example.test')
            ->withTokenHash($tokenService->hashToken($plainToken))
            ->withAccessRole($marketplaceRole)
            ->withPending()
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($operator);
        $em->persist($company);
        $em->persist($invite);
        $em->flush();

        $client->loginUser($operator);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', sprintf('/invite/%s', $plainToken));
        $token = (string) $crawler->filter(sprintf('form[action="/invite/%s/accept"] input[name="_token"]', $plainToken))->attr('value');

        $client->request('POST', sprintf('/invite/%s/accept', $plainToken), [
            '_token' => $token,
        ]);

        self::assertTrue($client->getResponse()->isRedirect());

        $em->clear();
        $member = self::getContainer()->get(CompanyMemberRepository::class)
            ->findOneByCompanyAndUser($company, $operator);
        self::assertInstanceOf(CompanyMember::class, $member);
        self::assertNotNull($member->getAccessRole());
        self::assertSame(SystemCompanyRoles::MARKETPLACE_ID, $member->getAccessRole()->getId());
    }
}
