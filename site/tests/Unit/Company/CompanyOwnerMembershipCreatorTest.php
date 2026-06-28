<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CompanyOwnerMembershipCreatorTest extends TestCase
{
    public function testCreateCompanyPersistsCompanyAndOwnerMembershipWithoutFlush(): void
    {
        $owner = UserBuilder::aUser()->build();
        $persisted = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager
            ->expects(self::never())
            ->method('flush');

        $creator = new CompanyOwnerMembershipCreator($entityManager);

        $company = $creator->createCompany($owner, '  Acme LLC  ');

        self::assertSame('Acme LLC', $company->getName());
        self::assertSame($owner, $company->getUser());
        self::assertTrue($owner->getCompanies()->contains($company));
        self::assertCount(2, $persisted);
        self::assertSame($company, $persisted[0]);
        self::assertInstanceOf(CompanyMember::class, $persisted[1]);
        self::assertSame($company, $persisted[1]->getCompany());
        self::assertSame($owner, $persisted[1]->getUser());
        self::assertSame(CompanyMember::ROLE_OWNER, $persisted[1]->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $persisted[1]->getStatus());
        self::assertTrue(Uuid::isValid((string) $company->getId()));
        self::assertTrue(Uuid::isValid((string) $persisted[1]->getId()));
    }

    public function testPersistCompanyWithOwnerMembershipKeepsExistingCompanyFields(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = new Company('11111111-1111-1111-1111-111111111111', $owner);
        $company->setName('Existing LLC');
        $company->setInn('1234567890');

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager
            ->expects(self::never())
            ->method('flush');

        $creator = new CompanyOwnerMembershipCreator($entityManager);

        $result = $creator->persistCompanyWithOwnerMembership($company, $owner);

        self::assertSame($company, $result);
        self::assertSame('Existing LLC', $result->getName());
        self::assertSame('1234567890', $result->getInn());
        self::assertSame($company, $persisted[0]);
        self::assertInstanceOf(CompanyMember::class, $persisted[1]);
    }
}
