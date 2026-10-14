<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Repository\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Поиск счетов: регистр запроса не важен, в том числе для кириллицы. strtolower с PHP 8.2
 * понижает только ASCII, а LOWER() в Postgres — всё (тот же дефект, что был у контрагентов,
 * #2665). На 14.10.2026 вызовов с поиском нет — тест держит репозиторий для будущего поиска
 * и изоляцию по компании: подходящий счёт другой компании не попадает в выдачу.
 */
final class MoneyAccountSearchTest extends IntegrationTestCase
{
    private MoneyAccountRepository $repository;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var MoneyAccountRepository $repository */
        $repository = self::getContainer()->get(MoneyAccountRepository::class);
        $this->repository = $repository;

        $owner = UserBuilder::aUser()->withEmail('owner-account-search@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);

        $sber = MoneyAccountBuilder::aMoneyAccount()->withId('33333333-3333-3333-3333-000000000101')->forCompany($this->company)->withName('Расчётный основной')->build();
        $sber->setBankName('ПАО Сбербанк');
        $alfa = MoneyAccountBuilder::aMoneyAccount()->withId('33333333-3333-3333-3333-000000000102')->forCompany($this->company)->withName('Альфа резерв')->build();
        $alfa->setBankName('АО «Альфа-Банк»');
        $this->em->persist($sber);
        $this->em->persist($alfa);

        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000031')->withEmail('owner-account-search-2@example.com')->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $foreign = MoneyAccountBuilder::aMoneyAccount()->withId('33333333-3333-3333-3333-000000000103')->forCompany($other)->withName('Альфа резерв чужой')->build();
        $foreign->setBankName('ПАО Сбербанк');
        $this->em->persist($otherOwner);
        $this->em->persist($other);
        $this->em->persist($foreign);
        $this->em->flush();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cyrillicCaseProvider(): iterable
    {
        yield 'банк с заглавной' => ['Сбер', 'Расчётный основной'];
        yield 'банк капсом' => ['СБЕРБАНК', 'Расчётный основной'];
        yield 'банк строчными' => ['сбер', 'Расчётный основной'];
        yield 'название с заглавной' => ['Альфа', 'Альфа резерв'];
        yield 'название капсом' => ['РЕЗЕРВ', 'Альфа резерв'];
    }

    #[DataProvider('cyrillicCaseProvider')]
    public function testSearchIgnoresCyrillicCase(string $query, string $expectedName): void
    {
        // When
        $accounts = $this->repository->findByFilters($this->company, null, null, null, $query, ['name' => \SortDirection::Ascending]);

        // Then
        self::assertSame([$expectedName], array_values(array_map(static fn (MoneyAccount $a): string => $a->getName(), $accounts)));
    }
}
