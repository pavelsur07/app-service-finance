<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceEconomics;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
use App\MarketplaceEconomics\Application\Service\SkuEconomicsReportBuilder;
use App\MarketplaceEconomics\Infrastructure\Export\SkuEconomicsXlsxExporter;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use OpenSpout\Reader\XLSX\Reader;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Режим «По категориям»: Ozon и WB одной категории — одна строка, проценты из сумм,
 * Σ категорий + «Без категории» + «Нераспределено» = итог кабинета.
 *
 * Данные: «Платья» — Ozon (выручка 1 000, комиссия 100) и WB (2 000, 300);
 * без категории — 500; затрата кабинета без товара — 50.
 */
final class SkuEconomicsByCategoryTest extends WebTestCaseBase
{
    private const URL = '/marketplace/economics';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000c01';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000c02';
    private const PERIOD = ['from' => '2026-11-10', 'to' => '2026-11-11'];

    public function testGroupsByCategoryAndTotalsMatchSkuMode(): void
    {
        $client = static::createClient();
        [$owner, $dressesId] = $this->seed();
        $this->login($client, $owner);

        $bySku = $client->request('GET', self::URL, self::PERIOD);
        self::assertResponseIsSuccessful();
        $skuTotal = $this->totalsRow($bySku, 'Итого');

        $crawler = $client->request('GET', self::URL, self::PERIOD + ['group' => 'category']);
        self::assertResponseIsSuccessful();

        $groups = $crawler->filter('[data-economics-table] tbody tr[data-category-group]');
        self::assertSame(['Платья', 'Без категории'], $groups->each(static fn (Crawler $r): string => trim($r->filter('td')->eq(0)->filter('a')->text())));
        self::assertSame('3 000,00', $this->cell($groups->eq(0), 'revenue'));
        self::assertSame('500,00', $this->cell($groups->eq(1), 'revenue'));
        // СМ1 % группы = (3 000 − 400) / 3 000, а не среднее 90 % и 85 %
        self::assertSame('86,7 %', $this->cell($groups->eq(0), 'md1Pct'));
        self::assertStringContainsString(
            'category='.$dressesId,
            (string) $groups->eq(0)->filter('a')->attr('href'),
        );

        self::assertSame('-50,00', $this->totalsRow($crawler, 'Нераспределено')['full']);
        self::assertSame($skuTotal, $this->totalsRow($crawler, 'Итого'));
        self::assertSame('3 050,00', $skuTotal['full']);
        self::assertStringContainsString('Доля выручки с категорией: 85,7 %', $this->text($crawler->filter('[data-economics-note]')));
    }

    public function testCategoryFilterNarrowsRowsAndTotal(): void
    {
        $client = static::createClient();
        [$owner, $dressesId] = $this->seed();
        $this->login($client, $owner);

        $crawler = $client->request('GET', self::URL, self::PERIOD + ['category' => $dressesId]);
        self::assertCount(2, $crawler->filter('[data-economics-table] tbody tr:not(.me-totals)'));
        self::assertSame('3 000,00', $this->totalsRow($crawler, 'Итого по выбранным')['revenue']);

        $crawler = $client->request('GET', self::URL, self::PERIOD + ['category' => 'none', 'group' => 'category']);
        self::assertSame(['Без категории'], $crawler->filter('tr[data-category-group]')->each(static fn (Crawler $r): string => trim($r->filter('td')->eq(0)->filter('a')->text())));
        self::assertSame('500,00', $this->totalsRow($crawler, 'Итого по выбранным')['revenue']);
    }

    public function testAbcClassesUseSelectedListingsBeforeSearchAndOnlyAllocatedFullProfit(): void
    {
        static::createClient();
        [, , $ozon, $wb] = $this->seed();
        $this->line('ozon', $ozon, 'return', 'revenue', -100000, 1);
        $this->line('wildberries', $wb, 'overhead_share', 'overhead', -170000);
        $this->line('ozon', null, 'sale', 'revenue', 900000, 1);
        $builder = static::getContainer()->get(SkuEconomicsReportBuilder::class);
        $from = new \DateTimeImmutable(self::PERIOD['from']);
        $to = new \DateTimeImmutable(self::PERIOD['to']);

        $report = $builder->build(self::COMPANY_ID, new SkuEconomicsFilter($from, $to));
        $classes = [];
        foreach ($report->rows as $row) {
            $classes[$row->sku] = [$row->abcRevenue->value, $row->abcProfit?->value];
        }
        self::assertSame(['A', 'D'], $classes['WB-1']);
        self::assertSame(['B', 'A'], $classes['OZ-2']);
        self::assertSame(['C', 'D'], $classes['OZ-1']);

        $search = $builder->build(self::COMPANY_ID, new SkuEconomicsFilter($from, $to, search: 'OZ-2', allocated: false));
        self::assertCount(1, $search->rows);
        self::assertSame('B', $search->rows[0]->abcRevenue->value);
        self::assertNull($search->rows[0]->abcProfit);

        $category = $builder->build(self::COMPANY_ID, new SkuEconomicsFilter($from, $to, category: 'none'));
        self::assertSame('A', $category->rows[0]->abcRevenue->value);
    }

