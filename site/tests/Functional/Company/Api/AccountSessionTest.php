<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company\Api;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Support\Spa\SpaWebTestCase;
use Ramsey\Uuid\Uuid;

final class AccountSessionTest extends SpaWebTestCase
{
    public function testAnonymousSessionIs401Json(): void
    {
        $client = static::createClient();
        $this->get($client, 'session');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthenticated', $this->body($client)['error']['code']);
    }

    public function testUserWithoutCompanyHasSuccessfulEmptyContext(): void
    {
        $client = static::createClient();
        $user = $this->user();
        $this->login($client, $user);
        $this->get($client, 'session');
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $user->getId(), 'email' => $user->getEmail()], $this->body($client)['user']);
        self::assertNull($this->body($client)['active_company']);
        self::assertSame(['none'], array_values(array_unique($this->body($client)['permissions'])));
    }

    public function testOwnerKeepsWriteWithoutMembershipAndDeletedCompanyDisappears(): void
    {
        $client = static::createClient();
        $user = $this->user();
        $company = $this->company($user, 'Owned');
        $id = (string) $company->getId();
        $this->login($client, $user);
        $this->get($client, 'session');
        self::assertResponseIsSuccessful();
        self::assertSame($id, $this->body($client)['active_company']['id']);
        self::assertSame(['write'], array_values(array_unique($this->body($client)['permissions'])));
        $company = $this->em()->find(Company::class, $id);
        self::assertInstanceOf(Company::class, $company);
        $company->requestDeletion(new \DateTimeImmutable(), (string) $user->getId());
        $this->em()->flush();
        $this->get($client, 'session');
        self::assertResponseIsSuccessful();
        self::assertNull($this->body($client)['active_company']);
    }

    public function testMemberWithoutRoleHasNoPermissionsAndRevocationRemovesCompany(): void
    {
        $client = static::createClient();
        $user = $this->user();
        $company = $this->company($this->user(), 'Member');
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($user)->build();
        $this->em()->persist($member);
        $this->em()->flush();
        $memberId = $member->getId();
        $companyId = $company->getId();
        $this->login($client, $user);
        $this->get($client, 'session');
        self::assertResponseIsSuccessful();
        self::assertSame($companyId, $this->body($client)['active_company']['id']);
        self::assertSame(['none'], array_values(array_unique($this->body($client)['permissions'])));
        $member = $this->em()->find(CompanyMember::class, $memberId);
        self::assertInstanceOf(CompanyMember::class, $member);
        $this->em()->remove($member);
        $this->em()->flush();
        $this->get($client, 'session');
        self::assertNull($this->body($client)['active_company']);
    }
}
