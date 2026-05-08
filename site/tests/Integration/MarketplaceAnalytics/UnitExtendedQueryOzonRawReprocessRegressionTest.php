<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAnalytics;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonSalesRawProcessor;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class UnitExtendedQueryOzonRawReprocessRegressionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000111';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000111';
    private const PERIOD_FROM = '2026-02-01';
    private const PERIOD_TO = '2026-02-28';
    private const LISTING_SKU = 'OZON-REGRESSION-1';

    private UnitExtendedQuery $unitExtendedQuery;
    private OzonSalesRawProcessor $rawProcessor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unitExtendedQuery = self::getContainer()->get(UnitExtendedQuery::class);
        $this->rawProcessor = self::getContainer()->get(OzonSalesRawProcessor::class);

        $this->cleanupTestData();
        $this->seedCompanyAndListing();
    }

    public function test_reprocessing_same_raw_document_does_not_double_revenue_or_sales_rows(): void
    {
        $rawDocument = $this->createRawDocument();
        $rawRows = $this->buildRawSalesRows();

        $this->rawProcessor->resetPerRunState();
        $this->rawProcessor->processBatch(self::COMPANY_ID, MarketplaceType::OZON, $rawRows, $rawDocument->getId());

        $firstQueryResult = $this->unitExtendedQuery->execute(
            self::COMPANY_ID,
            MarketplaceType::OZON->value,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            limit: 100,
        );

        $firstRevenue = (float) $firstQueryResult['totals']['revenue'];

        self::assertEqualsWithDelta(15488951.02, $firstRevenue, 0.01, 'Санити-чек: первая обработка должна дать ожидаемую выручку в Unit Extended.');
        self::assertSame(1, $this->countRawSalesRows($rawDocument->getId()), 'Санити-чек: первая обработка должна записать одну строку продажи.');

        $this->rawProcessor->resetPerRunState();
        $this->rawProcessor->processBatch(self::COMPANY_ID, MarketplaceType::OZON, $rawRows, $rawDocument->getId());

        $secondQueryResult = $this->unitExtendedQuery->execute(
            self::COMPANY_ID,
            MarketplaceType::OZON->value,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            limit: 100,
        );

        self::assertEqualsWithDelta(
            $firstRevenue,
            (float) $secondQueryResult['totals']['revenue'],
            0.01,
            'Регрессия: Unit Extended не должен показывать удвоенную выручку после повторной обработки того же raw-документа.',
        );

        self::assertSame(
            1,
            $this->countRawSalesRows($rawDocument->getId()),
            'Регрессия: количество строк marketplace_sales по raw_document_id не должно удваиваться при повторной обработке.',
        );

        self::assertSame(
            ['OZON-POSTING-REG-1'],
            $this->getExternalOrderIds($rawDocument->getId()),
            'Регрессия: после повторной обработки должен остаться только базовый external_order_id без _v2.',
        );
    }


    private function cleanupTestData(): void
    {
        $connection = $this->em->getConnection();

        $connection->executeStatement(
            'DELETE FROM marketplace_sales WHERE company_id = :companyId',
            ['companyId' => self::COMPANY_ID],
        );

        $connection->executeStatement(
            'DELETE FROM marketplace_raw_documents WHERE company_id = :companyId',
            ['companyId' => self::COMPANY_ID],
        );

        $connection->executeStatement(
            'DELETE FROM marketplace_listings WHERE company_id = :companyId OR sku = :sku',
            ['companyId' => self::COMPANY_ID, 'sku' => self::LISTING_SKU],
        );

        $connection->executeStatement(
            'DELETE FROM company WHERE id = :companyId',
            ['companyId' => self::COMPANY_ID],
        );

        $connection->executeStatement(
            'DELETE FROM "user" WHERE id = :ownerId',
            ['ownerId' => self::OWNER_ID],
        );
    }

    private function seedCompanyAndListing(): void
    {
        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('unit-extended-raw-regression@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku(self::LISTING_SKU)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($listing);
        $this->em->flush();
    }

    private function createRawDocument(): MarketplaceRawDocument
    {
        /** @var Company $company */
        $company = $this->em->find(Company::class, self::COMPANY_ID);

        $rawDocument = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('sales')
            ->withPeriod(new \DateTimeImmutable(self::PERIOD_FROM), new \DateTimeImmutable(self::PERIOD_TO))
            ->build();

        $this->em->persist($rawDocument);
        $this->em->flush();

        return $rawDocument;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRawSalesRows(): array
    {
        return [[
            'operation_id' => 7000001,
            'operation_date' => '2026-02-14T10:00:00+00:00',
            'type' => 'orders',
            'operation_type' => 'OperationAgentDeliveredToCustomer',
            'accruals_for_sale' => 15488951.02,
            'posting' => [
                'posting_number' => 'OZON-POSTING-REG-1',
            ],
            'items' => [[
                'sku' => self::LISTING_SKU,
                'name' => 'Regression SKU',
            ]],
        ]];
    }

    private function countRawSalesRows(string $rawDocId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :companyId AND raw_document_id = :rawDocId',
            ['companyId' => self::COMPANY_ID, 'rawDocId' => $rawDocId],
        );
    }


    /**
     * @return list<string>
     */
    private function getExternalOrderIds(string $rawDocId): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT external_order_id FROM marketplace_sales WHERE company_id = :companyId AND raw_document_id = :rawDocId ORDER BY external_order_id ASC',
            ['companyId' => self::COMPANY_ID, 'rawDocId' => $rawDocId],
        );

        return $ids;
    }
}
