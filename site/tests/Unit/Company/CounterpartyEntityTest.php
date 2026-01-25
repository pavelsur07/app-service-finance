<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Entity\Company;
use App\Company\Enum\CounterpartyType;
use App\Entity\Counterparty;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\TestCase;

final class CounterpartyEntityTest extends TestCase
{
    public function testConstructorInvalidUuidThrows(): void
    {
        // Given
        $id = 'not-uuid';
        $company = CompanyBuilder::aCompany()->build();
        $name = 'Counterparty 1';
        $type = CounterpartyType::LEGAL_ENTITY;

        // Then
        $this->expectException(\InvalidArgumentException::class);

        // When
        new Counterparty($id, $company, $name, $type);
    }

    public function testBuilderBuildsValidEntity(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // Then
        self::assertIsString($counterparty->getId());
        self::assertNotSame('', $counterparty->getId());
        self::assertInstanceOf(Company::class, $counterparty->getCompany());
        self::assertSame(CounterpartyBuilder::DEFAULT_NAME, $counterparty->getName());
        self::assertSame(CounterpartyBuilder::DEFAULT_INN, $counterparty->getInn());
        self::assertSame(CounterpartyBuilder::DEFAULT_TYPE, $counterparty->getType());
        self::assertSame(CounterpartyBuilder::DEFAULT_IS_ARCHIVED, $counterparty->isArchived());
    }

    public function testSetNameGetNameRoundtrip(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->setName('ООО Тест');

        // Then
        self::assertSame('ООО Тест', $counterparty->getName());
    }

    public function testSetInnNullableRoundtrip(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->setInn(null);
        $counterparty->setInn('7707083893');

        // Then
        self::assertSame('7707083893', $counterparty->getInn());
    }

    public function testSetCompanyChangesReference(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();
        $company2 = CompanyBuilder::aCompany()->build();

        // When
        $counterparty->setCompany($company2);

        // Then
        self::assertSame($company2, $counterparty->getCompany());
    }

    public function testSetTypeGetTypeRoundtrip(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->setType(CounterpartyType::NATURAL_PERSON);

        // Then
        self::assertSame(CounterpartyType::NATURAL_PERSON, $counterparty->getType());
    }

    public function testArchiveFlagRoundtrip(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->setIsArchived(true);

        // Then
        self::assertTrue($counterparty->isArchived());
    }

    public function testUpdatedAtRoundtrip(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();
        $updatedAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        // When
        $counterparty->setUpdatedAt($updatedAt);

        // Then
        self::assertSame($updatedAt, $counterparty->getUpdatedAt());
    }

    // В сущности нет валидации name/inn через Assert в сеттерах, поэтому тесты на ошибки не нужны.
}
