<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class StockTurnoverControllerTest extends WebTestCaseBase
{
    private const URL = '/marketplace-analytics/stock-turnover';
    private const NBSP = "\u{00A0}";

    private \DateTimeImmutable $date;
    private int $saleIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = new \DateTimeImmutable('yesterday');
    }

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

        $summary = $crawler->filter('.vf-turnover-summary')->text();
        self::assertStringContainsString('772'.self::NBSP.'400,00', $summary);
        self::assertStringContainsString('743'.self::NBSP.'600,00', $summary);
        self::assertStringContainsString('96'.self::NBSP.'%', $summary);
        self::assertStringContainsString('без себестоимости: 1,', $summary);
        self::assertStringContainsString('Остатки: Ozon на '.$this->date->format('d.m.Y'), $crawler->filter('.vf-turnover-stock-meta')->text());

        // Корзина словами, отрицательных нет; первая строка — «свыше 90 дней».
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

    public function testTabIsPresentInAnalyticsTabs(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-tab@example.test', '11111111-1111-1111-1111-111111118705');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace-analytics/snapshots');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="'.self::URL.'"]'));
    }

    /**
     * @return array{User, Company}
     */
    private function seedCompany(string $email, string $companyId): array
    {
        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);

        return [$owner, $company];
    }

    /** A 412/39/1800 → свыше 90; B 48/186/600 → до 30; C 20/0/100 → нет продаж; D 15/3 без цены. */
    private function seedStandardSet(Company $company): void
    {
        $tail = substr((string) $company->getId(), -2);
        $base = 9000 + (int) hexdec($tail) * 10;
        $a = $this->listing($company, $base + 1, 'SKU-A', 'Худи серое');
        $b = $this->listing($company, $base + 2, 'SKU-B', 'Футболка чёрная');
        $c = $this->listing($company, $base + 3, 'SKU-C', 'Носки');
        $d = $this->listing($company, $base + 4, 'SKU-D', 'Кепка');

        $this->snapshot($company, $a, '412.000');
        $this->snapshot($company, $b, '48.000');
        $this->snapshot($company, $c, '20.000');
        $this->snapshot($company, $d, '15.000');
        $this->costPrice($company, $a, '1800.00');
        $this->costPrice($company, $b, '600.00');
        $this->costPrice($company, $c, '100.00');
        $this->sale($company, $a, 39);
        $this->sale($company, $b, 186);
        $this->sale($company, $d, 3);
        $this->em()->flush();
    }

    private function listing(Company $company, int $index, string $sku, string $name): MarketplaceListing
    {
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withId(Uuid::uuid4()->toString())
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku($sku)
            ->withName($name)
            ->build();
        $this->em()->persist($listing);

        return $listing;
    }

    private function snapshot(Company $company, MarketplaceListing $listing, string $qty, ?\DateTimeImmutable $date = null): void
    {
        $date ??= $this->date;
        $this->em()->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId((string) $company->getId())
                ->withSnapshotSessionId(Uuid::uuid5(Uuid::NAMESPACE_DNS, (string) $company->getId())->toString())
                ->withSnapshotDate($date)
                ->withSnapshotAt($date->setTime(4, 5))
                ->withSource(MarketplaceType::OZON)
                ->withListingId($listing->getId())
                ->withProductId(null)
                ->withStatus(StockStatus::Available)
                ->withFulfillmentType('fbo')
                ->withSourceSku($listing->getMarketplaceSku())
                ->withQuantity($qty)
                ->build(),
        );
    }

    private function costPrice(Company $company, MarketplaceListing $listing, string $amount): void
    {
        $this->em()->persist(new MarketplaceInventoryCostPrice(
            Uuid::uuid4()->toString(),
            (string) $company->getId(),
            $listing,
            $this->date->modify('-100 days'),
            $amount,
        ));
    }

    private function sale(Company $company, MarketplaceListing $listing, int $quantity): void
    {
        $this->em()->persist(
            MarketplaceSaleBuilder::aSale()
                ->forCompany($company)
                ->forListing($listing)
                ->withId(Uuid::uuid4()->toString())
                ->withExternalOrderId('TURN-'.++$this->saleIndex.'-'.substr((string) $company->getId(), -4))
                ->withSaleDate($this->date->modify('-3 days'))
                ->withQuantity($quantity)
                ->build(),
        );
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
    }
}
