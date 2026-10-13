<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Repository\CounterpartyRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Поиск на странице «Справочники → Контрагенты»: регистр запроса не важен, в том числе
 * для кириллицы. strtolower с PHP 8.2 понижает только ASCII, а LOWER(name) в Postgres —
 * всё, поэтому «Альфа» не находила «Альфа-Снаб».
 */
final class CounterpartyListSearchTest extends IntegrationTestCase
{
    private CounterpartyRepository $repository;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CounterpartyRepository $repository */
        $repository = self::getContainer()->get(CounterpartyRepository::class);
        $this->repository = $repository;

        $owner = UserBuilder::aUser()->withEmail('owner-list-search@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);

        $this->persist(1, 'ООО "Альфа-Снаб"', '7708123456');
        $this->persist(2, 'ООО "Бета-Логистик"', '7812345678');
        $this->em->flush();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cyrillicCaseProvider(): iterable
    {
        yield 'с заглавной' => ['Альфа'];
        yield 'капсом' => ['АЛЬФА'];
        yield 'строчными' => ['альфа'];
    }

    #[DataProvider('cyrillicCaseProvider')]
    public function testNameSearchIgnoresCyrillicCase(string $query): void
    {
        // When
        $names = $this->names($this->repository->findByFilters($this->company, null, $query, false));

        // Then
        self::assertSame(['ООО "Альфа-Снаб"'], $names);
    }

    public function testInnSearchStillMatchesSubstring(): void
    {
        // When
        $names = $this->names($this->repository->findByFilters($this->company, null, '234567', false));

        // Then
        self::assertSame(['ООО "Бета-Логистик"'], $names);
    }

    /**
     * @param array<Counterparty> $counterparties
     *
     * @return list<string>
     */
    private function names(array $counterparties): array
    {
        return array_values(array_map(static fn (Counterparty $c): string => $c->getName(), $counterparties));
    }

    private function persist(int $index, string $name, string $inn): void
    {
        $this->em->persist(
            CounterpartyBuilder::aCounterparty()
                ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
                ->withCompany($this->company)
                ->withName($name)
                ->withInn($inn)
                ->build(),
        );
    }
}