    public function testXlsxByCategoryHasCategoryColumnAndSameTotal(): void
    {
        $client = static::createClient();
        [$owner, $category, $ozonId, $wbId] = $this->seed();
        $this->line('ozon', $ozonId, 'return', 'revenue', -100000, 1);
        $this->line('ozon', $ozonId, 'logistics_back', 'md1', -900);
        $this->line('wildberries', $wbId, 'cost_price', 'cost_price', -12000, 2);
        $this->line('wildberries', $wbId, 'logistics_to', 'md1', -2100);
        $this->line('wildberries', $wbId, 'ads_cpc', 'md2', -1800);
        $this->login($client, $owner);

        $columns = ['costPricePerUnit', 'logisticsPerUnit', 'cacPerUnit', 'averageCheck'];
        $byCategory = $client->request('GET', self::URL, self::PERIOD + ['group' => 'category']);
        self::assertSame('—', $this->cell($byCategory->filter('tr[data-category-group]')->first(), 'abcRevenue'));
        $group = $byCategory->filter(sprintf('tr[data-category-group="%s"]', $category));
        self::assertSame(['60,00', '15,00', '9,00', '1 000,00'], array_map(fn (string $col): string => $this->cell($group, $col), $columns));

        self::assertSame('833,33', $this->totalsRow($byCategory, 'Итого')['averageCheck']);

        $bySku = $client->request('GET', self::URL, self::PERIOD + ['alloc' => '0']);
        $header = $bySku->filter('th[data-column="cacPerUnit"] + th[data-column="averageCheck"]');
        self::assertSame('Ср. чек', $header->text());
        $returned = $bySku->filter('tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'OZ-1'));
        self::assertSame('C', $this->cell($returned, 'abcRevenue'));
        self::assertCount(0, $bySku->filter('[data-column="abcProfit"]'));
        self::assertSame(['—', '—', '—', '—'], array_map(fn (string $col): string => $this->cell($returned, $col), $columns));

        $client->request('GET', self::URL, self::PERIOD + ['group' => 'category', 'format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        self::assertInstanceOf(BinaryFileResponse::class, $client->getResponse());

        // Файл ответа удаляется после отправки — тот же отчёт пишем экспортёром напрямую.
        $container = static::getContainer();
        $report = $container->get(SkuEconomicsReportBuilder::class)->build(self::COMPANY_ID, new SkuEconomicsFilter(
            new \DateTimeImmutable(self::PERIOD['from']), new \DateTimeImmutable(self::PERIOD['to']), byCategory: true,
        ));
        $path = $container->get(SkuEconomicsXlsxExporter::class)->writeFile($report, true);

        $rows = $this->readXlsx($path);

        self::assertSame('Категория', $rows[1][0]);
        self::assertSame(['SKU', 'Название', 'ABC оборот', 'ABC СМ3', 'Канал'], \array_slice($rows[1], 1, 5));
        $body = \array_slice($rows, 2);
        self::assertSame(['Платья', 'Платья', 'Без категории', 'Нераспределено', 'Итого'], array_column($body, 0));
        $revenue = array_search('Выручка', $rows[1], true);
        self::assertIsInt($revenue);
        $last = array_pop($body);
        self::assertNotNull($last);
        self::assertEqualsWithDelta(2500.0, $last[$revenue] ?? null, 0.001);
        $returnedRows = array_values(array_filter($body, static fn (array $row): bool => 'OZ-1' === $row[1]));
        self::assertCount(1, $returnedRows);
        self::assertSame(['C', 'D'], \array_slice($returnedRows[0], 3, 2));
        self::assertSame(['', ''], \array_slice($last, 3, 2));
        self::assertSame(['CAC/ед., ₽', 'Ср. чек, ₽'], \array_slice($rows[1], -2));
        foreach (['Себест./ед., ₽' => 40, 'Логист./ед., ₽' => 10, 'CAC/ед., ₽' => 6, 'Ср. чек, ₽' => 833.33] as $header => $expected) {
            $index = array_search($header, $rows[1], true);
            self::assertIsInt($index);
            self::assertSame($expected, $last[$index]);
            self::assertSame('', $returnedRows[0][$index]);
        }
    }

    public function testXlsxWithoutAllocationShowsOnlyRevenueClassAfterTitle(): void
    {
        static::createClient();
        $this->seed();
        $container = static::getContainer();
        $report = $container->get(SkuEconomicsReportBuilder::class)->build(self::COMPANY_ID, new SkuEconomicsFilter(
            new \DateTimeImmutable(self::PERIOD['from']), new \DateTimeImmutable(self::PERIOD['to']), search: 'OZ-2', allocated: false,
        ));
        $rows = $this->readXlsx($container->get(SkuEconomicsXlsxExporter::class)->writeFile($report, false));

        self::assertSame(['SKU', 'Название', 'ABC оборот', 'Канал'], \array_slice($rows[1], 0, 4));
        self::assertNotContains('ABC СМ3', $rows[1]);
        self::assertSame(['OZ-2', 'Носки', 'B', 'Ozon'], \array_slice($rows[2], 0, 4));
        self::assertSame('Итого', $rows[3][0]);
        self::assertSame('', $rows[3][2]);
        self::assertSame(['Ср. чек, ₽'], \array_slice($rows[1], -1));
        self::assertSame([500], \array_slice($rows[2], -1));
    }

    /** @return list<array<int, mixed>> */
    private function readXlsx(string $path): array
    {
        $rows = [];
        $reader = new Reader();
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    /**
     * @return array{User, string, string, string}
     */
    private function seed(): array
    {
        $owner = UserBuilder::aUser()->withEmail('me-category@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $foreignOwner = UserBuilder::aUser()->withIndex(905)->withEmail('me-category-foreign@example.test')->build();
        $foreign = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($foreignOwner)->build();
        $dresses = MarketplaceListingCategoryBuilder::aCategory()->forCompanyId(self::COMPANY_ID)->withName('Платья')->build();
        $foreignCategory = MarketplaceListingCategoryBuilder::aCategory()->forCompanyId(self::OTHER_COMPANY_ID)->withName('Чужая')->build();
        $ozon = $this->listing($company, MarketplaceType::OZON, 'OZ-1', 'Платье Ozon');
        $wb = $this->listing($company, MarketplaceType::WILDBERRIES, 'WB-1', 'Платье WB');
        $plain = $this->listing($company, MarketplaceType::OZON, 'OZ-2', 'Носки');

        $em = $this->em();
        foreach ([$owner, $company, $foreignOwner, $foreign, $dresses, $foreignCategory, $ozon, $wb, $plain] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "UPDATE marketplace_listings SET category_id = ?, category_source = 'manual' WHERE id IN (?, ?)",
            [$dresses->getId(), $ozon->getId(), $wb->getId()],
        );

        $this->line('ozon', $ozon->getId(), 'sale', 'revenue', 100000, 1);
        $this->line('ozon', $ozon->getId(), 'commission', 'md1', -10000);
        $this->line('wildberries', $wb->getId(), 'sale', 'revenue', 200000, 2);
        $this->line('wildberries', $wb->getId(), 'commission', 'md1', -30000);
        $this->line('ozon', $plain->getId(), 'sale', 'revenue', 50000, 1);
        $this->line('ozon', null, 'subscription', 'overhead', -5000);
        $this->day('ozon');
        $this->day('wildberries');

        return [$owner, $dresses->getId(), $ozon->getId(), $wb->getId()];
    }

    private function listing(Company $company, MarketplaceType $type, string $sku, string $name): MarketplaceListing
    {
        return MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace($type)->withMarketplaceSku($sku)->withName($name)->build();
    }

    private function line(string $channel, ?string $listingId, string $measure, string $level, int $amount, ?int $qty = null): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day_line', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => self::COMPANY_ID, 'channel' => $channel, 'connection_ref' => 'cabinet-'.$channel,
            'operation_date' => '2026-11-10', 'listing_id' => $listingId, 'measure' => $measure, 'level' => $level, 'amount_minor' => $amount, 'quantity' => $qty,
            'allocation_key' => null,
        ]);
    }

    private function day(string $channel): void
    {
        static::getContainer()->get(Connection::class)->insert('me_day', [
            'company_id' => self::COMPANY_ID, 'channel' => $channel, 'connection_ref' => 'cabinet-'.$channel, 'operation_date' => '2026-11-10', 'rules_version' => 'economics-1',
            'canon_minor' => 0, 'unallocated_minor' => 0, 'cost_price_minor' => 0, 'without_cost_price' => 0, 'line_count' => 0, 'currency' => 'RUB', 'refreshed_at' => '2026-11-14 10:00:00',
        ]);
    }

    private function login(KernelBrowser $client, User $owner): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
    }

    /**
     * @return array<string, string> column id → значение строки итогов
     */
    private function totalsRow(Crawler $crawler, string $label): array
    {
        $row = $crawler->filter('tr.me-totals')->reduce(static fn (Crawler $r): bool => trim($r->filter('td')->first()->text()) === $label);
        self::assertCount(1, $row, $label);
        $values = [];
        $row->filter('td[data-column]')->each(function (Crawler $td) use (&$values): void {
            $values[(string) $td->attr('data-column')] = $this->text($td);
        });

        return $values;
    }

    private function cell(Crawler $row, string $column): string
    {
        return $this->text($row->filter(sprintf('td[data-column="%s"]', $column)));
    }

    private function text(Crawler $node): string
    {
        return trim(str_replace(["\u{a0}", "\u{202f}", '−'], [' ', ' ', '-'], $node->text()));
    }
}
