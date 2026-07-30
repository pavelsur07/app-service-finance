<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Query\CounterpartyDuplicateCandidatesQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CounterpartyDuplicateCandidatesQueryTest extends IntegrationTestCase
{
    private CounterpartyDuplicateCandidatesQuery $query;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CounterpartyDuplicateCandidatesQuery $query */
        $query = self::getContainer()->get(CounterpartyDuplicateCandidatesQuery::class);
        $this->query = $query;

        $owner = UserBuilder::aUser()->withEmail('owner-dupes@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testDifferentSpellingsOfSameNameArePaired(): void
    {
        // Given
        $this->persist(1, 'ООО "Ромашка"', '7707083893');
        $this->persist(2, '"Ромашка" ООО', '7707083894');
        $this->em->flush();

        // When
        $pairs = $this->query->findSimilarNamePairs(0.6, $this->company->getId());

        // Then
        self::assertCount(1, $pairs);
    }

    /**
     * Реальный случай PROD: ООО и АО «Балтийский лизинг» — разные юрлица.
     * Нормализация склеивает их core, поэтому отчёт обязан разводить их по ОПФ.
     */
    public function testDifferentLegalFormsAreNotPaired(): void
    {
        // Given
        $this->persist(1, 'ООО "Балтийский лизинг"', '7707083893');
        $this->persist(2, 'АО "Балтийский лизинг"', '7707083894');
        $this->em->flush();

        // When
        $pairs = $this->query->findSimilarNamePairs(0.6, $this->company->getId());

        // Then
        self::assertSame([], $pairs);
    }

    public function testArchivedRowsAreNotPaired(): void
    {
        // Given
        $this->persist(1, 'ООО "Ромашка"', '7707083893');
        $archived = CounterpartyBuilder::aCounterparty()
            ->withId('33333333-3333-3333-3333-000000000002')
            ->withCompany($this->company)
            ->withName('"Ромашка" ООО')
            ->withInn('7707083894')
            ->asArchived()
            ->build();
        $this->em->persist($archived);
        $this->em->flush();

        // Then
        self::assertSame([], $this->query->findSimilarNamePairs(0.6, $this->company->getId()));
    }

    public function testSameInnGroupsAreReported(): void
    {
        // Given
        $this->persist(1, 'ООО "Ромашка"', '7707083893');
        $this->persist(2, 'ООО "Ромашка Торг"', '7707083893');
        $this->em->flush();

        // When
        $groups = $this->query->findSameInnGroups($this->company->getId());

        // Then
        self::assertCount(1, $groups);
        self::assertSame(2, (int) $groups[0]['rows']);
    }

    public function testInvalidInnIsReported(): void
    {
        // Given: мусорный ИНН, какой встречается в исторических данных
        $this->persist(1, 'ООО "Ромашка"', null);
        $this->em->flush();
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET inn = :inn WHERE id = :id',
            ['inn' => '7', 'id' => '33333333-3333-3333-3333-000000000001'],
        );

        // When
        $rows = $this->query->findInvalidInnRows($this->company->getId());

        // Then
        self::assertCount(1, $rows);
        self::assertSame('7', $rows[0]['inn']);
    }

    public function testOtherCompanyIsNotMixedIn(): void
    {
        // Given
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000003')->withEmail('owner-dupes-2@example.com')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);

        $this->persist(1, 'ООО "Ромашка"', '7707083893');

        $foreign = CounterpartyBuilder::aCounterparty()
            ->withId('44444444-4444-4444-4444-000000000001')
            ->withCompany($otherCompany)
            ->withName('"Ромашка" ООО')
            ->withInn('7707083893')
            ->build();
        $this->em->persist($foreign);
        $this->em->flush();

        // Then
        self::assertSame([], $this->query->findSimilarNamePairs(0.6, $this->company->getId()));
        self::assertSame([], $this->query->findSameInnGroups($this->company->getId()));
    }

    private function persist(int $index, string $name, ?string $inn): void
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
