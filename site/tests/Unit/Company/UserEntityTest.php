<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

final class UserEntityTest extends TestCase
{
    public function testConstructorInvalidUuidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User('not-uuid');
    }

    public function testConstructorSetsCreatedAtDefault(): void
    {
        $user = new User(UserBuilder::DEFAULT_USER_ID);

        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
    }

    public function testConstructorUsesPassedCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        $user = new User(UserBuilder::DEFAULT_USER_ID, $createdAt);

        self::assertSame($createdAt, $user->getCreatedAt());
    }

    public function testSetEmailInvalidThrows(): void
    {
        $user = UserBuilder::aUser()->build();

        $this->expectException(\InvalidArgumentException::class);

        $user->setEmail('not-email');
    }

    public function testSetEmailGetUserIdentifierReturnsEmail(): void
    {
        $user = UserBuilder::aUser()->build();

        $user->setEmail('a@b.test');

        self::assertSame('a@b.test', $user->getEmail());
        self::assertSame('a@b.test', $user->getUserIdentifier());
    }

    public function testGetRolesAlwaysContainsRoleUserAndUnique(): void
    {
        $user = UserBuilder::aUser()->build();

        $user->setRoles(['ROLE_USER', 'ROLE_COMPANY_OWNER', 'ROLE_COMPANY_OWNER']);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertSame(count($roles), count(array_unique($roles)));
    }

    public function testSetRolesEmptyStillReturnsRoleUser(): void
    {
        $user = UserBuilder::aUser()->build();

        $user->setRoles([]);

        $roles = $user->getRoles();

        self::assertSame(['ROLE_USER'], $roles);
    }

    public function testCompaniesCollectionInitializedEmpty(): void
    {
        $user = UserBuilder::aUser()->build();

        self::assertInstanceOf(Collection::class, $user->getCompanies());
        self::assertSame(0, $user->getCompanies()->count());
    }

    public function testAddCompanyAddsAndSetsCompanyUser(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->build();

        $user->addCompany($company);

        self::assertTrue($user->getCompanies()->contains($company));
        self::assertSame($user, $company->getUser());
    }

    public function testAddCompanyIsIdempotent(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->build();

        $user->addCompany($company);
        $user->addCompany($company);

        self::assertSame(1, $user->getCompanies()->count());
    }

    public function testRemoveCompanyRemovesAndKeepsCompanyUserIfOwned(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->build();

        $user->addCompany($company);

        $user->removeCompany($company);

        self::assertFalse($user->getCompanies()->contains($company));
        self::assertSame($user, $company->getUser());
    }

    public function testSerializeHashesPasswordNotPlain(): void
    {
        $user = UserBuilder::aUser()->build();
        $user->setPassword('secret_hash');

        $data = $user->__serialize();

        self::assertArrayHasKey("\0".User::class."\0password", $data);
        self::assertSame(hash('crc32c', 'secret_hash'), $data["\0".User::class."\0password"]);
        self::assertNotSame('secret_hash', $data["\0".User::class."\0password"]);
    }

    public function testEraseCredentialsDoesNotThrow(): void
    {
        $user = UserBuilder::aUser()->build();

        $user->eraseCredentials();

        self::assertTrue(true);
    }
}
