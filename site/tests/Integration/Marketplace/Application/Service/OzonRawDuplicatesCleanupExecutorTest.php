<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Service;

use App\Marketplace\Application\Service\OzonRawDuplicatesCleanupExecutor;
use App\Marketplace\Application\Service\OzonRawDuplicatesCleanupPlanner;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class OzonRawDuplicatesCleanupExecutorTest extends IntegrationTestCase
{
    public function testRollbackOnFailureKeepsDataUnchanged(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1200)->build();
        $this->em->persist($company);
        $listing = MarketplaceListingBuilder::aListing()->withIndex(1200)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->build();
        $this->em->persist($listing);
        $day = new \DateTimeImmutable('2026-04-15');

        $rawA = MarketplaceRawDocumentBuilder::aDocument()->withIndex(1201)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod($day, $day)->withProcessingStatus('completed')->build();
        $rawB = MarketplaceRawDocumentBuilder::aDocument()->withIndex(1202)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod($day, $day)->withProcessingStatus('completed')->withSyncedAt($day->modify('+1 day'))->build();
        $this->em->persist($rawA); $this->em->persist($rawB);

        $saleA = MarketplaceSaleBuilder::aSale()->withIndex(1203)->forCompany($company)->forListing($listing)->withMarketplace(MarketplaceType::OZON)->withSaleDate($day)->build();
        $saleA->setRawDocumentId($rawA->getId());
        $saleB = MarketplaceSaleBuilder::aSale()->withIndex(1204)->forCompany($company)->forListing($listing)->withMarketplace(MarketplaceType::OZON)->withSaleDate($day)->build();
        $saleB->setRawDocumentId($rawB->getId());
        $this->em->persist($saleA); $this->em->persist($saleB);
        $this->em->flush();

        $this->em->getConnection()->insert('marketplace_returns', [
            'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-000000001200', 'company_id' => $company->getId(), 'listing_id' => $listing->getId(), 'marketplace' => MarketplaceType::OZON->value,
            'return_date' => $day->format('Y-m-d'), 'quantity' => 1, 'refund_amount' => '1.00', 'raw_document_id' => $rawA->getId(), 'created_at' => '2026-04-15 00:00:00', 'updated_at' => '2026-04-15 00:00:00',
        ]);
        $this->em->getConnection()->insert('marketplace_costs', [
            'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-000000001200', 'company_id' => $company->getId(), 'marketplace' => MarketplaceType::OZON->value,
            'amount' => '1.00', 'cost_date' => $day->format('Y-m-d'), 'raw_document_id' => $rawA->getId(), 'created_at' => '2026-04-15 00:00:00', 'updated_at' => '2026-04-15 00:00:00',
        ]);

        $conn = $this->em->getConnection();
        $before = $this->counts($company->getId(), $day);
        $conn->executeStatement('CREATE OR REPLACE FUNCTION test_fail_delete_returns() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'forced failure\'; END; $$ LANGUAGE plpgsql');
        $conn->executeStatement('CREATE TRIGGER trg_test_fail_delete_returns BEFORE DELETE ON marketplace_returns FOR EACH ROW EXECUTE FUNCTION test_fail_delete_returns()');

        $planner = self::getContainer()->get(OzonRawDuplicatesCleanupPlanner::class);
        $executor = self::getContainer()->get(OzonRawDuplicatesCleanupExecutor::class);
        $plan = $planner->buildPlan($company->getId(), $day, $day);

        $caught = null;

        try {
            $executor->execute($plan);
        } catch (\Throwable $e) {
            $caught = $e;
        } finally {
            $conn->executeStatement('DROP TRIGGER IF EXISTS trg_test_fail_delete_returns ON marketplace_returns');
            $conn->executeStatement('DROP FUNCTION IF EXISTS test_fail_delete_returns()');
        }

        self::assertInstanceOf(\Throwable::class, $caught);
        self::assertSame($before, $this->counts($company->getId(), $day));
    }

    private function counts(string $companyId, \DateTimeImmutable $day): array
    {
        $conn = $this->em->getConnection();

        return [
            'sales' => (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE company_id=:c AND sale_date=:d', ['c' => $companyId, 'd' => $day->format('Y-m-d')]),
            'returns' => (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE company_id=:c AND return_date=:d', ['c' => $companyId, 'd' => $day->format('Y-m-d')]),
            'costs' => (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_costs WHERE company_id=:c AND cost_date=:d', ['c' => $companyId, 'd' => $day->format('Y-m-d')]),
        ];
    }
}
