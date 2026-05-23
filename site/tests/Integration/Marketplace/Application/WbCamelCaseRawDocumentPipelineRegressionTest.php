<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class WbCamelCaseRawDocumentPipelineRegressionTest extends IntegrationTestCase
{
    public function testPipelineProcessesCamelCaseWbRawDocumentForSalesReturnsAndCosts(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(410)->build();
        $this->em->persist($company);

        $listing = MarketplaceListingBuilder::aListing()
            ->withIndex(410)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('200000000001')
            ->build();
        $this->em->persist($listing);

        $rawDocId = '99999999-aaaa-4aaa-8aaa-222222222222';
        $day = new \DateTimeImmutable('2026-05-21');

        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->withId($rawDocId)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->build();

        $rawDoc->setRawData([
            [
                'reportId' => 1,
                'rrdId' => 1001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Продажа',
                'quantity' => 1,
                'retailPriceWithDisc' => '2099',
                'retailAmount' => '1584',
                'commissionPercent' => 34,
                'forPay' => '1308.04',
                'acquiringFee' => '77.30',
                'nmId' => 123456,
                'techSize' => 'M',
                'sku' => '200000000001',
                'vendorCode' => 'ART-001',
                'brandName' => 'TestBrand',
                'subjectName' => 'Одежда',
                'saleDt' => '2026-05-21T10:00:00Z',
                'rrDate' => '2026-05-21',
                'srid' => 'test-sale-srid-1',
            ],
            [
                'reportId' => 1,
                'rrdId' => 1002,
                'docTypeName' => 'Возврат',
                'sellerOperName' => 'Возврат',
                'quantity' => 1,
                'retailPriceWithDisc' => '2099',
                'retailAmount' => '1584',
                'commissionPercent' => 34,
                'forPay' => '1308.04',
                'acquiringFee' => '77.30',
                'nmId' => 123456,
                'techSize' => 'M',
                'sku' => '200000000001',
                'vendorCode' => 'ART-001',
                'brandName' => 'TestBrand',
                'subjectName' => 'Одежда',
                'saleDt' => '2026-05-22T10:00:00Z',
                'rrDate' => '2026-05-22',
                'srid' => 'test-return-srid-1',
            ],
            [
                'reportId' => 1,
                'rrdId' => 1003,
                'docTypeName' => '',
                'sellerOperName' => 'Логистика',
                'quantity' => 0,
                'retailPriceWithDisc' => '0',
                'deliveryAmount' => 1,
                'returnAmount' => 0,
                'deliveryService' => '80',
                'nmId' => 123456,
                'techSize' => 'M',
                'sku' => '200000000001',
                'vendorCode' => 'ART-001',
                'brandName' => 'TestBrand',
                'subjectName' => 'Одежда',
                'saleDt' => '2026-05-21T10:00:00Z',
                'rrDate' => '2026-05-21',
                'srid' => 'test-logistics-srid-1',
            ],
        ]);

        $this->em->persist($rawDoc);
        $this->em->flush();

        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);
        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'sales'));
        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'returns'));
        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'costs'));

        self::assertSame(1, $this->salesCount($company->getId(), $rawDocId));
        self::assertSame(2099.0, $this->saleRevenue($company->getId(), $rawDocId, 'test-sale-srid-1'));
        self::assertSame(1, $this->returnsCount($company->getId(), $rawDocId));

        self::assertEqualsWithDelta(
            713.66,
            $this->costAmountByCategoryAndOperation($company->getId(), $rawDocId, 'commission', 'charge'),
            0.01,
        );
        self::assertEqualsWithDelta(
            713.66,
            $this->costAmountByCategoryAndOperation($company->getId(), $rawDocId, 'commission', 'storno'),
            0.01,
        );
        self::assertEqualsWithDelta(
            80.0,
            $this->costAmountByCategoryAndOperation($company->getId(), $rawDocId, 'logistics_delivery', 'charge'),
            0.01,
        );

        self::assertNotNull($this->salesListingId($company->getId(), $rawDocId));
        self::assertNotNull($this->returnsListingId($company->getId(), $rawDocId));
        self::assertNotNull($this->costListingIdByCategory($company->getId(), $rawDocId, 'logistics_delivery'));
        self::assertNotNull($this->costListingIdByCategory($company->getId(), $rawDocId, 'commission'));
    }

    public function testPipelineStillProcessesSnakeCaseWbRawDocument(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(411)->build();
        $this->em->persist($company);
        $rawDocId = '99999999-aaaa-4aaa-8aaa-333333333333';

        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->withId($rawDocId)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-05-21'), new \DateTimeImmutable('2026-05-21'))
            ->build();

        $rawDoc->setRawData([
            [
                'doc_type_name' => 'Продажа',
                'supplier_oper_name' => 'Продажа',
                'quantity' => 1,
                'retail_price_withdisc_rub' => '2099',
                'retail_amount' => '1584',
                'nm_id' => 123456,
                'barcode' => '200000000001',
                'sale_dt' => '2026-05-21 10:00:00',
                'rr_dt' => '2026-05-21 10:00:00',
                'srid' => 'snake-sale-1',
            ],
        ]);

        $this->em->persist($rawDoc);
        $this->em->flush();

        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);
        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'sales'));

        self::assertSame(1, $this->salesCount($company->getId(), $rawDocId));
        self::assertSame(2099.0, $this->saleRevenue($company->getId(), $rawDocId, 'snake-sale-1'));
    }

    private function salesCount(string $companyId, string $rawDocId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE company_id=:c AND raw_document_id=:r', ['c' => $companyId, 'r' => $rawDocId]);
    }

    private function returnsCount(string $companyId, string $rawDocId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE company_id=:c AND raw_document_id=:r', ['c' => $companyId, 'r' => $rawDocId]);
    }

    private function saleRevenue(string $companyId, string $rawDocId, string $srid): float
    {
        return (float) $this->connection->fetchOne('SELECT total_revenue FROM marketplace_sales WHERE company_id=:c AND raw_document_id=:r AND external_order_id=:e', ['c' => $companyId, 'r' => $rawDocId, 'e' => $srid]);
    }

    private function costAmountByCategoryAndOperation(
        string $companyId,
        string $rawDocId,
        string $categoryCode,
        string $operationType,
    ): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(c.amount), 0)
            FROM marketplace_costs c
            JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id = :c
              AND c.raw_document_id = :r
              AND cc.code = :code
              AND c.operation_type = :operationType',
            ['c' => $companyId, 'r' => $rawDocId, 'code' => $categoryCode, 'operationType' => $operationType],
        );
    }

    private function costListingIdByCategory(string $companyId, string $rawDocId, string $categoryCode): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT c.listing_id
            FROM marketplace_costs c
            JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id = :c
              AND c.raw_document_id = :r
              AND cc.code = :code
            LIMIT 1',
            ['c' => $companyId, 'r' => $rawDocId, 'code' => $categoryCode],
        );

        return $value === false ? null : (string) $value;
    }

    private function salesListingId(string $companyId, string $rawDocId): ?string
    {
        $value = $this->connection->fetchOne('SELECT listing_id FROM marketplace_sales WHERE company_id=:c AND raw_document_id=:r LIMIT 1', ['c' => $companyId, 'r' => $rawDocId]);

        return $value === false ? null : (string) $value;
    }

    private function returnsListingId(string $companyId, string $rawDocId): ?string
    {
        $value = $this->connection->fetchOne('SELECT listing_id FROM marketplace_returns WHERE company_id=:c AND raw_document_id=:r LIMIT 1', ['c' => $companyId, 'r' => $rawDocId]);

        return $value === false ? null : (string) $value;
    }
}
