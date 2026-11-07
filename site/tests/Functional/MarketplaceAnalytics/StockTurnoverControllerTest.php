<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

final class StockTurnoverControllerTest extends WebTestCaseBase
{
    use StockTurnoverFixtures;

    private const URL = '/inventory/stock-turnover';
    private const NBSP = "\u{00A0}";

    public function testPageRendersRowsSortedByValueAndSummary(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-page@example.test', '11111111-1111-1111-1111-111111118701');
        $this->seedStandardSet($company);
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', self::URL.'?date='.$this->date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('table tbody tr'));
        self::assertStringContainsString('Худи серое', $crawler->filter('table tbody tr')->first()->text());
        // Размер — у B, у A размера нет (UNKNOWN не показываем).
        $rows = $crawler->filter('table tbody tr')->each(static fn (Crawler $tr): string => $tr->text());
        self::assertStringContainsString('Футболка чёрная · размер 46', implode(' | ', $rows));
        self::assertStringNotContainsString('UNKNOWN', implode(' | ', $rows));

        $summary = $crawler->filter('.vf-turnover-summary')->text();
        self::assertStringContainsString('772'.self::NBSP.'400,00', $summary);
        self::assertStringContainsString('743'.self::NBSP.'600,00', $summary);
        self::assertStringContainsString('96'.self::NBSP.'%', $summary);
        self::assertStringContainsString('без себестоимости: 1,', $summary);
        self::assertStringContainsString('Остатки: Ozon на '.$this->date->format('d.m.Y'), $crawler->filter('.vf-turnover-stock-meta')->text());

        // Скорость продаж словами (не «корзина»): фильтр, колонка и сводка.
        self::assertSame('Скорость продаж', trim($crawler->filter('label[for="bucket"]')->text()));
        self::assertSame('Скорость продаж', trim($crawler->filter('table thead th')->last()->text()));
        self::assertStringNotContainsString('орзин', $crawler->filter('.page-body')->text());
        self::assertStringContainsString('свыше 90 дней', $crawler->filter('table tbody tr')->first()->filter('.badge')->last()->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParams(): iterable
    {
        yield 'period' => ['period=7'];
        yield 'scheme' => ['scheme=dbs'];
        yield 'bucket' => ['bucket=zzz'];
        yield 'date' => ['date=31-12-2026'];
        yield 'source' => ['source=amazon'];
        yield 'tags' => ['tags[]=not-a-uuid'];
        yield 'period-nonnumeric' => ['period=abc'];
        yield 'page-nonnumeric' => ['page=abc'];
        yield 'tags-scalar' => ['tags=not-a-uuid'];
        yield 'tags-nested' => ['tags[a][]=x'];
        yield 'source-array' => ['source[]=ozon'];
        yield 'date-array' => ['date[]=2026-01-01'];
        yield 'period-array' => ['period[]=60'];
        yield 'date-null-byte' => ['date=2026-11-06%00'];
    }

    #[DataProvider('invalidParams')]
    public function testInvalidParamsFallBackToDefaults(string $query): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-invalid-'.md5($query).'@example.test', '11111111-1111-1111-1111-1111111187'.substr(md5($query), 0, 2));
        $this->seedStandardSet($company);
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', self::URL.'?'.$query);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="period"][value="30"][checked]'));
        self::assertCount(4, $crawler->filter('table tbody tr'));
    }

    public function testBucketFilterKeepsSummaryAndNarrowsRows(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-bucket@example.test', '11111111-1111-1111-1111-111111118702');
        $this->seedStandardSet($company);
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', self::URL.'?bucket=frozen&date='.$this->date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        // A и D — свыше 90 дней; сводка по всем четырём.
        self::assertCount(2, $crawler->filter('table tbody tr'));
        self::assertStringContainsString('772'.self::NBSP.'400,00', $crawler->filter('.vf-turnover-summary')->text());
    }

    public function testOutOfRangePageRedirectsToFirstPageKeepingFilters(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-page-range@example.test', '11111111-1111-1111-1111-111111118706');
        $this->seedStandardSet($company);
        $this->login($client, $owner, $company);

        $client->request('GET', self::URL.'?bucket=frozen&period=60&page=7');

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('bucket=frozen', $location);
        self::assertStringContainsString('period=60', $location);
        self::assertStringNotContainsString('page=', $location);
    }

    public function testFilterWithoutRowsShowsFilterEmptyStateNotStockOne(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-empty-filter@example.test', '11111111-1111-1111-1111-111111118707');
        $this->seedStandardSet($company);
        $this->login($client, $owner, $company);

        // В стандартном наборе нет корзины «60–90 дней».
        $crawler = $client->request('GET', self::URL.'?bucket=slow&date='.$this->date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $tbody = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('Под фильтр ничего не попало', $tbody);
        self::assertStringNotContainsString('Остатков на эту дату нет', $tbody);
    }

    public function testPaginationUsesFiftyRowsAndKeepsFilters(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-pages@example.test', '11111111-1111-1111-1111-111111118703');
        for ($i = 0; $i < 51; ++$i) {
            $listing = $this->listing($company, 8800 + $i, sprintf('SKU-P-%02d', $i), 'Товар '.$i);
            $this->snapshot($company, $listing, '10.000');
            $this->costPrice($company, $listing, '100.00');
        }
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', self::URL.'?period=60&bucket=no_sales&date='.$this->date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('table tbody tr'));
        $links = $crawler->filter('.card-footer nav a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertNotEmpty($links);
        foreach ($links as $href) {
            self::assertStringContainsString('period=60', $href);
            self::assertStringContainsString('bucket=no_sales', $href);
        }
    }

    public function testStaleSourceBannerIsShown(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-stale@example.test', '11111111-1111-1111-1111-111111118704');
        $listing = $this->listing($company, 8901, 'SKU-STALE', 'Старый');
        $this->snapshot($company, $listing, '10.000', $this->date->modify('-5 days'));
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', self::URL.'?date='.$this->date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('остатки Ozon устарели', $crawler->filter('.vf-turnover-stock-meta')->text());
        self::assertStringContainsString($this->date->modify('-5 days')->format('d.m.Y'), $crawler->filter('.vf-turnover-stock-meta')->text());
        self::assertStringContainsString('Остатков на эту дату нет', $crawler->filter('table tbody')->text());
    }

    public function testTabFollowsStocksInInventoryTabsAndIsGoneFromAnalytics(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-tab@example.test', '11111111-1111-1111-1111-111111118705');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', '/inventory/stocks');
        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('.card-header-tabs a')->each(static fn (Crawler $a): string => trim($a->text()));
        self::assertSame(['Листинг', 'Остатки', 'Оборачиваемость', 'Себестоимость', 'Теги'], $labels);
        self::assertCount(1, $crawler->filter('.card-header-tabs a[href="'.self::URL.'"]'));

        $crawler = $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSame('Оборачиваемость', trim($crawler->filter('.card-header-tabs .active')->text()));

        $crawler = $client->request('GET', '/marketplace-analytics/snapshots');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="'.self::URL.'"]'));
        self::assertStringNotContainsString('Оборачиваемость', $crawler->filter('.page-header')->text());
    }
}
