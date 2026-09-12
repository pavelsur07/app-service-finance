<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Inventory\Entity\Location;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Query\InventoryStockReportQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\LocationBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class StockReportControllerTest extends WebTestCaseBase
{
    private ?\DateTimeImmutable $today = null;

    public function testDefaultDateShowsLatestAvailableSnapshotDay(): void
    {
        $client = $this->seedTwoOzonDays('stocks-default@example.test', '11111111-1111-1111-1111-111111112001', '01');

        $client->request('GET', '/inventory/stocks');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SKU-LATEST', $html);
        self::assertStringNotContainsString('SKU-OLD', $html);
    }

    public function testDateWithoutSnapshotFallsBackToNearestPreviousDay(): void
    {
        $client = $this->seedTwoOzonDays('stocks-nearest@example.test', '11111111-1111-1111-1111-111111112002', '02');

        $client->request('GET', '/inventory/stocks?date='.$this->daysAgo(3)->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SKU-OLD', $html);
        self::assertStringNotContainsString('SKU-LATEST', $html);
        self::assertStringContainsString('показан ближайший на '.$this->daysAgo(5)->format('d.m.Y'), $html);
    }

    public function testExactSnapshotDateShowsThatDayWithoutFallbackNotice(): void
    {
        $client = $this->seedTwoOzonDays('stocks-exact@example.test', '11111111-1111-1111-1111-111111112007', '07');

        $client->request('GET', '/inventory/stocks?date='.$this->daysAgo(5)->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SKU-OLD', $html);
        self::assertStringNotContainsString('SKU-LATEST', $html);
        self::assertStringNotContainsString('показан ближайший на', $html);
    }

    public function testDateBeforeAnySnapshotRendersEmptyState(): void
    {
        $client = $this->seedTwoOzonDays('stocks-empty@example.test', '11111111-1111-1111-1111-111111112003', '03');

        $crawler = $client->request('GET', '/inventory/stocks?date='.$this->daysAgo(30)->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Нет остатков на выбранную дату', $html);
        self::assertStringNotContainsString('SKU-OLD', $html);
        self::assertStringNotContainsString('SKU-LATEST', $html);
        // Пустой отчёт не показывает футер со счётчиком «Показано 0–0 из 0».
        self::assertCount(0, $crawler->filter('.card-footer'));
    }

    #[DataProvider('brokenQueryProvider')]
    public function testBrokenQueryParamsFallBackToDefaults(string $queryString): void
    {
        $client = $this->seedTwoOzonDays('stocks-broken-'.md5($queryString).'@example.test', '11111111-1111-1111-1111-111111112004', '04');

        $client->request('GET', '/inventory/stocks?'.$queryString);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SKU-LATEST', (string) $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenQueryProvider(): iterable
    {
        yield 'not a date' => ['date=not-a-date'];
        yield 'non-existent day' => ['date=2026-02-31'];
        yield 'array date' => ['date[]=2026-05-10'];
        yield 'unsupported source' => ['source=yandex_market'];
        yield 'array source' => ['source[]=ozon'];
        yield 'year zero (вне диапазона PostgreSQL DATE)' => ['date=0000-01-01'];
        yield 'null byte' => ['date=%00'];
        yield 'date with trailing null byte' => ['date=2026-05-10%00'];
    }

    public function testSnapshotsOfAnotherCompanyAreNeverVisible(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('stocks-idor-owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112008')->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId($company->getId())->build();

        $stranger = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-222222222009')
            ->withEmail('stocks-idor-stranger@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112009')->withOwner($stranger)->build();
        $otherLocation = LocationBuilder::aLocation()->withCompanyId($otherCompany->getId())->build();

        $this->persist(
            $owner,
            $company,
            $location,
            $stranger,
            $otherCompany,
            $otherLocation,
            $this->snapshot($company, $location, '081', MarketplaceType::OZON, $this->daysAgo(1), 'SKU-MINE'),
            $this->snapshot($otherCompany, $otherLocation, '082', MarketplaceType::OZON, $this->daysAgo(1), 'SKU-STRANGER'),
        );

        $this->login($client, $owner, $company);
        $client->request('GET', '/inventory/stocks?date='.$this->daysAgo(1)->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SKU-MINE', $html);
        self::assertStringNotContainsString('SKU-STRANGER', $html);
    }

    public function testValidButAncientDateShowsEmptyReportInsteadOfToday(): void
    {
        $client = $this->seedTwoOzonDays('stocks-ancient@example.test', '11111111-1111-1111-1111-111111112010', '10');

        $client->request('GET', '/inventory/stocks?date=1969-12-31');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Нет остатков на выбранную дату', $html);
        self::assertStringNotContainsString('SKU-LATEST', $html);
    }

    public function testSourceFilterSeparatesMarketplaces(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('stocks-source@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112005')->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId($company->getId())->build();

        $this->persist(
            $owner,
            $company,
            $location,
            // Разные дни: эффективная дата обязана считаться отдельно по каждому источнику.
            $this->snapshot($company, $location, '051', MarketplaceType::OZON, $this->daysAgo(1), 'SKU-OZON'),
            $this->snapshot($company, $location, '052', MarketplaceType::WILDBERRIES, $this->daysAgo(4), 'SKU-WB'),
        );

        $this->login($client, $owner, $company);

        $client->request('GET', '/inventory/stocks?source=wildberries');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SKU-WB', $html);
        self::assertStringNotContainsString('SKU-OZON', $html);
        self::assertStringContainsString('показан ближайший на '.$this->daysAgo(4)->format('d.m.Y'), $html);

        $client->request('GET', '/inventory/stocks');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SKU-OZON', $html);
        self::assertStringNotContainsString('SKU-WB', $html);
        self::assertStringContainsString('показан ближайший на '.$this->daysAgo(1)->format('d.m.Y'), $html);
    }

    public function testRemovedFiltersAreIgnoredAndAvailableForSaleIsShown(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('stocks-legacy-filters@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112006')->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId($company->getId())->build();

        $unmapped = $this->snapshot($company, $location, '061', MarketplaceType::OZON, $this->daysAgo(1), 'UNMAPPED-SKU')
            ->withQuantity('10.000')
            ->withReservedQuantity('3.000')
            ->build();
        $mapped = $this->snapshot($company, $location, '062', MarketplaceType::OZON, $this->daysAgo(1), 'MATCHED-SKU')
            ->withMappingStatus(StockSnapshotMappingStatus::Mapped)
            ->build();

        $this->persist($owner, $company, $location, $unmapped, $mapped);
        $this->login($client, $owner, $company);

        $client->request('GET', '/inventory/stocks?mappingStatus=unmapped&search=zzz&status=defect&snapshotSessionId=not-a-uuid');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('UNMAPPED-SKU', $html);
        self::assertStringContainsString('MATCHED-SKU', $html);
        self::assertStringContainsString('10.000', $html);
        self::assertStringContainsString('3.000', $html);
        self::assertStringContainsString('7.000', $html);
    }

    public function testRowsAreGroupedBySkuWithFulfillmentTypeBreakdown(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('stocks-rollup@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112011')->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId($company->getId())->build();

        $this->persist(
            $owner,
            $company,
            $location,
            $this->snapshot($company, $location, '111', MarketplaceType::OZON, $this->daysAgo(1), 'SKU-ROLLUP')
                ->withFulfillmentType('fbo')
                ->withQuantity('10.000')
                ->withReservedQuantity('3.000'),
            $this->snapshot($company, $location, '112', MarketplaceType::OZON, $this->daysAgo(1), 'SKU-ROLLUP')
                ->withFulfillmentType('fbs')
                ->withQuantity('5.000')
                ->withReservedQuantity('1.000'),
        );

        $this->login($client, $owner, $company);
        $crawler = $client->request('GET', '/inventory/stocks?date='.$this->daysAgo(1)->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Дата-время snapshot', 'Marketplace/source', 'SKU', 'Offer ID', 'Остаток', 'Зарезервировано', 'Доступно расчётно', 'FBO', 'FBS', 'mappingStatus'],
            $crawler->filter('table thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        // Две записи снимка одного SKU схлопнуты в одну строку.
        self::assertCount(1, $crawler->filter('table tbody tr'));

        $cells = $crawler->filter('table tbody tr td')->each(static fn (Crawler $td): string => trim($td->text()));
        self::assertSame('SKU-ROLLUP', $cells[2]);
        self::assertSame('15.000', $cells[4]);
        self::assertSame('4.000', $cells[5]);
        // Доступно расчётно = сумма по обоим типам склада, и она сходится с разбивкой.
        self::assertSame('11.000', $cells[6]);
        self::assertSame('7.000', $cells[7]);
        self::assertSame('4.000', $cells[8]);
    }

    public function testWildberriesBreaksDownByStockStatus(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('stocks-wb-breakdown@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112012')->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId((string) $company->getId())->build();

        $this->persist(
            $owner,
            $company,
            $location,
            $this->snapshot($company, $location, '121', MarketplaceType::WILDBERRIES, $this->daysAgo(1), 'SKU-WB-ROLLUP')
                ->withFulfillmentType('fbw')
                ->withQuantity('8.000'),
            $this->snapshot($company, $location, '122', MarketplaceType::WILDBERRIES, $this->daysAgo(1), 'SKU-WB-ROLLUP')
                ->withFulfillmentType('fbw')
                ->withStatus(StockStatus::InTransitToCustomer)
                ->withQuantity('2.000'),
            $this->snapshot($company, $location, '123', MarketplaceType::WILDBERRIES, $this->daysAgo(1), 'SKU-WB-ROLLUP')
                ->withFulfillmentType('fbw')
                ->withStatus(StockStatus::InTransitFromCustomer)
                ->withQuantity('1.000'),
        );

        $this->login($client, $owner, $company);
        $crawler = $client->request('GET', '/inventory/stocks?source=wildberries&date='.$this->daysAgo(1)->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        // У Wildberries fulfillment_type всегда один, поэтому разбивка идёт по статусам остатка.
        self::assertSame(
            ['Дата-время snapshot', 'Marketplace/source', 'SKU', 'Offer ID', 'Остаток', 'Зарезервировано', 'Доступно расчётно', 'На складе', 'В пути к клиенту', 'В пути от клиента', 'mappingStatus'],
            $crawler->filter('table thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        self::assertCount(1, $crawler->filter('table tbody tr'));

        $cells = $crawler->filter('table tbody tr td')->each(static fn (Crawler $td): string => trim($td->text()));
        self::assertSame('11.000', $cells[6]);
        self::assertSame('8.000', $cells[7]);
        self::assertSame('2.000', $cells[8]);
        self::assertSame('1.000', $cells[9]);
    }

    public function testPaginationCountsSkuNotSnapshotRows(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('stocks-pagination@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112013')->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId((string) $company->getId())->build();

        $snapshots = [];
        foreach (['SKU-P1', 'SKU-P2'] as $index => $sku) {
            foreach (['fbo', 'fbs'] as $typeIndex => $fulfillmentType) {
                $snapshots[] = $this->snapshot(
                    $company,
                    $location,
                    '9'.$index.$typeIndex,
                    MarketplaceType::OZON,
                    $this->daysAgo(1),
                    $sku,
                )->withFulfillmentType($fulfillmentType);
            }
        }

        $this->persist($owner, $company, $location, ...$snapshots);
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', '/inventory/stocks?date='.$this->daysAgo(1)->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        // Четыре записи снимка, но страниц и позиций считается по двум SKU.
        self::assertCount(2, $crawler->filter('table tbody tr'));
        self::assertSame(
            'Показано 1–2 из 2',
            preg_replace('/\s+/u', ' ', trim($crawler->filter('.card-footer p')->text())),
        );
        // Одна страница: счётчик есть, навигации нет.
        self::assertCount(0, $crawler->filter('.card-footer nav'));
    }

    public function testPaginationKeepsFiltersInPageLinks(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('stocks-page-links@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111112014')->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId((string) $company->getId())->build();

        // На страницу выводится InventoryStockReportQuery::PER_PAGE SKU, поэтому нужен хотя бы один сверх.
        $snapshots = [];
        for ($index = 0; $index < InventoryStockReportQuery::PER_PAGE + 1; ++$index) {
            $snapshots[] = $this->snapshot(
                $company,
                $location,
                sprintf('%03d', 200 + $index),
                MarketplaceType::WILDBERRIES,
                $this->daysAgo(1),
                sprintf('SKU-PAGE-%02d', $index),
            );
        }

        $this->persist($owner, $company, $location, ...$snapshots);
        $this->login($client, $owner, $company);

        $date = $this->daysAgo(1)->format('Y-m-d');
        $crawler = $client->request('GET', '/inventory/stocks?source=wildberries&date='.$date);

        self::assertResponseIsSuccessful();
        self::assertCount(InventoryStockReportQuery::PER_PAGE, $crawler->filter('table tbody tr'));
        self::assertSame(
            sprintf('Показано 1–%d из %d', InventoryStockReportQuery::PER_PAGE, InventoryStockReportQuery::PER_PAGE + 1),
            preg_replace('/\s+/u', ' ', trim($crawler->filter('.card-footer p')->text())),
        );

        // Ссылки страниц берут фильтры из текущего query string, а не из белого списка шаблона.
        $links = $crawler->filter('.card-footer nav a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        $secondPage = array_values(array_filter($links, static fn (string $href): bool => str_contains($href, 'page=2')));

        self::assertNotEmpty($secondPage);
        self::assertStringContainsString('source=wildberries', $secondPage[0]);
        self::assertStringContainsString('date='.$date, $secondPage[0]);
    }

    private function seedTwoOzonDays(string $email, string $companyId, string $suffix): KernelBrowser
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $location = LocationBuilder::aLocation()->withCompanyId((string) $company->getId())->build();

        $this->persist(
            $owner,
            $company,
            $location,
            $this->snapshot($company, $location, $suffix.'1', MarketplaceType::OZON, $this->daysAgo(5), 'SKU-OLD'),
            $this->snapshot($company, $location, $suffix.'2', MarketplaceType::OZON, $this->daysAgo(1), 'SKU-LATEST'),
        );

        $this->login($client, $owner, $company);

        return $client;
    }

    /**
     * Возвращает билдер — вызывающий тест при необходимости уточняет количество/маппинг перед build().
     */
    private function snapshot(
        Company $company,
        Location $location,
        string $tail,
        MarketplaceType $source,
        \DateTimeImmutable $date,
        string $sku,
    ): StockSnapshotBuilder {
        return StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withLocationId($location->getId())
            ->withSource($source)
            ->withSnapshotSessionId('22222222-2222-4222-8222-222222222'.$tail)
            ->withRawSnapshotId('44444444-4444-4444-8444-444444444'.$tail)
            ->withSnapshotDate($date)
            ->withSnapshotAt($date->setTime(9, 0))
            ->withSourceSku($sku);
    }

    private function persist(object ...$entities): void
    {
        $em = $this->em();
        foreach ($entities as $entity) {
            $em->persist($entity instanceof StockSnapshotBuilder ? $entity->build() : $entity);
        }
        $em->flush();
    }

    private function daysAgo(int $days): \DateTimeImmutable
    {
        // Опорная дата фиксируется один раз на тест: иначе прогон через полночь развёл бы
        // дату посева и дату проверки на сутки.
        $this->today ??= new \DateTimeImmutable('today');

        return $this->today->modify(sprintf('-%d days', $days));
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
