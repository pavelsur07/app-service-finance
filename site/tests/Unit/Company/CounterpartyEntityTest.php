<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\TestCase;

final class CounterpartyEntityTest extends TestCase
{
    private CounterpartyNameNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CounterpartyNameNormalizer();
    }

    public function testConstructorInvalidUuidThrows(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();

        // Then
        $this->expectException(\InvalidArgumentException::class);

        // When
        new Counterparty('not-uuid', $company, $this->normalizer->normalize('Counterparty 1'), CounterpartyType::LEGAL_ENTITY);
    }

    public function testBuilderBuildsValidEntity(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // Then
        self::assertNotSame('', $counterparty->getId());
        self::assertInstanceOf(Company::class, $counterparty->getCompany());
        self::assertSame(CounterpartyBuilder::DEFAULT_NAME, $counterparty->getName());
        self::assertSame(CounterpartyBuilder::DEFAULT_INN, $counterparty->getInn());
        self::assertSame(CounterpartyBuilder::DEFAULT_TYPE, $counterparty->getType());
        self::assertSame(CounterpartyBuilder::DEFAULT_IS_ARCHIVED, $counterparty->isArchived());
    }

    public function testConstructorFillsDerivedNameFields(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();

        // When
        $counterparty = new Counterparty(
            '11111111-1111-1111-1111-111111111111',
            $company,
            $this->normalizer->normalize('ООО "Ромашка"'),
            CounterpartyType::LEGAL_ENTITY,
        );

        // Then
        self::assertSame('ООО "Ромашка"', $counterparty->getName());
        self::assertSame('РОМАШКА', $counterparty->getNameCore());
        self::assertSame('ООО', $counterparty->getLegalFormHint());
    }

    public function testRenameKeepsDerivedFieldsConsistent(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->rename($this->normalizer->normalize('"Ромашка" ООО'));

        // Then
        self::assertSame('"Ромашка" ООО', $counterparty->getName());
        self::assertSame('РОМАШКА', $counterparty->getNameCore());
        self::assertSame('ООО', $counterparty->getLegalFormHint());
    }

    public function testRenameTouchesUpdatedAt(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withUpdatedAt(new \DateTimeImmutable('2020-01-01 00:00:00'))
            ->build();

        // When
        $counterparty->rename($this->normalizer->normalize('ООО Тест'));

        // Then
        self::assertGreaterThan(new \DateTimeImmutable('2020-01-02 00:00:00'), $counterparty->getUpdatedAt());
    }

    public function testRefreshNormalizedNameKeepsUpdatedAtUntouched(): void
    {
        // Given
        $updatedAt = new \DateTimeImmutable('2020-01-01 00:00:00');
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withName('ООО "Ромашка"')
            ->withUpdatedAt($updatedAt)
            ->build();

        // When
        $counterparty->refreshNormalizedName($this->normalizer->normalize('ООО "Ромашка"'));

        // Then
        self::assertSame('РОМАШКА', $counterparty->getNameCore());
        self::assertSame($updatedAt->getTimestamp(), $counterparty->getUpdatedAt()->getTimestamp());
    }

    public function testRefreshNormalizedNameCannotRename(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('ООО "Ромашка"')->build();

        // Then
        $this->expectException(\InvalidArgumentException::class);

        // When
        $counterparty->refreshNormalizedName($this->normalizer->normalize('ООО "Василёк"'));
    }

    public function testAssignTaxIdsRoundtrip(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->assignTaxIds('7707083893', '770701001');

        // Then
        self::assertSame('7707083893', $counterparty->getInn());
        self::assertSame('770701001', $counterparty->getKpp());
        self::assertTrue($counterparty->hasTaxId());
    }

    public function testAssignTaxIdsAllowsClearingBoth(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->assignTaxIds(null, null);

        // Then
        self::assertNull($counterparty->getInn());
        self::assertNull($counterparty->getKpp());
        self::assertFalse($counterparty->hasTaxId());
    }

    public function testKppWithoutInnThrows(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // Then
        $this->expectException(\InvalidArgumentException::class);

        // When
        $counterparty->assignTaxIds(null, '770101001');
    }

    public function testBelongsToCompanyPositive(): void
    {
        // Given
        $company = CompanyBuilder::aCompany()->build();
        $counterparty = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();

        // Then
        self::assertTrue($counterparty->belongsToCompany($company->getId()));
    }

    public function testBelongsToCompanyNegative(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // Then
        self::assertFalse($counterparty->belongsToCompany('99999999-9999-9999-9999-999999999999'));
    }

    public function testInconsistentLegalFormHintForLegalEntityInn(): void
    {
        // Given: разобранный «ИП» при 10-значном ИНН — ошибка парсера названия
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withName('ИП Кулешова Анастасия Владимировна')
            ->withInn('7707083893')
            ->build();

        // Then
        self::assertTrue($counterparty->hasInconsistentLegalFormHint());
    }

    public function testConsistentLegalFormHintForTwelveDigitInn(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withName('ИП Кулешова Анастасия Владимировна')
            ->withInn('503200000010')
            ->build();

        // Then
        self::assertFalse($counterparty->hasInconsistentLegalFormHint());
    }

    public function testClearLegalFormHintKeepsName(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withName('ИП Кулешова Анастасия Владимировна')
            ->withInn('7707083893')
            ->build();

        // When
        $counterparty->clearLegalFormHint();

        // Then
        self::assertNull($counterparty->getLegalFormHint());
        self::assertSame('ИП Кулешова Анастасия Владимировна', $counterparty->getName());
        self::assertSame('КУЛЕШОВА АНАСТАСИЯ ВЛАДИМИРОВНА', $counterparty->getNameCore());
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

    public function testArchiveAndRestore(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        // When
        $counterparty->archive();

        // Then
        self::assertTrue($counterparty->isArchived());

        // When
        $counterparty->restore();

        // Then
        self::assertFalse($counterparty->isArchived());
    }

    /**
     * Смена компании закрыта навсегда: это вектор IDOR, а не удобство.
     */
    public function testCompanyCannotBeChanged(): void
    {
        // Then
        self::assertFalse(method_exists(Counterparty::class, 'setCompany'));
        self::assertFalse(method_exists(Counterparty::class, 'setName'));
        self::assertFalse(method_exists(Counterparty::class, 'setInn'));
        self::assertFalse(method_exists(Counterparty::class, 'setIsArchived'));
        self::assertFalse(method_exists(Counterparty::class, 'setUpdatedAt'));
    }
}
