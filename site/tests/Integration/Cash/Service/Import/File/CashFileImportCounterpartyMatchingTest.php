<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import\File;

use App\Cash\Service\Import\File\CashFileImportService;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Матчинг контрагента при импорте файла — тот самый путь, из-за которого справочник
 * заполнялся дублями. Проверяется реальный код сервиса, а не только запрос репозитория:
 * нормализация, ключ кэша и ветка создания.
 *
 * Приватный метод вызывается рефлексией — так же, как в существующем
 * tests/Unit/Cash/Service/Import/File/CashFileImportServiceTest.
 */
final class CashFileImportCounterpartyMatchingTest extends IntegrationTestCase
{
    private CashFileImportService $service;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CashFileImportService $service */
        $service = self::getContainer()->get(CashFileImportService::class);
        $this->service = $service;

        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000030')->withEmail('owner-import-match@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testDifferentSpellingsResolveToOneCounterparty(): void
    {
        // When: три написания одного контрагента, как они приходят из разных выписок
        $first = $this->resolve('ООО "Ромашка"');
        $second = $this->resolve('"Ромашка" ООО');
        $third = $this->resolve('ооо   ромашка');
        $this->em->flush();

        // Then
        self::assertSame($first->getId(), $second->getId());
        self::assertSame($first->getId(), $third->getId());
        self::assertSame(1, $this->countCounterparties());
    }

    /**
     * Ключ кэша не должен быть единственной защитой: после flushBatch() кэш сбрасывается,
     * и повторный импорт обязан найти контрагента в БД.
     */
    public function testCounterpartyIsFoundInDatabaseAfterCacheReset(): void
    {
        // Given
        $first = $this->resolve('ООО "Ромашка"');
        $this->em->flush();
        $this->resetServiceCache();

        // When
        $second = $this->resolve('"Ромашка" ООО');
        $this->em->flush();

        // Then
        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $this->countCounterparties());
    }

    /**
     * Реальный случай PROD: ООО и АО «Балтийский лизинг» — разные юрлица.
     */
    public function testDifferentLegalFormsCreateTwoCounterparties(): void
    {
        // When
        $ooo = $this->resolve('ООО "Балтийский лизинг"');
        $ao = $this->resolve('АО "Балтийский лизинг"');
        $this->em->flush();

        // Then
        self::assertNotSame($ooo->getId(), $ao->getId());
        self::assertSame(2, $this->countCounterparties());
    }

    public function testNameWithoutLegalFormIsSeparateFromNameWithOne(): void
    {
        // When
        $person = $this->resolve('Агрба Анна Сергеевна');
        $company = $this->resolve('ООО "Агрба Анна Сергеевна"');
        $this->em->flush();

        // Then
        self::assertNotSame($person->getId(), $company->getId());
        self::assertSame(2, $this->countCounterparties());
    }

    public function testExistingCounterpartyIsReusedRegardlessOfSpelling(): void
    {
        // Given: контрагент уже есть в справочнике, создан не импортом
        $existing = $this->resolve('ООО "Ромашка"');
        $this->em->flush();
        $existingId = $existing->getId();
        $this->em->clear();
        $this->resetServiceCache();

        // When
        $found = $this->resolve('Общество с ограниченной ответственностью "Ромашка"');
        $this->em->flush();

        // Then
        self::assertSame($existingId, $found->getId());
        self::assertSame(1, $this->countCounterparties());
    }

    public function testEmptyNameIsRejected(): void
    {
        // Then
        $this->expectException(\RuntimeException::class);

        // When
        $this->resolve('   ');
    }

    private function resolve(string $name): Counterparty
    {
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        $method = new \ReflectionMethod(CashFileImportService::class, 'getOrCreateCounterparty');

        /** @var Counterparty $counterparty */
        $counterparty = $method->invoke($this->service, $company->getId(), $name, $company);

        return $counterparty;
    }

    private function resetServiceCache(): void
    {
        $cache = new \ReflectionProperty(CashFileImportService::class, 'counterpartyCache');
        $cache->setValue($this->service, []);
    }

    private function countCounterparties(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM "counterparty" WHERE company_id = :companyId',
            ['companyId' => $this->company->getId()],
        );
    }
}
