<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceEconomics;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyTaxProfileBuilder;
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
        // двухуровневая шапка: группы сверху, под ними короткие подписи с полным названием в title
        $headers = $crawler->filter('[data-economics-table] thead th:not(.me-col-group)');
        self::assertSame('SKU', $headers->eq(0)->text());
        self::assertSame('Наименование', $headers->eq(1)->text());
        self::assertSame('Оборот', $headers->eq(2)->text());
        self::assertSame('СМ3', $headers->eq(3)->text());
        self::assertSame('Прод., шт', $headers->eq(4)->text());
        self::assertSame('Продано, шт.', $headers->eq(4)->attr('title'));
        $groups = $crawler->filter('[data-economics-table] thead th.me-col-group');
        self::assertSame(['ABC', 'Продажи', 'Выручка', 'Товар', 'Расходы МП', 'СМ1', 'Реклама', 'СМ2', 'Склад и пр. МП', 'СМ3', 'На единицу'], $groups->each(static fn ($th): string => $th->text()));
        self::assertSame('5', $groups->eq(2)->attr('colspan'));
        self::assertSame('revenue revenueWithSpp spp vat revenueNet', $groups->eq(2)->attr('data-columns'));
        $gross = $crawler->filter('[data-economics-table] thead th[data-column="gross"]');
        self::assertSame('Выр. − себест.', $gross->text());
        self::assertSame('Выручка без НДС − себестоимость, ₽', $gross->attr('title'));
        self::assertStringNotContainsString(', ₽', $crawler->filter('[data-economics-table] thead')->text());
        self::assertStringContainsString('Суммы в ₽', (string) $client->getResponse()->getContent());
        $product = $crawler->filter('[data-economics-table] tbody tr')->first();
        self::assertSame('SKU-991', $product->filter('td')->eq(0)->text());
        self::assertSame('Платье', $product->filter('td')->eq(1)->text());
        self::assertCount($headers->count(), $product->filter('td'));
        self::assertSame('A', $product->filter('td')->eq(2)->text());
        self::assertSame('A', $product->filter('td')->eq(3)->text());
        $row = $product->text();
        // СМ1 = 3000 − 900 − 450 = 1650; СМ2 = 1500; СМ3 = 1450
        self::assertMatchesRegularExpression('/1\s650,00/u', $row);
        self::assertMatchesRegularExpression('/1\s450,00/u', $row);
        $totals = $crawler->filter('tr.me-totals');
        self::assertSame(['Нераспределено', 'Итого'], $totals->each(static fn ($r): string => $r->filter('td')->first()->text()));
        foreach ($totals->each(static fn ($total) => $total->filter('td')) as $cells) {
            self::assertSame('2', $cells->first()->attr('colspan'));
            self::assertSame($headers->count(), $cells->count() + 1);
        }
        // итог кабинета: 1450 − 30 нераспределённой подписки
        self::assertMatchesRegularExpression('/1\s420,00/u', $totals->last()->text());
        // без сравнения с прошлым периодом: в ячейке прибыли одна сумма, стандартная ширина
        self::assertCount(0, $crawler->filter('[data-economics-table] tbody td[data-column] p'));
        self::assertStringNotContainsString('Сравнение с', (string) $client->getResponse()->getContent());

        $withoutAllocation = $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18', 'alloc' => '0']);
        self::assertStringNotContainsString('СМ3', (string) $client->getResponse()->getContent());
        self::assertCount(0, $withoutAllocation->filter('[data-economics-table] tbody td[data-column="md2"] p'));

        $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18', 'format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testVatFromRevenueWithSppByTaxProfileOfEachMonthAndColumnPicker(): void
    {
        $client = static::createClient();
        [$owner, $listingId] = $this->seed();
        $em = $this->em();
        $em->persist(CompanyTaxProfileBuilder::aProfile()->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::EXEMPT)->validFrom(null)->build());
        $em->persist(CompanyTaxProfileBuilder::aProfile()->withId('01930000-0000-7000-8000-00000000a002')->forCompany(self::COMPANY_ID)->with(TaxRegime::USN, VatMode::USN_5)->validFrom('2026-11-01')->build());
        $em->flush();
        // октябрь без НДС, ноябрь — 5 % с выручки с СПП: (2 400 − 300) × 5 / 105 = 100
        $this->line('2026-10-31', $listingId, 'sale', 'revenue', 100000, 1);
        $this->line('2026-11-01', $listingId, 'sale', 'revenue', 240000, 2);
        $this->line('2026-11-01', $listingId, 'spp', 'info', 30000);
        $this->line('2026-11-01', $listingId, 'ads_settlement', 'info', -50000);
        $this->day('2026-10-31');
        $this->day('2026-11-01');

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $crawler = $client->request('GET', self::URL, ['from' => '2026-10-31', 'to' => '2026-11-01']);

        self::assertResponseIsSuccessful();
        $titles = $crawler->filter('[data-economics-table] thead th[data-column]')->each(static fn ($th): string => (string) $th->attr('title'));
        foreach (['Выручка с СПП, ₽', 'НДС к уплате, ₽', 'Выручка без НДС, ₽', 'СМ1, %', 'СМ2, %', 'СМ3, %', 'СПП, ₽'] as $label) {
            self::assertContains($label, $titles);
        }
        self::assertStringNotContainsString('Маржинальность', implode(' ', $titles));
        $row = $crawler->filter('[data-economics-table] tbody tr')->first();
        $cell = static fn (string $id): string => $row->filter(sprintf('td[data-column="%s"]', $id))->text();
        self::assertSame('3 100,00', str_replace("\u{a0}", ' ', $cell('revenueWithSpp')));
        self::assertSame('-100,00', str_replace(["\u{a0}", '−'], [' ', '-'], $cell('vat')));
        self::assertSame('3 300,00', str_replace("\u{a0}", ' ', $cell('revenueNet')));
        self::assertSame('300,00', $cell('spp'), 'удержание за рекламу — не СПП');
        // СМ1 = 3 300 без себестоимости и затрат; СМ1 % = 3 300 / 3 400
        self::assertSame('97,1 %', str_replace("\u{a0}", ' ', $cell('md1Pct')));

        // окно колонок: обязательные заблокированы, остальные включены
        $picker = $crawler->filter('[data-economics-table] [data-economics-columns]');
        self::assertCount(1, $picker, 'настройка колонок — в углу таблицы, не в фильтрах');
        self::assertCount(0, $crawler->filter('form [data-economics-columns]'));
        $toggle = $picker->filter('summary');
        self::assertSame('Настроить колонки', $toggle->attr('title'));
        self::assertCount(1, $toggle->filter('i.ti-settings[aria-hidden="true"]'));
        self::assertSame('Настроить колонки', trim($toggle->text()), 'подпись для экранных читалок');
        self::assertCount(1, $picker->filter('input[value="revenue"][checked][disabled]'));
        self::assertCount(1, $picker->filter('input[value="vat"][checked]:not([disabled])'));
        self::assertSame('vat', $crawler->filter('[data-economics-table] thead th[data-column="vat"]')->attr('data-column'));
    }

    public function testWithoutAllocationCostsWithoutSkuStayInUnallocatedRowAndNoteExplainsMode(): void
    {
        $client = static::createClient();
        [$owner, $listingId] = $this->seed();
        $this->line('2026-11-10', $listingId, 'sale', 'revenue', 300000, 3);
        // drop-off без SKU 10 ₽ и его разнесение парой строк (доля на SKU + обратная без SKU)
        $this->line('2026-11-10', null, 'logistics_to_dropoff', 'md1', -1000);
        $this->line('2026-11-10', $listingId, 'logistics_to_dropoff', 'md1', -1000, null, 'sales_qty_share');
        $this->line('2026-11-10', null, 'logistics_to_dropoff', 'md1', 1000, null, 'sales_qty_share');
        $this->line('2026-11-10', null, 'subscription', 'overhead', -800);
        $this->line('2026-11-10', $listingId, 'overhead_share', 'overhead', -800, null, 'revenue_share');
        $this->line('2026-11-10', null, 'overhead_share', 'overhead', 800, null, 'revenue_share');
        $this->day('2026-11-10');
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $money = static fn ($crawler, string $row, string $column): string => str_replace(["\u{a0}", '−'], [' ', '-'], $crawler->filter($row)->filter(sprintf('td[data-column="%s"]', $column))->text());
        $period = ['from' => '2026-11-10', 'to' => '2026-11-10'];

        $crawler = $client->request('GET', self::URL, $period);
        self::assertSame('-10,00', $money($crawler, 'tbody tr:first-child', 'logisticsTo'), 'разнесено на SKU');
        self::assertSame('0,00', $money($crawler, 'tr.me-totals:nth-last-child(2)', 'logisticsTo'));
        self::assertSame('-10,00', $money($crawler, 'tr.me-totals:last-child', 'logisticsTo'));
        self::assertStringContainsString('по проданным штукам', $crawler->filter('[data-economics-note]')->text());

        $crawler = $client->request('GET', self::URL, $period + ['alloc' => '0']);
        self::assertSame('0,00', $money($crawler, 'tbody tr:first-child', 'logisticsTo'), 'без разнесения у SKU только свои затраты');
        self::assertSame('-10,00', $money($crawler, 'tr.me-totals:nth-last-child(2)', 'logisticsTo'), 'затрата без SKU — в «Нераспределено»');
        self::assertSame('-10,00', $money($crawler, 'tr.me-totals:last-child', 'logisticsTo'), 'итог кабинета тот же');
        // СМ2 «Нераспределено» = −10 drop-off; подписка (общие) в этом режиме не показывается колонкой
        self::assertMatchesRegularExpression('/-10,00/', $money($crawler, 'tr.me-totals:nth-last-child(2)', 'md2'));
        self::assertStringContainsString('только свои затраты', $crawler->filter('[data-economics-note]')->text());
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
        $table = $crawler->filter('[data-economics-table] table');
        $empty = $table->filter('tbody tr')->first()->filter('td');
        self::assertCount(1, $empty);
        self::assertSame('Нет продаж и затрат по SKU за период.', $empty->text());
        self::assertSame('Наименование', $table->filter('thead th:not(.me-col-group)')->eq(1)->text());
        self::assertSame((string) $table->filter('thead th:not(.me-col-group)')->count(), $empty->attr('colspan'));
        self::assertSame('2', $table->filter('tr.me-totals td')->first()->attr('colspan'));
        $href = $crawler->filter('nav[aria-label="Отчёт"] a')->eq(1)->attr('href');
        parse_str((string) parse_url((string) $href, \PHP_URL_QUERY), $query);
        self::assertSame(array_diff_key($params, array_flip(['sort', 'dir', 'limit'])), $query);
        self::assertSame('/marketplace/economics/quality', $crawler->filter('nav[aria-label="Отчёт"] a')->last()->attr('href'));
    }

    public function testPeriodPresetsAndRangePickerKeepOtherFilters(): void
    {
        $client = static::createClient();
        [$owner] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
        $this->day('2026-11-10');

        $crawler = $client->request('GET', self::URL, ['from' => '2026-11-10', 'to' => '2026-11-18', 'q' => 'SKU-991', 'page' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.vf-seg a.active');
        // Соседние .card-body в Tabler разделены border-top; у формы .row он съезжал на пресеты полосой.
        self::assertSelectorNotExists('.card-body + .card-body');
        self::assertSelectorExists('.card-body > form[data-economics-filters].row');
        self::assertSelectorTextContains('.vf-ops-range.active', '10.11.2026 — 18.11.2026');
        self::assertSelectorExists('.vf-period-pop form input[type="date"][name="from"][value="2026-11-10"]');
        self::assertSelectorExists('.vf-period-pop form input[type="date"][name="to"][value="2026-11-18"]');
        self::assertSelectorExists('.vf-period-pop form input[type="hidden"][name="q"][value="SKU-991"]');
        self::assertSelectorExists('[data-economics-filters] input[type="hidden"][name="from"][value="2026-11-10"]');
        self::assertSelectorExists('[data-economics-filters] input[type="hidden"][name="to"][value="2026-11-18"]');

        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $href = (string) $crawler->filter('.vf-seg a')->first()->attr('href');
        self::assertSame('Вчера', $crawler->filter('.vf-seg a')->first()->text());
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame(['q' => 'SKU-991', 'from' => $yesterday, 'to' => $yesterday], $query);

        $client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vf-seg a.active[aria-current="true"]', 'Вчера');
        self::assertSelectorNotExists('.vf-ops-range.active');
    }

    public function testSortingPaginationAndCompanyIsolation(): void
    {
        $client = static::createClient();
        [$owner, $listingId] = $this->seed();
        $this->line('2026-11-10', $listingId, 'sale', 'revenue', 100000, 1);
        $this->day('2026-11-10');
        $company = $this->em()->getReference(Company::class, self::COMPANY_ID);
        self::assertInstanceOf(Company::class, $company);
        $sellerArticle = str_repeat('Артикул продавца ', 6).'2';
        $second = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplaceSku('SKU-SECOND')->withName('')->withSupplierSku($sellerArticle)->build();
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
        $productCells = $crawler->filter('tbody tr')->first()->filter('td');
        self::assertSame('SKU-SECOND', $productCells->eq(0)->text());
        self::assertSame($sellerArticle, $productCells->eq(1)->text());
        self::assertSame($sellerArticle, $productCells->eq(1)->filter('[title]')->attr('title'));
        self::assertMatchesRegularExpression('/3\s000,00/u', $crawler->filter('tr.me-totals')->last()->text());
        self::assertSelectorExists('.pagination .page-item.active [aria-current="page"]');
        self::assertSelectorTextContains('.vf-pager-caption', 'из 2');
        $href = $crawler->filter('a[aria-label="Назад"]')->attr('href');
        parse_str((string) parse_url((string) $href, \PHP_URL_QUERY), $query);
        self::assertSame(array_replace($params, ['page' => '1']), $query);
        $sortLink = $crawler->filter('thead th[data-column="revenue"] a')->link();
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

    private function line(string $date, ?string $listingId, string $measure, string $level, int $amount, ?int $qty = null, ?string $allocationKey = null): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => self::COMPANY_ID, 'channel' => 'ozon', 'connection_ref' => 'cabinet-1',
            'operation_date' => $date, 'listing_id' => $listingId, 'measure' => $measure, 'level' => $level, 'amount_minor' => $amount, 'quantity' => $qty,
            'allocation_key' => $allocationKey,
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
