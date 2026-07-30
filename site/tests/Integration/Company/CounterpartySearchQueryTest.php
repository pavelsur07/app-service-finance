<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Query\CounterpartySearchQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CounterpartySearchQueryTest extends IntegrationTestCase
{
    private CounterpartySearchQuery $query;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CounterpartySearchQuery $query */
        $query = self::getContainer()->get(CounterpartySearchQuery::class);
        $this->query = $query;

        $owner = UserBuilder::aUser()->withEmail('owner-search@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testFindsBothLegalFormOrders(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ромашка"', '7707083893');
        $this->persistCounterparty(2, '"Ромашка" ООО', '7707083894');
        $this->em->flush();

        // When
        $names = $this->searchNames('ромашка');

        // Then
        self::assertContains('ООО "Ромашка"', $names);
        self::assertContains('"Ромашка" ООО', $names);
    }

    public function testFindsByTypo(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ромашка"', '7707083893');
        $this->em->flush();

        // When
        $names = $this->searchNames('рамашка');

        // Then
        self::assertSame(['ООО "Ромашка"'], $names);
    }

    public function testFindsByInnWithoutTouchingNameSearch(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ромашка"', '7707083893');
        $this->persistCounterparty(2, 'ООО "Василёк"', '7707083894');
        $this->em->flush();

        // When
        $names = $this->searchNames('7707083893');

        // Then
        self::assertSame(['ООО "Ромашка"'], $names);
    }

    public function testFindsByInnPrefix(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ромашка"', '7707083893');
        $this->em->flush();

        // When
        $names = $this->searchNames('77070');

        // Then
        self::assertSame(['ООО "Ромашка"'], $names);
    }

    /**
     * Обязательная проверка IDOR: чужая компания не находится ни при каком запросе.
     */
    public function testOtherCompanyCounterpartyIsNeverFound(): void
    {
        // Given
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000002')->withEmail('owner-foreign@example.com')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);

        $foreign = CounterpartyBuilder::aCounterparty()
            ->withId('44444444-4444-4444-4444-444444444444')
            ->withCompany($otherCompany)
            ->withName('ООО "Ромашка"')
            ->withInn('7707083893')
            ->build();
        $this->em->persist($foreign);
        $this->em->flush();

        // Then
        self::assertSame([], $this->searchNames('ромашка'));
        self::assertSame([], $this->searchNames('7707083893'));
        self::assertSame([], $this->searchNames('РОМАШКА'));
    }

    public function testArchivedCounterpartyIsNotFound(): void
    {
        // Given
        $archived = CounterpartyBuilder::aCounterparty()
            ->withId('55555555-5555-5555-5555-555555555555')
            ->withCompany($this->company)
            ->withName('ООО "Ромашка"')
            ->withInn('7707083893')
            ->asArchived()
            ->build();
        $this->em->persist($archived);
        $this->em->flush();

        // Then
        self::assertSame([], $this->searchNames('ромашка'));
        self::assertSame([], $this->searchNames('7707083893'));
    }

    public function testExactCoreRanksAboveSimilarity(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ромашка Плюс"', '7707083893');
        $this->persistCounterparty(2, 'ООО "Ромашка"', '7707083894');
        $this->em->flush();

        // When
        $names = $this->searchNames('ромашка');

        // Then
        self::assertSame('ООО "Ромашка"', $names[0]);
    }

    /**
     * `%` из пользовательского ввода — литерал, а не подстановочный знак: иначе
     * запрос «р%» вытаскивает весь справочник.
     */
    public function testLikeWildcardFromUserInputIsEscaped(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ромашка"', '7707083893');
        $this->persistCounterparty(2, 'ООО "Ромашка Плюс"', '7707083894');
        $this->em->flush();

        // When
        $names = $this->searchNames('р%');

        // Then
        self::assertNotContains('ООО "Ромашка"', $names);
        self::assertNotContains('ООО "Ромашка Плюс"', $names);
    }

    public function testUnderscoreFromUserInputIsEscaped(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ро"', '7707083893');
        $this->em->flush();

        // When
        $names = $this->searchNames('р_');

        // Then
        self::assertNotContains('ООО "Ро"', $names);
    }

    public function testShortQueryReturnsNothing(): void
    {
        // Given
        $this->persistCounterparty(1, 'ООО "Ромашка"', '7707083893');
        $this->em->flush();

        // Then
        self::assertSame([], $this->searchNames('р'));
        self::assertSame([], $this->searchNames(''));
    }

    public function testLimitIsCapped(): void
    {
        // Given
        for ($i = 1; $i <= 25; ++$i) {
            $this->persistCounterparty($i, sprintf('ООО "Ромашка %d"', $i), sprintf('770708%04d', $i));
        }
        $this->em->flush();

        // When
        $items = $this->query->search($this->company->getId(), 'ромашка', 100);

        // Then
        self::assertCount(20, $items);
    }

    /**
     * @return list<string>
     */
    private function searchNames(string $query): array
    {
        return array_map(
            static fn (array $row): string => $row['name'],
            $this->query->search($this->company->getId(), $query),
        );
    }

    private function persistCounterparty(int $index, string $name, ?string $inn): void
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->withCompany($this->company)
            ->withName($name)
            ->withInn($inn)
            ->build();

        $this->em->persist($counterparty);
    }
}
