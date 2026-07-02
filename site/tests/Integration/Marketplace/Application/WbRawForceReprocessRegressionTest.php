<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\ReprocessMarketplacePeriodAction;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class WbRawForceReprocessRegressionTest extends IntegrationTestCase
{
    public function testPeriodReprocessReplacesOpenWbSalesBySridSoSaleGrossUsesNewFormula(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(402)->build();
        $this->em->persist($company->getUser());
        $this->em->persist($company);

        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('10001')
            ->build();
        $this->em->persist($listing);

        $day = new \DateTimeImmutable('2026-04-21');
        $staleSale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $company,
            $listing,
            MarketplaceType::WILDBERRIES,
        );
        $staleSale->setExternalOrderId('SRID-SALE-REPROCESS-1');
        $staleSale->setSaleDate($day);
        $staleSale->setQuantity(1);
        $staleSale->setPricePerUnit('1584.00');
        $staleSale->setTotalRevenue('1584.00');
        $this->em->persist($staleSale);

        $rawDocId = '99999999-aaaa-4aaa-8aaa-111111111142';
        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->withId($rawDocId)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->withSyncedAt(new \DateTimeImmutable('2026-04-22 10:00:00'))
            ->build();
        $rawDoc->setRawData([$this->makeWbSaleRowWithAmounts(
            srid: 'SRID-SALE-REPROCESS-1',
            retailAmount: 1584.00,
            forPay: 1308.04,
            acquiringFee: 77.30,
            ppvzVw: 600.00,
            ppvzVwNds: 113.66,
        )]);
        $this->em->persist($rawDoc);
        $this->em->flush();
        $this->em->clear();

        $action = self::getContainer()->get(ReprocessMarketplacePeriodAction::class);
        $result = $action(
            companyId: (string) $company->getId(),
            marketplace: MarketplaceType::WILDBERRIES->value,
            periodFrom: $day,
            periodTo: $day,
            type: 'sales_report',
        );

        self::assertSame(1, $result['docs']);
        self::assertSame(1, $result['sales']);
        self::assertSame(1, $this->saleRowsCountBySrid($company->getId(), 'SRID-SALE-REPROCESS-1'));
        self::assertSame(1584.0, $this->saleRevenueBySrid($company->getId(), 'SRID-SALE-REPROCESS-1'));
        self::assertSame(2099.0, $this->saleGrossBySrid($company->getId(), 'SRID-SALE-REPROCESS-1'));
    }

    public function testForceReprocessReplacesWbSalesAndReturnsByRawDocument(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(401)->build();
        $this->em->persist($company->getUser());
        $this->em->persist($company);

        $rawDocId = '99999999-aaaa-4aaa-8aaa-111111111111';
        $day = new \DateTimeImmutable('2026-04-20');
        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->withId($rawDocId)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->build();
        $rawDoc->setRawData([
            $this->makeWbSaleRow('SRID-SALE-1', 1000.0),
            $this->makeWbReturnRow('SRID-RETURN-1', 500.0),
        ]);
        $this->em->persist($rawDoc);
        $this->em->flush();

        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);

        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'sales'));
        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'returns'));

        self::assertSame(1000.0, $this->saleAmount($company->getId(), $rawDocId, 'SRID-SALE-1'));
        self::assertSame(500.0, $this->returnAmount($company->getId(), $rawDocId, 'SRID-RETURN-1'));

        $rawDoc = $this->em->getRepository(MarketplaceRawDocument::class)->find($rawDocId);
        self::assertInstanceOf(MarketplaceRawDocument::class, $rawDoc);
        $rawDoc->setRawData([
            $this->makeWbSaleRow('SRID-SALE-1', 1700.0),
            $this->makeWbReturnRow('SRID-RETURN-1', 900.0),
        ]);
        $this->em->flush();
        $this->em->clear();

        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'sales', true));
        $action(new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), $rawDocId, 'returns', true));

        self::assertSame(1, $this->saleRowsCount($company->getId(), $rawDocId, 'SRID-SALE-1'));
        self::assertSame(1, $this->returnRowsCount($company->getId(), $rawDocId, 'SRID-RETURN-1'));
        self::assertSame(1700.0, $this->saleAmount($company->getId(), $rawDocId, 'SRID-SALE-1'));
        self::assertSame(900.0, $this->returnAmount($company->getId(), $rawDocId, 'SRID-RETURN-1'));
    }

    private function makeWbSaleRow(string $srid, float $retailPriceWithDisc): array
    {
        return [
            'doc_type_name' => 'Продажа',
            'supplier_oper_name' => 'Продажа',
            'srid' => $srid,
            'nm_id' => '10001',
            'ts_name' => 'M',
            'sa_name' => 'ART-1',
            'barcode' => '1234567890123',
            'quantity' => 1,
            'retail_price_withdisc_rub' => $retailPriceWithDisc,
            'retail_amount' => $retailPriceWithDisc,
            'ppvz_for_pay' => $retailPriceWithDisc - 150.0,
            'acquiring_fee' => 20.0,
            'ppvz_vw' => 100.0,
            'ppvz_vw_nds' => 30.0,
            'retail_price' => 1500.0,
            'sale_dt' => '2026-04-20 12:00:00',
            'rr_dt' => '2026-04-20 12:00:00',
        ];
    }

    private function makeWbSaleRowWithAmounts(
        string $srid,
        float $retailAmount,
        float $forPay,
        float $acquiringFee,
        float $ppvzVw,
        float $ppvzVwNds,
    ): array {
        return [
            'doc_type_name' => 'Продажа',
            'supplier_oper_name' => 'Продажа',
            'srid' => $srid,
            'nm_id' => '10001',
            'ts_name' => 'UNKNOWN',
            'sa_name' => 'ART-1',
            'barcode' => '1234567890123',
            'quantity' => 1,
            'retail_price_withdisc_rub' => $retailAmount,
            'retail_amount' => $retailAmount,
            'ppvz_for_pay' => $forPay,
            'acquiring_fee' => $acquiringFee,
            'ppvz_vw' => $ppvzVw,
            'ppvz_vw_nds' => $ppvzVwNds,
            'retail_price' => 3000.0,
            'sale_dt' => '2026-04-21 12:00:00',
            'rr_dt' => '2026-04-21 12:00:00',
        ];
    }

    private function makeWbReturnRow(string $srid, float $retailPriceWithDisc): array
    {
        return [
            'doc_type_name' => 'Возврат',
            'supplier_oper_name' => 'Возврат покупателем',
            'srid' => $srid,
            'nm_id' => '10001',
            'ts_name' => 'M',
            'sa_name' => 'ART-1',
            'barcode' => '1234567890123',
            'quantity' => 1,
            'retail_price_withdisc_rub' => $retailPriceWithDisc,
            'retail_amount' => 300.0,
            'retail_price' => 900.0,
            'sale_dt' => '2026-04-20 12:00:00',
            'rr_dt' => '2026-04-20 12:00:00',
        ];
    }

    private function saleRowsCount(string $companyId, string $rawDocId, string $srid): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :companyId AND raw_document_id = :rawDocId AND external_order_id = :srid',
            ['companyId' => $companyId, 'rawDocId' => $rawDocId, 'srid' => $srid],
        );
    }

    private function saleRowsCountBySrid(string $companyId, string $srid): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :companyId AND external_order_id = :srid',
            ['companyId' => $companyId, 'srid' => $srid],
        );
    }

    private function returnRowsCount(string $companyId, string $rawDocId, string $srid): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_returns WHERE company_id = :companyId AND raw_document_id = :rawDocId AND external_return_id = :srid',
            ['companyId' => $companyId, 'rawDocId' => $rawDocId, 'srid' => $srid],
        );
    }

    private function saleAmount(string $companyId, string $rawDocId, string $srid): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT total_revenue FROM marketplace_sales WHERE company_id = :companyId AND raw_document_id = :rawDocId AND external_order_id = :srid',
            ['companyId' => $companyId, 'rawDocId' => $rawDocId, 'srid' => $srid],
        );
    }

    private function saleRevenueBySrid(string $companyId, string $srid): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT total_revenue FROM marketplace_sales WHERE company_id = :companyId AND external_order_id = :srid',
            ['companyId' => $companyId, 'srid' => $srid],
        );
    }

    private function saleGrossBySrid(string $companyId, string $srid): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT price_per_unit * quantity FROM marketplace_sales WHERE company_id = :companyId AND external_order_id = :srid',
            ['companyId' => $companyId, 'srid' => $srid],
        );
    }

    private function returnAmount(string $companyId, string $rawDocId, string $srid): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT refund_amount FROM marketplace_returns WHERE company_id = :companyId AND raw_document_id = :rawDocId AND external_return_id = :srid',
            ['companyId' => $companyId, 'rawDocId' => $rawDocId, 'srid' => $srid],
        );
    }
}
