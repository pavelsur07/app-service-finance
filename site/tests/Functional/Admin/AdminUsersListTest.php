<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class AdminUsersListTest extends WebTestCaseBase
{
    public function testIndexShowsCabinetWithOwnerBadgeAndInn(): void
    {
        $client = static::createClient();

        $admin = $this->createAdmin(1);
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withName('ООО Кабинет')->withOwner($owner)->build();
        $company->setInn('7701234567');

        $em = $this->em();
        $em->persist($admin);
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ООО Кабинет', $content);
        self::assertStringContainsString('ИНН 7701234567', $content);
        self::assertStringContainsString('Владелец', $content);
    }

    public function testFilterByCompanyShowsOwnerAndMemberButHidesOutsiders(): void
    {
        $client = static::createClient();

        $admin = $this->createAdmin(1);
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $member = UserBuilder::aUser()->withIndex(3)->build();
        $outsider = UserBuilder::aUser()->withIndex(4)->build();

        $companyA = CompanyBuilder::aCompany()->withIndex(1)->withName('Кабинет A')->withOwner($owner)->build();
        $companyB = CompanyBuilder::aCompany()->withIndex(2)->withName('Кабинет B')->withOwner($outsider)->build();
        $membership = CompanyMemberBuilder::aMember()
            ->withId($this->memberId(1))
            ->withCompany($companyA)
            ->withUser($member)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->build();

        $em = $this->em();
        $em->persist($admin);
        $em->persist($owner);
        $em->persist($member);
        $em->persist($outsider);
        $em->persist($companyA);
        $em->persist($companyB);
        $em->persist($membership);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users?company='.$companyA->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('user+2@example.test', $content);
        self::assertStringContainsString('user+3@example.test', $content);
        self::assertStringContainsString('Владелец', $content);
        self::assertStringContainsString('Участник', $content);
        self::assertStringNotContainsString('user+4@example.test', $content);
    }

    public function testFilterWithoutCompanyShowsOnlyUsersWithoutCabinets(): void
    {
        $client = static::createClient();

        $admin = $this->createAdmin(1);
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $lonely = UserBuilder::aUser()->withIndex(3)->withRoles(['ROLE_USER'])->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($admin);
        $em->persist($owner);
        $em->persist($lonely);
        $em->persist($company);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users?company=none');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('user+3@example.test', $content);
        self::assertStringContainsString('Без кабинета', $content);
        self::assertStringNotContainsString('user+2@example.test', $content);
    }

    public function testOwnersOnlyFilterHidesUsersWithoutOwnedCompany(): void
    {
        $client = static::createClient();

        $admin = $this->createAdmin(1);
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $plainUser = UserBuilder::aUser()->withIndex(3)->withRoles(['ROLE_USER'])->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($admin);
        $em->persist($owner);
        $em->persist($plainUser);
        $em->persist($company);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users?owners=1');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('user+2@example.test', $content);
        self::assertStringNotContainsString('user+3@example.test', $content);
    }

    public function testDisabledMembershipIsNotShownAsCabinet(): void
    {
        $client = static::createClient();

        $admin = $this->createAdmin(1);
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $disabledMember = UserBuilder::aUser()->withIndex(3)->withRoles(['ROLE_USER'])->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $membership = CompanyMemberBuilder::aMember()
            ->withId($this->memberId(1))
            ->withCompany($company)
            ->withUser($disabledMember)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->asDisabled()
            ->build();

        $em = $this->em();
        $em->persist($admin);
        $em->persist($owner);
        $em->persist($disabledMember);
        $em->persist($company);
        $em->persist($membership);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users?company='.$company->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('user+3@example.test', $content);
        self::assertStringContainsString('user+2@example.test', $content);
    }

    public function testInvalidCompanyFilterIsIgnoredAndListsAll(): void
    {
        $client = static::createClient();

        $admin = $this->createAdmin(1);
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $plainUser = UserBuilder::aUser()->withIndex(3)->withRoles(['ROLE_USER'])->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($admin);
        $em->persist($owner);
        $em->persist($plainUser);
        $em->persist($company);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users?company=not-a-uuid');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('user+2@example.test', $content);
        self::assertStringContainsString('user+3@example.test', $content);
    }

    private function createAdmin(int $index): User
    {
        return UserBuilder::aUser()
            ->withIndex($index)
            ->withEmail(sprintf('admin+%d@example.test', $index))
            ->withRoles(['ROLE_ADMIN'])
            ->build();
    }

    private function memberId(int $index): string
    {
        return sprintf('44444444-4444-4444-4444-%012d', $index);
    }
}
