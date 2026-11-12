<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonSearchPromoSyncCommandTest extends IntegrationTestCase
{
    private const string COMPANY_ID = '11111111-1111-1111-1111-000000000071';
    private const string LISTING_ID = '55555555-5555-5555-5555-000000000071';
    private const string LISTING_2_ID = '55555555-5555-5555-5555-000000000072';

    public function testProjectsOrdersReportIntoPayPerOrderStatisticsByOrderedSku(): void
    {
        $this->seedCompanyWithListing('111');
        $this->store('2026-11-01:2026-11-07', [
            self::order('03.11.2026', 'A', '111', '111', '50,00'),
            // Заказан другой товар, чем продвигался: расход — на заказанный.
            self::order('03.11.2026', 'B', '111', '999', '1 000,50'),
            // SKU, которого нет среди листингов: сумма остаётся без строки листинга.
            self::order('04.11.2026', 'C', '777', '777', '30,00'),
        ]);
        $this->seedClicksDocument();

        $display = $this->sync();

        self::assertStringContainsString('дней записано 2, строк 3, расход 1080.50, заказанный SKU ≠ продвигаемому 1, дней с SKU без листинга 1', $display);
        $facade = $this->facade();
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-11-07');
        self::assertSame([self::LISTING_ID => '1050.50'], $facade->getAdSpendByListingForPeriod(self::COMPANY_ID, $from, $to, 'ozon', AdSpendKind::PAY_PER_ORDER));
        self::assertSame('1080.50', $facade->getTotalAdCostForPeriod(self::COMPANY_ID, $from, $to, 'ozon', AdSpendKind::PAY_PER_ORDER));
        self::assertSame('5.00', $facade->getTotalAdCostForPeriod(self::COMPANY_ID, $from, $to, 'ozon', AdSpendKind::CLICKS));
        self::assertSame('1085.50', $facade->getTotalAdCostForPeriod(self::COMPANY_ID, $from, $to, 'ozon'));
        self::assertSame([self::LISTING_ID => '1055.50'], $facade->getAdSpendByListingForPeriod(self::COMPANY_ID, $from, $to, 'ozon'));
    }

    public function testRepeatedSyncDoesNotDuplicateAndEmptiedDayIsCleared(): void
    {
        $this->seedCompanyWithListing('111');
        $this->store('2026-11-01:2026-11-07', [
            self::order('03.11.2026', 'A', '111', '111', '50,00'),
            self::order('04.11.2026', 'B', '111', '111', '20,00'),
        ]);
        $this->sync();
        $this->sync();
        self::assertSame(2, $this->countPayPerOrderDocuments());

        // Новая версия окна: заказ 04.11 исчез из отчёта.
        $this->store('2026-11-01:2026-11-07', [self::order('03.11.2026', 'A', '111', '111', '50,00')]);
        $this->sync();

        self::assertSame(1, $this->countPayPerOrderDocuments());
        self::assertSame('50.00', $this->facade()->getTotalAdCostForPeriod(self::COMPANY_ID, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-07')));
    }

    public function testClicksPipelineDoesNotSeePayPerOrderRawDocument(): void
    {
        $this->seedCompanyWithListing('111');
        $this->store('2026-11-01:2026-11-07', [self::order('03.11.2026', 'A', '111', '111', '50,00')]);
        $this->sync();

        /** @var AdRawDocumentRepository $repository */
        $repository = self::getContainer()->get(AdRawDocumentRepository::class);
        $day = new \DateTimeImmutable('2026-11-03');
        self::assertNull($repository->findByMarketplaceAndDate(self::COMPANY_ID, 'ozon', $day));
        self::assertSame(0, $repository->countByCompanyMarketplaceAndDateRange(self::COMPANY_ID, 'ozon', $day, $day));

        $clicks = $this->seedClicksDocument();
        self::assertSame($clicks, $repository->findByMarketplaceAndDate(self::COMPANY_ID, 'ozon', $day)?->getId());
        self::assertSame(1, $repository->countByCompanyMarketplaceAndDateRange(self::COMPANY_ID, 'ozon', $day, $day));
    }

    public function testAllSkuPromoSpendIsAllocatedByCampaignProductSales(): void
    {
        $company = $this->seedCompanyWithListing('111');
        $listing111 = $this->em->find(MarketplaceListing::class, self::LISTING_ID);
        self::assertNotNull($listing111);
        $listing222 = new MarketplaceListing(self::LISTING_2_ID, $company, null, MarketplaceType::OZON);
        $listing222->setMarketplaceSku('222');
        $listing222->setPrice('0.00');
        $this->em->persist($listing222);
        // 03.11: продажи 3 : 1; 04.11 продаж нет — доли за период (тоже 3 : 1).
        foreach ([[$listing111, 3], [$listing222, 1]] as [$listing, $quantity]) {
            $this->em->persist(MarketplaceSaleBuilder::aSale()->forCompany($company)->forListing($listing)
                ->withSaleDate(new \DateTimeImmutable('2026-11-03'))->withQuantity($quantity)->build());
        }
        $this->em->flush();

        $this->storeRaw(OzonResourceType::PERFORMANCE_CAMPAIGNS, 'performance-campaigns:2026-11-01:2026-11-07', [
            ['id' => '20', 'advObjectType' => 'SEARCH_PROMO'],
            ['id' => '30', 'advObjectType' => 'ALL_SKU_PROMO'],
            ['id' => '31', 'advObjectType' => 'ALL_SKU_PROMO'],
        ]);
        $this->storeRaw(OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS, 'performance-search-promo-products:30:2026-11-01:2026-11-07:page:1', [
            ['sku' => '111'], ['sku' => '222'], ['sku' => '999'],
        ]);
        // У кампании 31 товары без листингов — расход остаётся без товара.
        $this->storeRaw(OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS, 'performance-search-promo-products:31:2026-11-01:2026-11-07:page:1', [['sku' => '888']]);
        $this->storeRaw(OzonResourceType::PERFORMANCE_EXPENSE_STATISTICS, 'performance-expense-stats:2026-11-01:2026-11-07', [
            ['id' => '30', 'date' => '2026-11-03', 'moneySpent' => '90,00', 'prepaymentSpent' => '10,00', 'bonusSpent' => '5,00'],
            ['id' => '30', 'date' => '2026-11-04', 'moneySpent' => '50,00', 'prepaymentSpent' => '0,00'],
            ['id' => '31', 'date' => '2026-11-03', 'moneySpent' => '20,00', 'prepaymentSpent' => '0,00'],
            // SEARCH_PROMO — разносится отчётом по заказам, здесь не учитывается.
            ['id' => '20', 'date' => '2026-11-03', 'moneySpent' => '77,00', 'prepaymentSpent' => '0,00'],
        ]);

        $display = $this->sync();
        $this->sync();

        self::assertStringContainsString('все товары (ALL_SKU_PROMO) — кампаний 2, дней записано 2, расход 170.00, без товара 20.00', $display);
        $facade = $this->facade();
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-11-07');
        self::assertSame(
            [self::LISTING_ID => '112.50', self::LISTING_2_ID => '37.50'],
            $facade->getAdSpendByListingForPeriod(self::COMPANY_ID, $from, $to, 'ozon', AdSpendKind::PAY_PER_ORDER),
        );
        self::assertSame('170.00', $facade->getTotalAdCostForPeriod(self::COMPANY_ID, $from, $to, 'ozon', AdSpendKind::PAY_PER_ORDER));
        self::assertSame('0', $facade->getTotalAdCostForPeriod(self::COMPANY_ID, $from, $to, 'ozon', AdSpendKind::CLICKS));
        // Повтор не задваивает: 03.11 — 111, 222, без товара; 04.11 — 111, 222.
        self::assertSame(5, $this->em->getRepository(AdDocument::class)->count(['companyId' => self::COMPANY_ID, 'campaignId' => 'all-sku-promo']));
        // Без товара — осознанный итог распределения, не ошибка маппинга.
        self::assertSame([AdRawDocumentStatus::PROCESSED->value], $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT status FROM marketplace_ad_raw_documents WHERE company_id = :companyId',
            ['companyId' => self::COMPANY_ID],
        ));
        // CPC-конвейер не видит документ «все товары».
        /** @var AdRawDocumentRepository $repository */
        $repository = self::getContainer()->get(AdRawDocumentRepository::class);
        self::assertNull($repository->findByMarketplaceAndDate(self::COMPANY_ID, 'ozon', new \DateTimeImmutable('2026-11-03')));
    }

    public function testRejectsInvalidPeriod(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => self::COMPANY_ID, '--from' => '2026-11-07', '--to' => '2026-11-01']));
    }

    private function sync(): string
    {
        $tester = $this->tester();
        $status = $tester->execute(['--company-id' => self::COMPANY_ID, '--from' => '2026-11-01', '--to' => '2026-11-07']);
        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->em->clear();

        return $tester->getDisplay();
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:marketplace-ads:ozon-search-promo:sync'));
    }

    private function facade(): MarketplaceAdsFacade
    {
        /** @var MarketplaceAdsFacade $facade */
        $facade = self::getContainer()->get(MarketplaceAdsFacade::class);

        return $facade;
    }

    private function countPayPerOrderDocuments(): int
    {
        return $this->em->getRepository(AdDocument::class)->count(['companyId' => self::COMPANY_ID, 'campaignId' => 'search-promo']);
    }

    private function seedCompanyWithListing(string $sku): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('cpo-owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $listing = new MarketplaceListing(self::LISTING_ID, $company, null, MarketplaceType::OZON);
        $listing->setMarketplaceSku($sku);
        $listing->setPrice('0.00');
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($listing);
        $this->em->flush();

        return $company;
    }

    /**
     * Документ загрузки кликов (CPC) за 03.11: 5 ₽ на тот же листинг.
     */
    private function seedClicksDocument(): string
    {
        $raw = new AdRawDocument(
            self::COMPANY_ID,
            MarketplaceType::OZON,
            new \DateTimeImmutable('2026-11-03'),
            (string) json_encode(['rows' => [['campaign_id' => '42', 'campaign_name' => 'Клики', 'sku' => '111', 'spend' => 5, 'views' => 10, 'clicks' => 1]]]),
        );
        $this->em->persist($raw);
        $this->em->flush();
        $id = $raw->getId();
        $this->em->clear();
        self::getContainer()->get(\App\MarketplaceAds\Application\ProcessAdRawDocumentAction::class)(self::COMPANY_ID, $id);
        $this->em->clear();
        self::assertSame(AdRawDocumentStatus::PROCESSED, $this->em->find(AdRawDocument::class, $id)?->getStatus());

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private static function order(string $day, string $orderId, string $sku, string $promotedSku, string $expense): array
    {
        return ['Дата' => $day, 'ID заказа' => $orderId, 'SKU' => $sku, 'SKU продвигаемого товара' => $promotedSku, 'Расход, ₽' => $expense];
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function storeRaw(string $resourceType, string $externalId, array $rows): void
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        $facade->store(new RawBatch(
            companyId: self::COMPANY_ID,
            connectionRef: 'perf-connection',
            shopRef: 'perf-connection',
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable(),
            rows: $rows,
        ));
        usleep(2000);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function store(string $window, array $rows): void
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        $facade->store(new RawBatch(
            companyId: self::COMPANY_ID,
            connectionRef: 'perf-connection',
            shopRef: 'perf-connection',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            externalId: 'performance-search-promo-stats:'.$window.':orders:batch:abc',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable(),
            rows: $rows,
        ));
        // Версии различаются по last_seen_at: следующая сохраняется позже.
        usleep(2000);
    }
}
