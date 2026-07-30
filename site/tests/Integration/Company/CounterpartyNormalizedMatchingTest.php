<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Company\Repository\CounterpartyRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Запрос по нормализованному ключу названия — строительный блок матчинга.
 *
 * Поведение самого импорта проверяется отдельно, в
 * tests/Integration/Cash/Service/Import/File/CashFileImportCounterpartyMatchingTest:
 * здесь только контракт репозитория.
 */
final class CounterpartyNormalizedMatchingTest extends IntegrationTestCase
{
    private CounterpartyRepository $repository;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CounterpartyRepository $repository */
        $repository = self::getContainer()->get(CounterpartyRepository::class);
        $this->repository = $repository;

        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000020')->withEmail('owner-matching@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testNormalizedKeyFindsCounterpartySavedWithLegalFormPrefix(): void
    {
        // Given
        $id = $this->persist(1, 'ООО "Ромашка"');
        $this->em->flush();

        // When: во второй выписке то же название с ОПФ в конце
        $found = $this->repository->findOneByNormalizedName($this->company->getId(), 'РОМАШКА', 'ООО');

        // Then
        self::assertSame($id, $found?->getId());
    }

    public function testNormalizedKeyFindsRowSavedWithExtraWhitespaceAndLowerCase(): void
    {
        // Given
        $id = $this->persist(1, 'ооо   ромашка');
        $this->em->flush();

        // When
        $found = $this->repository->findOneByNormalizedName($this->company->getId(), 'РОМАШКА', 'ООО');

        // Then
        self::assertSame($id, $found?->getId());
    }

    /**
     * Реальный случай PROD: ООО и АО «Балтийский лизинг» — разные юрлица.
     */
    public function testDifferentLegalFormIsNotMatched(): void
    {
        // Given
        $this->persist(1, 'ООО "Балтийский лизинг"');
        $this->em->flush();

        // When
        $found = $this->repository->findOneByNormalizedName($this->company->getId(), 'БАЛТИЙСКИЙ ЛИЗИНГ', 'АО');

        // Then
        self::assertNull($found);
    }

    public function testNameWithoutLegalFormMatchesOnlyNullHint(): void
    {
        // Given
        $id = $this->persist(1, 'Агрба Анна Сергеевна');
        $this->em->flush();

        // When
        $found = $this->repository->findOneByNormalizedName($this->company->getId(), 'АГРБА АННА СЕРГЕЕВНА', null);

        // Then
        self::assertSame($id, $found?->getId());

        // And: та же основа с ОПФ — это другой контрагент
        self::assertNull($this->repository->findOneByNormalizedName($this->company->getId(), 'АГРБА АННА СЕРГЕЕВНА', 'ООО'));
    }

    public function testOtherCompanyIsNotMatched(): void
    {
        // Given
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000021')->withEmail('owner-matching-2@example.com')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);

        $foreign = CounterpartyBuilder::aCounterparty()
            ->withId('44444444-4444-4444-4444-000000000020')
            ->withCompany($otherCompany)
            ->withName('ООО "Ромашка"')
            ->withInn(null)
            ->build();
        $this->em->persist($foreign);
        $this->em->flush();

        // Then
        self::assertNull($this->repository->findOneByNormalizedName($this->company->getId(), 'РОМАШКА', 'ООО'));
    }

    public function testArchivedCounterpartyIsStillMatched(): void
    {
        // Given: архивного не предлагаем в формах, но импорт не должен создавать дубль
        $archived = CounterpartyBuilder::aCounterparty()
            ->withId('33333333-3333-3333-3333-000000000030')
            ->withCompany($this->company)
            ->withName('ООО "Ромашка"')
            ->withInn(null)
            ->asArchived()
            ->build();
        $this->em->persist($archived);
        $this->em->flush();

        // When
        $found = $this->repository->findOneByNormalizedName($this->company->getId(), 'РОМАШКА', 'ООО');

        // Then
        self::assertSame($archived->getId(), $found?->getId());
    }

    private function persist(int $index, string $name): string
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->withCompany($this->company)
            ->withName($name)
            ->withInn(null)
            ->build();

        $this->em->persist($counterparty);

        return $counterparty->getId();
    }
}
