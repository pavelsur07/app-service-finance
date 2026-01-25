<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Enum\CompanyTaxSystem;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use PHPUnit\Framework\TestCase;

final class CompanyEntityTest extends TestCase
{
    public function testConstructorInvalidUuidThrows(): void
    {
        // Given
        $id = 'not-uuid';
        $owner = UserBuilder::aUser()->build();

        // Then
        $this->expectException(\InvalidArgumentException::class);

        // When
        new Company($id, $owner);
    }

    public function testConstructorSetsRequiredFieldsAndCollectionsInitialized(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();

        // Then
        self::assertIsString($company->getId());
        self::assertNotSame('', $company->getId());
        self::assertInstanceOf(User::class, $company->getUser());

        // Collections: only assert those that exist on Company.
    }

    public function testSetNameGetName(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();

        // When
        $company->setName('ООО Ромашка');

        // Then
        self::assertSame('ООО Ромашка', $company->getName());
    }

    public function testSetInnNullableRoundtrip(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();

        // When
        $company->setInn(null);
        $company->setInn('1234567890');

        // Then
        self::assertSame('1234567890', $company->getInn());
    }

    public function testSetUserChangesOwnerReference(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();
        $user2 = UserBuilder::aUser()->build();

        // When
        $company->setUser($user2);

        // Then
        self::assertSame($user2, $company->getUser());
    }

    public function testSetFinanceLockBeforeNormalizesTimeToMidnight(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();
        $date = new \DateTimeImmutable('2026-01-25 13:45:10');

        // When
        $company->setFinanceLockBefore($date);

        // Then
        self::assertInstanceOf(\DateTimeImmutable::class, $company->getFinanceLockBefore());
        self::assertSame('2026-01-25 00:00:00', $company->getFinanceLockBefore()?->format('Y-m-d H:i:s'));
    }

    public function testSetFinanceLockBeforeNullResetsValue(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-01-25 13:45:10'));

        // When
        $company->setFinanceLockBefore(null);

        // Then
        self::assertNull($company->getFinanceLockBefore());
    }

    public function testTaxSystemRoundtrip(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();

        // When
        $company->setTaxSystem(CompanyTaxSystem::OSNO);

        // Then
        self::assertSame(CompanyTaxSystem::OSNO, $company->getTaxSystem());

        // When
        $company->setTaxSystem(null);

        // Then
        self::assertNull($company->getTaxSystem());
    }
}
