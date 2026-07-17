<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
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
            ->expects(self::exactly(6))
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
        self::assertCount(6, $persisted);
        self::assertSame($company, $persisted[0]);
        self::assertInstanceOf(CompanyMember::class, $persisted[1]);
        self::assertSame($company, $persisted[1]->getCompany());
        self::assertSame($owner, $persisted[1]->getUser());
        self::assertSame(CompanyMember::ROLE_OWNER, $persisted[1]->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $persisted[1]->getStatus());
        self::assertTrue(Uuid::isValid((string) $company->getId()));
        self::assertTrue(Uuid::isValid((string) $persisted[1]->getId()));
        self::assertInstanceOf(ProjectDirection::class, $persisted[2]);
        self::assertSame($company, $persisted[2]->getCompany());
        self::assertSame('Общий', $persisted[2]->getName());
        self::assertSame(ProjectDirection::CODE_GENERAL, $persisted[2]->getSystemCode());
        self::assertNull($persisted[2]->getParent());
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $persisted[3]);
        self::assertSame($company->getId(), $persisted[3]->getCompanyId());
        self::assertSame(FinancialResponsibilityCenter::CODE_GENERAL, $persisted[3]->getCode());
        self::assertSame(FinancialResponsibilityCenter::NAME_GENERAL, $persisted[3]->getName());
        self::assertInstanceOf(FinancialResponsibilityCenterProject::class, $persisted[4]);
        self::assertSame($persisted[2], $persisted[4]->getProjectDirection());
        self::assertSame($persisted[3], $persisted[4]->getResponsibilityCenter());
        self::assertInstanceOf(MoneyAccount::class, $persisted[5]);
        self::assertSame($company, $persisted[5]->getCompany());
        self::assertSame('Основной счет', $persisted[5]->getName());
        self::assertSame(MoneyAccountType::BANK, $persisted[5]->getType());
        self::assertSame('RUB', $persisted[5]->getCurrency());
        self::assertTrue($persisted[5]->isActive());
        self::assertFalse($persisted[5]->isDefault());
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
            ->expects(self::exactly(6))
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
        self::assertInstanceOf(ProjectDirection::class, $persisted[2]);
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $persisted[3]);
        self::assertInstanceOf(FinancialResponsibilityCenterProject::class, $persisted[4]);
        self::assertInstanceOf(MoneyAccount::class, $persisted[5]);
    }
}
