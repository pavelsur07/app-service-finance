<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbRawFinancialReportBuilder;
use App\Marketplace\Application\Service\WbRawFinancialReportProductEnricher;
use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class WbRawFinancialReportProductEnricherTest extends IntegrationTestCase
{
    public function testCalculatesSkuResultFromTenantScopedHistoricalCosts(): void
    {
        $company = $this->company(601);
        $listing = $this->listing($company, 601, '123', 'M', '', '', '460000000001');
        $juneCost = $this->cost($company, $listing, '2026-06-01', '30.00');
        $juneCost->closeAt(new \DateTimeImmutable('2026-06-30'));
        $this->cost($company, $listing, '2026-07-01', '40.00');

        $otherCompany = $this->company(602);
        $otherListing = $this->listing(
            $otherCompany,
            602,
            '123',
            'M',
            'OTHER',
            'Чужой товар',
            '460000000001',
        );
        $this->cost($otherCompany, $otherListing, '2026-01-01', '999.00');
        $this->em->flush();

        $report = $this->report([
            [
                'reportId' => 9201,
                'rrdId' => 20001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Продажа',
                'nmId' => 123,
                'techSize' => 'M',
                'sku' => '460000000001',
                'saleDt' => '2026-07-01',
                'quantity' => 2,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '180',
                'forPay' => '150',
                'acquiringFee' => '4',
            ],
            [
                'reportId' => 9201,
                'rrdId' => 20002,
                'docTypeName' => 'Возврат',
                'sellerOperName' => 'Возврат',
                'techSize' => 'M',
                'sku' => '460000000001',
                'vendorCode' => 'SELLER-1',
                'brandName' => 'Brand',
                'subjectName' => 'Футболка',
                'orderDt' => '2026-06-30',
                'quantity' => 1,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '90',
                'forPay' => '75',
                'acquiringFee' => '2',
            ],
        ], $company, 2);

        self::assertArrayNotHasKey('_product_sources', $report);
        self::assertCount(1, $report['products']);
        $product = $report['products'][0];
        self::assertSame($listing->getId(), $product['listing_id']);
        self::assertSame('mapped', $product['mapping_status']);
        self::assertSame('SELLER-1', $product['supplier_sku']);
        self::assertSame('Brand Футболка', $product['name']);
        self::assertSame('complete', $product['cost_status']);
        self::assertSame(2, $product['sold_quantity']);
        self::assertSame(1, $product['returned_quantity']);
        self::assertSame(1, $product['net_quantity']);
        self::assertSame(10000, $product['net_sales_without_spp_minor']);
        self::assertSame(9000, $product['net_sales_with_spp_minor']);
        self::assertSame(7500, $product['for_pay_minor']);
        self::assertSame(8000, $product['sold_cost_minor']);
        self::assertSame(3000, $product['returned_cost_minor']);
        self::assertSame(5000, $product['net_cost_minor']);
        self::assertSame(2500, $product['result_minor']);
        self::assertSame('positive', $product['result_status']);
        self::assertSame('100.0', $product['cost_coverage_percent']);
        self::assertSame('50.0', $product['return_rate_percent']);
        self::assertSame('25.0', $product['profitability_percent']);
        self::assertSame(0, $product['fallback_cost_quantity']);

        self::assertSame(1, $report['product_summary']['sku_count']);
        self::assertSame(2500, $report['product_summary']['result_minor']);
        self::assertSame(0, $report['product_summary']['unallocated_for_pay_minor']);
        self::assertSame(0, $report['product_summary']['sales_reconciliation_minor']);
    }

    public function testKeepsConflictsAndMissingCostsVisibleWithoutFalseResult(): void
    {
        $company = $this->company(603);
        $fallbackListing = $this->listing($company, 603, '301', 'M', 'FALLBACK', 'Fallback', '460000000301');
        $this->cost($company, $fallbackListing, '2026-07-01', '50.00');
        $missingListing = $this->listing($company, 604, '302', 'M', 'MISSING', 'Missing', '460000000302');
        $conflictByNm = $this->listing($company, 605, '303', 'M', 'CONFLICT-NM', 'Conflict NM', '460000000303');
        $conflictByBarcode = $this->listing(
            $company,
            606,
            '304',
            'M',
            'CONFLICT-BARCODE',
            'Conflict barcode',
            '460000000304',
        );
        $this->cost($company, $conflictByNm, '2026-01-01', '10.00');
        $this->cost($company, $conflictByBarcode, '2026-01-01', '20.00');
        $zeroCostListing = $this->listing($company, 607, '305', 'M', 'ZERO', 'Zero cost', '460000000305');
        $this->cost($company, $zeroCostListing, '2026-01-01', '0.00');
        $foreignCostListing = $this->listing($company, 608, '306', 'M', 'USD', 'USD cost', '460000000306');
        $this->cost($company, $foreignCostListing, '2026-01-01', '10.00', 'USD');
        $partialListing = $this->listing($company, 609, '307', 'M', 'PARTIAL', 'Partial cost', '460000000307');
        $zeroJuneCost = $this->cost($company, $partialListing, '2026-06-01', '0.00');
        $zeroJuneCost->closeAt(new \DateTimeImmutable('2026-06-30'));
        $this->cost($company, $partialListing, '2026-07-01', '20.00');
        $this->em->flush();

        $report = $this->report([
            $this->saleRow(21001, '301', '460000000301', '2026-06-01'),
            $this->saleRow(21002, '302', '460000000302', '2026-07-01'),
            $this->saleRow(21003, '303', '460000000304', '2026-07-01'),
            $this->saleRow(21004, '999', '460000000999', '2026-07-01'),
            $this->saleRow(21006, '305', '460000000305', '2026-07-01'),
            $this->saleRow(21007, '306', '460000000306', '2026-07-01'),
            $this->saleRow(21008, '307', '460000000307', '2026-06-15'),
            $this->saleRow(21009, '307', '460000000307', '2026-07-15'),
            [
                'reportId' => 9301,
                'rrdId' => 21005,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Коррекция продаж',
                'quantity' => 0,
                'retailPriceWithDisc' => '0',
                'retailAmount' => '0',
                'forPay' => '5',
                'acquiringFee' => '0',
            ],
        ], $company);

        self::assertCount(7, $report['products']);
        $fallback = $this->product($report, '301');
        self::assertSame('complete', $fallback['cost_status']);
        self::assertSame(1, $fallback['fallback_cost_quantity']);
        self::assertSame(5000, $fallback['sold_cost_minor']);
        self::assertSame(2000, $fallback['result_minor']);

        $missing = $this->product($report, '302');
        self::assertSame($missingListing->getId(), $missing['listing_id']);
        self::assertSame('missing', $missing['cost_status']);
        self::assertNull($missing['result_minor']);
        self::assertSame('unavailable', $missing['result_status']);

        $conflict = $this->product($report, '303');
        self::assertNull($conflict['listing_id']);
        self::assertSame('conflict', $conflict['mapping_status']);
        self::assertNull($conflict['result_minor']);

        $unmapped = $this->product($report, '999');
        self::assertSame('unmapped', $unmapped['mapping_status']);
        self::assertSame('missing', $unmapped['cost_status']);

        self::assertSame('missing', $this->product($report, '305')['cost_status']);
        self::assertSame('missing', $this->product($report, '306')['cost_status']);

        $partial = $this->product($report, '307');
        self::assertSame('partial', $partial['cost_status']);
        self::assertSame(1, $partial['covered_cost_quantity']);
        self::assertSame(1, $partial['missing_cost_quantity']);
        self::assertSame(2000, $partial['sold_cost_minor']);
        self::assertNull($partial['result_minor']);

        self::assertSame(5, $report['product_summary']['mapped_sku_count']);
        self::assertSame(1, $report['product_summary']['unmapped_sku_count']);
        self::assertSame(1, $report['product_summary']['conflict_sku_count']);
        self::assertSame(6, $report['product_summary']['missing_cost_quantity']);
        self::assertSame(1, $report['product_summary']['partial_cost_sku_count']);
        self::assertSame('25.0', $report['product_summary']['cost_coverage_percent']);
        self::assertSame(72000, $report['product_summary']['net_sales_with_spp_minor']);
        self::assertSame(2000, $report['product_summary']['known_result_minor']);
        self::assertNull($report['product_summary']['result_minor']);
        self::assertSame(500, $report['product_summary']['unallocated_for_pay_minor']);
        self::assertSame(0, $report['product_summary']['sales_reconciliation_minor']);
    }

    public function testMapsBarcodeOnlySourceAndLoadsAllListingBarcodes(): void
    {
        $company = $this->company(610);
        $listing = $this->listing($company, 610, '401', 'M', 'BARCODE', 'Barcode only', '460000000401');
        $this->em->persist(new MarketplaceListingBarcode(
            Uuid::uuid7()->toString(),
            $listing,
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            '460000000402',
        ));
        $this->cost($company, $listing, '2026-01-01', '25.00');
        $this->em->flush();

        $row = $this->saleRow(22001, '', '460000000401', '2026-07-01');
        unset($row['nmId']);
        $report = $this->report([$row], $company);

        self::assertCount(1, $report['products']);
        self::assertSame($listing->getId(), $report['products'][0]['listing_id']);
        self::assertSame('mapped', $report['products'][0]['mapping_status']);
        self::assertSame(['460000000401', '460000000402'], $report['products'][0]['barcodes']);
        self::assertSame(2500, $report['products'][0]['sold_cost_minor']);
        self::assertSame(4500, $report['products'][0]['result_minor']);
    }

    private function company(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);

        return $company;
    }

    private function listing(
        Company $company,
        int $index,
        string $nmId,
        string $size,
        string $supplierSku,
        string $name,
        string $barcode,
    ): MarketplaceListing {
        $listing = MarketplaceListingBuilder::aListing()
            ->withIndex($index)
            ->forCompany($company)
            ->withMarketplaceSku($nmId)
            ->build()
            ->setSize($size)
            ->setSupplierSku($supplierSku)
            ->setName($name);
        $this->em->persist($listing);
        $this->em->persist(new MarketplaceListingBarcode(
            Uuid::uuid7()->toString(),
            $listing,
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            $barcode,
        ));

        return $listing;
    }

    private function cost(
        Company $company,
        MarketplaceListing $listing,
        string $effectiveFrom,
        string $amount,
        string $currency = 'RUB',
    ): MarketplaceInventoryCostPrice {
        $cost = new MarketplaceInventoryCostPrice(
            Uuid::uuid7()->toString(),
            (string) $company->getId(),
            $listing,
            new \DateTimeImmutable($effectiveFrom),
            $amount,
            $currency,
        );
        $this->em->persist($cost);

        return $cost;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function report(array $rows, Company $company, ?int $expectedRawSourceCount = null): array
    {
        $builder = self::getContainer()->get(WbRawFinancialReportBuilder::class);
        $enricher = self::getContainer()->get(WbRawFinancialReportProductEnricher::class);
        $report = $builder->build(
            [[
                'business_date' => '2026-07-31',
                'status' => 'success',
                'records_count' => count($rows),
                'raw_document_id' => '11111111-1111-4111-8111-111111111111',
                'joined_raw_document_id' => '11111111-1111-4111-8111-111111111111',
                'last_error_message' => null,
                'updated_at' => '2026-08-01 01:00:00',
                'raw_data' => $rows,
                'synced_at' => '2026-08-01 01:00:00',
            ]],
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
        );

        if (null !== $expectedRawSourceCount) {
            self::assertCount($expectedRawSourceCount, $report['_product_sources']);
        }

        return $enricher->enrich((string) $company->getId(), $report);
    }

    /**
     * @return array<string, mixed>
     */
    private function saleRow(int $rrdId, string $nmId, string $barcode, string $saleDate): array
    {
        return [
            'reportId' => 9301,
            'rrdId' => $rrdId,
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'nmId' => $nmId,
            'techSize' => 'M',
            'sku' => $barcode,
            'saleDt' => $saleDate,
            'quantity' => 1,
            'retailPriceWithDisc' => '100',
            'retailAmount' => '90',
            'forPay' => '70',
            'acquiringFee' => '2',
        ];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function product(array $report, string $nmId): array
    {
        foreach ($report['products'] as $product) {
            if ($nmId === $product['nm_id']) {
                return $product;
            }
        }

        self::fail(sprintf('Product nmId=%s not found.', $nmId));
    }
}
