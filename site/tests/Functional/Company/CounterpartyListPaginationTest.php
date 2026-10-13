<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * «Справочники → Контрагенты» отдаётся по 50 строк (Pagerfanta), а не всем справочником:
 * итог и число архивных — по всем страницам, фильтры переходят в ссылки страниц,
 * неверные page/limit — 422 (CLAUDE.md, «Производительность»).
 */
final class CounterpartyListPaginationTest extends WebTestCaseBase
{
    public function testFirstPageShowsFiftyAndTotalCountsAllPages(): void
    {
        // Given: 51 контрагент своей компании и один чужой
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 931);
        $this->createCounterparties($company, 51, 'Поставщик');
        [, $foreignCompany] = $this->createCompany($client, 932);
        $this->createCounterparties($foreignCompany, 1, 'Чужой');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        // When
        $crawler = $client->request('GET', '/counterparties/');

        // Then
        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('.vf-table tbody tr'));
        self::assertSelectorTextContains('.vf-bar', 'Найдено 51 контрагент');
        self::assertSelectorTextNotContains('.vf-table', 'Чужой');
        $next = $crawler->filter('.pagination a[aria-label="Вперёд"]')->attr('href');
        self::assertNotNull($next);
        self::assertStringContainsString('page=2', $next);
    }

    public function testSecondPageShowsRestAndLinksKeepFilters(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 933);
        $this->createCounterparties($company, 52, 'Поставщик');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        // When
        $crawler = $client->request('GET', '/counterparties/?q=%D0%BF%D0%BE%D1%81%D1%82&page=2');

        // Then: строки 51 и 52 по алфавиту, ссылка «Назад» несёт поиск
        self::assertResponseIsSuccessful();
        $names = $crawler->filter('.vf-table tbody tr a.vf-strong')->each(static fn ($a): string => trim($a->text()));
        self::assertSame(['Поставщик 051', 'Поставщик 052'], $names);
        $back = $crawler->filter('.pagination a[aria-label="Назад"]')->attr('href');
        self::assertNotNull($back);
        self::assertStringContainsString('q=', $back);
        self::assertStringContainsString('page=1', $back);
    }

    public function testArchivedCountCoversAllPages(): void
    {
        // Given: 3 архивных из 5, по 2 на страницу
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 934);
        $this->createCounterparties($company, 2, 'Активный');
        $this->createCounterparties($company, 3, 'Архивный', archived: true);
        $this->em()->flush();
        $this->login($client, $owner, $company);

        // When
        $crawler = $client->request('GET', '/counterparties/?show_archived=1&limit=2');

        // Then
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.vf-table tbody tr'));
        self::assertSelectorTextContains('.vf-bar', 'Найдено 5 контрагентов, из них в архиве 3');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPagingProvider(): iterable
    {
        yield 'страница 0' => ['page=0'];
        yield 'страница не число' => ['page=abc'];
        yield 'лимит больше 200' => ['limit=201'];
        yield 'лимит 0' => ['limit=0'];
        yield 'страницы нет' => ['page=3'];
    }

    #[DataProvider('invalidPagingProvider')]
    public function testInvalidPagingIsUnprocessable(string $query): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 935);
        $this->createCounterparties($company, 3, 'Поставщик');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        // When
        $client->request('GET', '/counterparties/?'.$query);

        // Then
        self::assertResponseStatusCodeSame(422);
    }

    private function createCounterparties(Company $company, int $count, string $prefix, bool $archived = false): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $builder = CounterpartyBuilder::aCounterparty()
                ->withId(Uuid::uuid7()->toString())
                ->withCompany($company)
                ->withName(sprintf('%s %03d', $prefix, $i))
                ->withInn(null);
            if ($archived) {
                $builder = $builder->asArchived();
            }
            $this->em()->persist($builder->build());
        }
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
    }

    /**
     * @return array{User, Company}
     */
    private function createCompany(KernelBrowser $client, int $index): array
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $this->em()->persist($owner);
        $this->em()->flush();

        /** @var CompanyOwnerMembershipCreator $creator */
        $creator = $client->getContainer()->get(CompanyOwnerMembershipCreator::class);
        $company = $creator->createCompany($owner, 'Company '.$index);
        $this->em()->flush();

        return [$owner, $company];
    }
}
