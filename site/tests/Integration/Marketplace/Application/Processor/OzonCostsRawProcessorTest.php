<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Processor;

use App\Finance\Entity\Document;
use App\Marketplace\Application\Processor\OzonCostsRawProcessor;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class OzonCostsRawProcessorTest extends IntegrationTestCase
{
    public function testCleanupRemovesOnlyStaleOpenCosts(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(301)->build();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(302)->build();
        $this->em->persist($company);
        $this->em->persist($foreignCompany);
        $day = new \DateTimeImmutable('2026-03-12');
        $outside = new \DateTimeImmutable('2026-03-20');
        $rawDocA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa12';
        $rawDocB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb12';

        $stale = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, null);
        $stale->setExternalId('stale-cost')->setCostDate($day)->setAmount('100')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocA);

        $doc = new Document(Uuid::uuid4()->toString(), $company);
        $this->em->persist($doc);
        $closed = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, null);
        $closed->setExternalId('closed-cost')->setCostDate($day)->setAmount('100')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocA)->setDocument($doc);
        $foreignStale = new MarketplaceCost(Uuid::uuid4()->toString(), $foreignCompany, MarketplaceType::OZON, null);
        $foreignStale->setExternalId('foreign-cost')->setCostDate($day)->setAmount('100')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocA);
        $outsidePeriod = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, null);
        $outsidePeriod->setExternalId('outside-cost')->setCostDate($outside)->setAmount('100')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocA);
        $wbCost = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES, null);
        $wbCost->setExternalId('wb-cost')->setCostDate($day)->setAmount('100')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocA);

        $this->em->persist($stale);
        $this->em->persist($closed);
        $this->em->persist($foreignStale);
        $this->em->persist($outsidePeriod);
        $this->em->persist($wbCost);
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocB)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build());
        $this->em->flush();

        self::getContainer()->get(OzonCostsRawProcessor::class)->processBatch($company->getId(), MarketplaceType::OZON, [[
            'operation_id' => 'cost-1', 'operation_date' => '2026-03-12 10:00:00', 'operation_type' => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Продажа', 'sale_commission' => -10, 'delivery_charge' => 0, 'return_delivery_charge' => 0,
            'amount' => 0, 'type' => 'orders', 'items' => [['sku' => 'sku-1', 'name' => 'N']], 'services' => [],
        ]], $rawDocB);

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE external_id='stale-cost'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE external_id='closed-cost' AND document_id IS NOT NULL"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE raw_document_id=:raw", ['raw' => $rawDocB]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE external_id='foreign-cost'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE external_id='outside-cost'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE external_id='wb-cost'"));
    }

    public function testReprocessFailsWhenFinanceLockCoversRawDocPeriod(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(303)->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-03-15'));
        $this->em->persist($company);

        $day = new \DateTimeImmutable('2026-03-12');
        $rawDocId = 'cccccccc-cccc-4ccc-8ccc-cccccccccc12';

        $stale = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, null);
        $stale->setExternalId('locked-stale-cost')->setCostDate($day)->setAmount('100')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId('legacy-raw-doc');
        $this->em->persist($stale);
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build());
        $this->em->flush();

        try {
            self::getContainer()->get(OzonCostsRawProcessor::class)->processBatch($company->getId(), MarketplaceType::OZON, [[
                'operation_id' => 'locked-cost-1', 'operation_date' => '2026-03-12 10:00:00', 'operation_type' => 'MarketplaceSellerCompensationOperation',
                'operation_type_name' => 'Продажа', 'sale_commission' => -10, 'delivery_charge' => 0, 'return_delivery_charge' => 0,
                'amount' => 0, 'type' => 'orders', 'items' => [['sku' => 'sku-1', 'name' => 'N']], 'services' => [],
            ]], $rawDocId);

            self::fail('Expected DomainException for finance lock, but no exception was thrown.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Период raw-документа заблокирован для переобработки затрат', $e->getMessage());
        }

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE external_id='locked-stale-cost'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE raw_document_id=:raw", ['raw' => $rawDocId]));
    }

    public function testReprocessAllowedWhenFinanceLockIsNull(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(304)->build();
        $this->em->persist($company);

        $day = new \DateTimeImmutable('2026-03-12');
        $rawDocId = 'dddddddd-dddd-4ddd-8ddd-dddddddddd12';
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build());
        $this->em->flush();

        self::getContainer()->get(OzonCostsRawProcessor::class)->processBatch($company->getId(), MarketplaceType::OZON, [[
            'operation_id' => 'open-cost-1', 'operation_date' => '2026-03-12 10:00:00', 'operation_type' => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Продажа', 'sale_commission' => -10, 'delivery_charge' => 0, 'return_delivery_charge' => 0,
            'amount' => 0, 'type' => 'orders', 'items' => [['sku' => 'sku-1', 'name' => 'N']], 'services' => [],
        ]], $rawDocId);

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE raw_document_id=:raw", ['raw' => $rawDocId]));
    }

    public function testReprocessAllowedWhenFinanceLockBeforeRawDocPeriod(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(305)->build();
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-03-01'));
        $this->em->persist($company);

        $day = new \DateTimeImmutable('2026-03-12');
        $rawDocId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeee12';
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build());
        $this->em->flush();

        self::getContainer()->get(OzonCostsRawProcessor::class)->processBatch($company->getId(), MarketplaceType::OZON, [[
            'operation_id' => 'open-cost-2', 'operation_date' => '2026-03-12 10:00:00', 'operation_type' => 'MarketplaceSellerCompensationOperation',
            'operation_type_name' => 'Продажа', 'sale_commission' => -12, 'delivery_charge' => 0, 'return_delivery_charge' => 0,
            'amount' => 0, 'type' => 'orders', 'items' => [['sku' => 'sku-2', 'name' => 'N']], 'services' => [],
        ]], $rawDocId);

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE raw_document_id=:raw", ['raw' => $rawDocId]));
    }
}
