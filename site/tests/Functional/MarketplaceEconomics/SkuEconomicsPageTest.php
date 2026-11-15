<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceEconomics;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class SkuEconomicsPageTest extends WebTestCaseBase
{
    private const URL = '/marketplace/economics';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000991';

    public function testShowsSkuLevelsUnallocatedAndCabinetTotal(): void
    {
        $client = static::createClient();
        [$owner, $listingId] = $this->seed();
        $this->line('2026-11-10', $listingId, 'sale', 'revenue', 300000, 3);
        $this->line('2026-11-10', $listingId, 'cost_price', 'cost_price', -90000, 3);
        $this->line('2026-11-10', $listingId, 'commission', 'md1', -45000);
        $this->line('2026-11-10', $listingId, 'ads_allocated', 'md2', -15000);
        $this->line('2026-11-10', $listingId, 'overhead_share', 'overhead', -5000);
        $this->line('2026-11-10', null, 'subscription', 'overhead', -8000);
        $this->line('2026-11-10', null, 'overhead_share', 'overhead', 5000);
        // прошлый период (01.11–09.11 для 10.11–18.11): СМ2 SKU = 1 000 ₽
        $this->line('2026-11-05', $listingId, 'sale', 'revenue', 100000, 1);
        $this->day('2026-11-10');

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $crawler = $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-economics-table] .table.table-vcenter.card-table');
        self::assertSelectorExists('[data-economics-table] [data-economics-scroll][tabindex="0"]');
        self::assertSelectorExists('script[src*="marketplace_economics"]');
        $row = $crawler->filter('tbody tr')->first()->text();
        self::assertStringContainsString('SKU-991', $row);
        // СМ1 = 3000 − 900 − 450 = 1650; СМ2 = 1500; СМ3 = 1450
        self::assertMatchesRegularExpression('/1\s650,00/u', $row);
        self::assertMatchesRegularExpression('/1\s450,00/u', $row);
        $totals = $crawler->filter('tr.me-totals');
        self::assertSame(['Нераспределено', 'Итого'], $totals->each(static fn ($r): string => $r->filter('td')->first()->text()));
        // итог кабинета: 1450 − 30 нераспределённой подписки
        self::assertMatchesRegularExpression('/1\s420,00/u', $totals->last()->text());

        $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18', 'alloc' => '0']);
        self::assertStringNotContainsString('СМ3', (string) $client->getResponse()->getContent());

        $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18', 'format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testRejectsTooLongPeriodAndShowsEmptyStateWithoutData(): void
    {
        $client = static::createClient();
        [$owner] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', self::URL, ['from' => '2025-01-01', 'to' => '2026-01-02']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('не больше 366 дней', (string) $client->getResponse()->getContent());

        $client->request('GET', self::URL, ['from' => '2026-11-01', 'to' => '2026-11-10']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ещё не переведены на новый контур', (string) $client->getResponse()->getContent());
    }

    public function testLegacyShellPreservesFiltersAndTabQuery(): void
    {
        $client = static::createClient();
        [$owner] = $this->seed();
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId(self::COMPANY_ID)->build();
        $this->em()->persist($tag);
        $this->em()->flush();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $this->day('2026-11-10');
        $this->day('2026-11-11', 'cabinet-2');
        $params = ['connection' => 'cabinet-2', 'from' => '2026-11-10', 'to' => '2026-11-18', 'q' => 'SKU-991', 'tags' => [$tag->getId()], 'alloc' => '0', 'sort' => 'revenue', 'dir' => 'asc', 'limit' => '1'];
        $crawler = $client->request('GET', self::URL, $params);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorTextContains('.vf-page-head h1', 'Экономика SKU');
        self::assertSelectorTextContains('nav[aria-label="Отчёт"] .nav-link.active[aria-current="page"]', 'По SKU');
        self::assertSelectorExists('form[method="get"] .form-control[name="from"][value="2026-11-10"]');
        self::assertSelectorExists('input[name="q"][value="SKU-991"]');
        self::assertSelectorExists('select[name="connection"] option[value="cabinet-2"][selected]');
        self::assertSelectorExists('select[name="alloc"] option[value="0"][selected]');
        self::assertSelectorExists('select[name="tags[]"] option[selected]');
        self::assertSelectorExists('button.btn-primary');
        $href = $crawler->filter('nav[aria-label="Отчёт"] a')->eq(1)->attr('href');
        parse_str((string) parse_url((string) $href, \PHP_URL_QUERY), $query);
        self::assertSame(array_diff_key($params, array_flip(['sort', 'dir', 'limit'])), $query);
        self::assertSame('/marketplace/economics/quality', $crawler->filter('nav[aria-label="Отчёт"] a')->last()->attr('href'));
    }

    public function testSortingPaginationAndCompanyIsolation(): void
    {
        $client = static::createClient();
        [$owner, $listingId] = $this->seed();
        $this->line('2026-11-10', $listingId, 'sale', 'revenue', 100000, 1);
        $this->day('2026-11-10');
        $company = $this->em()->getReference(Company::class, self::COMPANY_ID);
        self::assertInstanceOf(Company::class, $company);
        $second = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplaceSku('SKU-SECOND')->withName(str_repeat('Длинное название ', 12))->build();
        $this->em()->persist($second);
        $this->em()->flush();
        $this->line('2026-11-10', (string) $second->getId(), 'sale', 'revenue', 200000, 2);
        $db = static::getContainer()->get(Connection::class);
        $db->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => Uuid::uuid7()->toString(), 'channel' => 'ozon', 'connection_ref' => 'foreign-cabinet',
            'operation_date' => '2026-11-10', 'listing_id' => Uuid::uuid7()->toString(), 'measure' => 'sale', 'level' => 'revenue', 'amount_minor' => 99999999, 'quantity' => 999,
        ]);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $params = ['from' => '2026-11-10', 'to' => '2026-11-18', 'sort' => 'revenue', 'dir' => 'asc', 'limit' => '1', 'page' => '2'];
        $crawler = $client->request('GET', self::URL, $params);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SKU-SECOND', $crawler->filter('tbody tr')->first()->text());
        self::assertMatchesRegularExpression('/3\s000,00/u', $crawler->filter('tr.me-totals')->last()->text());
        self::assertSelectorExists('.pagination .page-item.active [aria-current="page"]');
        self::assertSelectorTextContains('.vf-pager-caption', 'из 2');
        $href = $crawler->filter('a[aria-label="Назад"]')->attr('href');
        parse_str((string) parse_url((string) $href, \PHP_URL_QUERY), $query);
        self::assertSame(array_replace($params, ['page' => '1']), $query);
        $sortLink = $crawler->selectLink('Выручка, ₽')->link();
        $crawler = $client->click($sortLink);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SKU-SECOND', $crawler->filter('tbody tr')->first()->text(), 'desc, первая страница');
        self::assertStringNotContainsString('foreign-cabinet', $crawler->filter('[data-economics-table]')->text());
    }

    /** @return array{0: User, 1: string} */
    private function seed(): array
    {
        $owner = UserBuilder::aUser()->withEmail('me-sku@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('SKU-991')->withName('Платье')->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($listing);
        $em->flush();

        return [$owner, (string) $listing->getId()];
    }

    private function line(string $date, ?string $listingId, string $measure, string $level, int $amount, ?int $qty = null): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => self::COMPANY_ID, 'channel' => 'ozon', 'connection_ref' => 'cabinet-1',
            'operation_date' => $date, 'listing_id' => $listingId, 'measure' => $measure, 'level' => $level, 'amount_minor' => $amount, 'quantity' => $qty,
        ]);
    }

    private function day(string $date, string $connection = 'cabinet-1'): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day', [
            'company_id' => self::COMPANY_ID, 'channel' => 'ozon', 'connection_ref' => $connection, 'operation_date' => $date, 'rules_version' => 'economics-1',
            'canon_minor' => 0, 'unallocated_minor' => 0, 'cost_price_minor' => 0, 'without_cost_price' => 0, 'line_count' => 0, 'currency' => 'RUB', 'refreshed_at' => '2026-11-14 10:00:00',
        ]);
    }
}
