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

/**
 * Список выбора контрагента в формах: архивные не предлагаются, но уже выбранное
 * значение из списка не исчезает.
 */
final class CounterpartySelectableListTest extends IntegrationTestCase
{
    private CounterpartyRepository $repository;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CounterpartyRepository $repository */
        $repository = self::getContainer()->get(CounterpartyRepository::class);
        $this->repository = $repository;

        $owner = UserBuilder::aUser()->withEmail('owner-selectable@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testArchivedIsNotOffered(): void
    {
        // Given
        $this->persist(1, 'ООО "Активный"', false);
        $this->persist(2, 'ООО "Архивный"', true);
        $this->em->flush();

        // When
        $names = $this->names($this->repository->findSelectableByCompany($this->company->getId()));

        // Then
        self::assertSame(['ООО "Активный"'], $names);
    }

    public function testCurrentlySelectedArchivedStaysInList(): void
    {
        // Given
        $this->persist(1, 'ООО "Активный"', false);
        $archivedId = $this->persist(2, 'ООО "Архивный"', true);
        $this->em->flush();

        // When
        $names = $this->names($this->repository->findSelectableByCompany($this->company->getId(), $archivedId));

        // Then
        self::assertSame(['ООО "Активный"', 'ООО "Архивный"'], $names);
    }

    public function testOtherCompanyIsNotOffered(): void
    {
        // Given
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000004')->withEmail('owner-selectable-2@example.com')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);

        $foreign = CounterpartyBuilder::aCounterparty()
            ->withId('44444444-4444-4444-4444-000000000001')
            ->withCompany($otherCompany)
            ->withName('ООО "Чужой"')
            ->build();
        $this->em->persist($foreign);
        $this->em->flush();

        // When: даже переданный keepId чужой компании не должен просачиваться
        $names = $this->names($this->repository->findSelectableByCompany($this->company->getId(), $foreign->getId()));

        // Then
        self::assertSame([], $names);
    }

    /**
     * @param list<Counterparty> $counterparties
     *
     * @return list<string>
     */
    private function names(array $counterparties): array
    {
        return array_map(static fn (Counterparty $c): string => $c->getName(), $counterparties);
    }

    private function persist(int $index, string $name, bool $archived): string
    {
        $builder = CounterpartyBuilder::aCounterparty()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->withCompany($this->company)
            ->withName($name)
            ->withInn(null);

        if ($archived) {
            $builder = $builder->asArchived();
        }

        $counterparty = $builder->build();
        $this->em->persist($counterparty);

        return $counterparty->getId();
    }
}
