<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\Processor\OzonCostsRawProcessor;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class OzonCostsRawProcessorTest extends IntegrationTestCase
{
    private const LEGACY_RAW_DOC_ID = '99999999-9999-4999-8999-999999999999';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->em->getConnection();
        $this->cleanupTestData();
        $this->em->clear();
    }

    public function testCleanupRemovesOnlyStaleOpenCosts(): void
    {
        $company = $this->buildCompanyWithOwnerIndex(301);
        $foreignCompany = $this->buildCompanyWithOwnerIndex(302);
        $this->persistCompanyWithUser($company);
        $this->persistCompanyWithUser($foreignCompany);
        $day = new \DateTimeImmutable('2026-03-12');
        $outside = new \DateTimeImmutable('2026-03-20');
        $rawDocA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa12';
        $rawDocB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb12';
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocA)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build());

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
        $company = $this->buildCompanyWithOwnerIndex(303);
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-03-15'));
        $this->persistCompanyWithUser($company);

        $day = new \DateTimeImmutable('2026-03-12');
        $rawDocId = 'cccccccc-cccc-4ccc-8ccc-cccccccccc12';

        $stale = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, null);
        $stale->setExternalId('locked-stale-cost')->setCostDate($day)->setAmount('100')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId(self::LEGACY_RAW_DOC_ID);
        $this->em->persist($stale);
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withId(self::LEGACY_RAW_DOC_ID)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build());
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
        $company = $this->buildCompanyWithOwnerIndex(304);
        $this->persistCompanyWithUser($company);

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
        $company = $this->buildCompanyWithOwnerIndex(305);
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-03-01'));
        $this->persistCompanyWithUser($company);

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

    public function testCostsReprocessSameRawDocIsIdempotent(): void
    {
        $company = $this->buildCompanyWithOwnerIndex(306);
        $this->persistCompanyWithUser($company);

        $rawDocId = '11111111-1111-4111-8111-111111111306';
        $day = new \DateTimeImmutable('2026-03-12');

        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build();
        $rawDoc->setRawData(['result' => ['operations' => $this->buildOrderOperations([
            ['id' => 'A', 'commission' => -100.0],
            ['id' => 'B', 'commission' => -200.0],
        ])]]);
        $this->em->persist($rawDoc);
        $this->em->flush();

        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);
        $command = new ProcessMarketplaceRawDocumentCommand($company->getId(), $rawDocId, 'costs');
        $action($command);
        $action($command);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :companyId', ['companyId' => $company->getId()]));
        self::assertSame('300.00', (string) $this->connection->fetchOne('SELECT CAST(COALESCE(SUM(amount),0) AS DECIMAL(12,2)) FROM marketplace_costs WHERE company_id = :companyId', ['companyId' => $company->getId()]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT external_id) FROM marketplace_costs WHERE company_id = :companyId', ['companyId' => $company->getId()]));
    }

    public function testCostsReprocessAddsOnlyNewRowsWhenRawDocIsExtended(): void
    {
        $company = $this->buildCompanyWithOwnerIndex(307);
        $this->persistCompanyWithUser($company);

        $rawDocId = '11111111-1111-4111-8111-111111111307';
        $day = new \DateTimeImmutable('2026-03-12');

        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build();
        $rawDoc->setRawData(['result' => ['operations' => $this->buildOrderOperations([
            ['id' => 'A', 'commission' => -100.0],
            ['id' => 'B', 'commission' => -200.0],
        ])]]);
        $this->em->persist($rawDoc);
        $this->em->flush();

        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);
        $command = new ProcessMarketplaceRawDocumentCommand($company->getId(), $rawDocId, 'costs');
        $action($command);

        $rawDoc->setRawData(['result' => ['operations' => $this->buildOrderOperations([
            ['id' => 'A', 'commission' => -100.0],
            ['id' => 'B', 'commission' => -200.0],
            ['id' => 'C', 'commission' => -300.0],
        ])]]);
        $this->em->flush();

        $action($command);

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :companyId', ['companyId' => $company->getId()]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :companyId AND external_id LIKE 'A_%'", ['companyId' => $company->getId()]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :companyId AND external_id LIKE 'B_%'", ['companyId' => $company->getId()]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :companyId AND external_id LIKE 'C_%'", ['companyId' => $company->getId()]));
    }

    public function testCostsReprocessReplacesOpenRowsWithUpdatedAmountsAndKeepsClosedRows(): void
    {
        $company = $this->buildCompanyWithOwnerIndex(308);
        $this->persistCompanyWithUser($company);

        $rawDocId = '11111111-1111-4111-8111-111111111308';
        $day = new \DateTimeImmutable('2026-03-12');

        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build();
        $rawDoc->setRawData(['result' => ['operations' => $this->buildOrderOperations([
            ['id' => 'A', 'commission' => -100.0],
            ['id' => 'B', 'commission' => -200.0],
        ])]]);
        $this->em->persist($rawDoc);

        $closedDocument = new Document(Uuid::uuid4()->toString(), $company);
        $this->em->persist($closedDocument);
        $closedCost = new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, null);
        $closedCost->setExternalId('closed-legacy-row')->setRawDocumentId($rawDocId)->setCostDate($day)->setAmount('999')->setOperationType(MarketplaceCostOperationType::CHARGE)->setDocument($closedDocument);
        $this->em->persist($closedCost);
        $this->em->flush();

        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);
        $command = new ProcessMarketplaceRawDocumentCommand($company->getId(), $rawDocId, 'costs');
        $action($command);

        $rawDoc->setRawData(['result' => ['operations' => $this->buildOrderOperations([
            ['id' => 'A', 'commission' => -100.0],
            ['id' => 'B', 'commission' => -250.0],
        ])]]);
        $this->em->flush();

        $action($command);

        self::assertSame('350.00', (string) $this->connection->fetchOne("SELECT CAST(COALESCE(SUM(amount),0) AS DECIMAL(12,2)) FROM marketplace_costs WHERE company_id = :companyId AND document_id IS NULL", ['companyId' => $company->getId()]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :companyId AND document_id IS NULL AND external_id LIKE 'B_%' AND amount = 250", ['companyId' => $company->getId()]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_costs WHERE company_id = :companyId AND document_id IS NOT NULL AND external_id = 'closed-legacy-row'", ['companyId' => $company->getId()]));
    }



    private function buildCompanyWithOwnerIndex(int $index): Company
    {
        $owner = UserBuilder::aUser()
            ->withIndex($index)
            ->build();

        return CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($owner)
            ->build();
    }

    private function cleanupTestData(): void
    {
        $companyIds = [];
        $userIds = [];
        for ($i = 301; $i <= 308; ++$i) {
            $company = $this->buildCompanyWithOwnerIndex($i);
            $companyIds[] = $company->getId();
            $userIds[] = $company->getUser()?->getId();
        }

        $rawDocumentIds = [
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa12',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb12',
            'cccccccc-cccc-4ccc-8ccc-cccccccccc12',
            'dddddddd-dddd-4ddd-8ddd-dddddddddd12',
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeee12',
            '11111111-1111-4111-8111-111111111306',
            '11111111-1111-4111-8111-111111111307',
            '11111111-1111-4111-8111-111111111308',
        ];
        $costRawDocumentIds = [...$rawDocumentIds, self::LEGACY_RAW_DOC_ID];

        $platform = $this->connection->getDatabasePlatform();
        $companyTable = $platform->quoteIdentifier($this->em->getClassMetadata(Company::class)->getTableName());
        $userTable = $platform->quoteIdentifier($this->em->getClassMetadata(User::class)->getTableName());
        $documentTable = $platform->quoteIdentifier($this->em->getClassMetadata(Document::class)->getTableName());

        $this->connection->executeStatement('DELETE FROM marketplace_costs WHERE company_id IN (:companyIds) OR raw_document_id IN (:costRawDocumentIds)', [
            'companyIds' => $companyIds,
            'costRawDocumentIds' => $costRawDocumentIds,
        ], [
            'companyIds' => Connection::PARAM_STR_ARRAY,
            'costRawDocumentIds' => Connection::PARAM_STR_ARRAY,
        ]);

        $this->connection->executeStatement('DELETE FROM marketplace_raw_documents WHERE company_id IN (:companyIds) OR id IN (:rawDocumentIds)', [
            'companyIds' => $companyIds,
            'rawDocumentIds' => $rawDocumentIds,
        ], [
            'companyIds' => Connection::PARAM_STR_ARRAY,
            'rawDocumentIds' => Connection::PARAM_STR_ARRAY,
        ]);

        $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE company_id IN (:companyIds)', $documentTable), [
            'companyIds' => $companyIds,
        ], [
            'companyIds' => Connection::PARAM_STR_ARRAY,
        ]);

        $this->connection->executeStatement('DELETE FROM marketplace_listings WHERE company_id IN (:companyIds)', [
            'companyIds' => $companyIds,
        ], [
            'companyIds' => Connection::PARAM_STR_ARRAY,
        ]);

        $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE id IN (:companyIds)', $companyTable), [
            'companyIds' => $companyIds,
        ], [
            'companyIds' => Connection::PARAM_STR_ARRAY,
        ]);

        $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE id IN (:userIds)', $userTable), [
            'userIds' => array_values(array_filter($userIds)),
        ], [
            'userIds' => Connection::PARAM_STR_ARRAY,
        ]);
    }

    private function persistCompanyWithUser(Company $company): void
    {
        $this->em->persist($company->getUser());
        $this->em->persist($company);
    }

    /**
     * @param list<array{id: string, commission: float}> $rows
     * @return list<array<string, mixed>>
     */
    private function buildOrderOperations(array $rows): array
    {
        $operations = [];
        foreach ($rows as $row) {
            $operations[] = [
                'operation_id' => $row['id'],
                'operation_date' => '2026-03-12 10:00:00',
                'operation_type' => 'MarketplaceSellerCompensationOperation',
                'operation_type_name' => 'Продажа',
                'sale_commission' => $row['commission'],
                'delivery_charge' => 0,
                'return_delivery_charge' => 0,
                'amount' => 0,
                'type' => 'orders',
                'items' => [['sku' => 'sku-' . $row['id'], 'name' => 'SKU ' . $row['id']]],
                'services' => [],
            ];
        }

        return $operations;
    }
}
